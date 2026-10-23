<?php

namespace App\Services\Issuance;

use App\Contracts\InsurerClientInterface;
use App\Enums\PolicyState;
use App\Models\IssuanceAttempt;
use App\Models\PolicyRequest;
use App\Services\Workflow\StateMachine;
use Illuminate\Support\Facades\DB;

class IssuanceService
{
    public const MAX_ATTEMPTS = 3;
    public const LEASE_SECONDS = 30;

    public function __construct(
        private readonly InsurerClientInterface $insurerClient,
        private readonly StateMachine $stateMachine
    ) {}

    /**
     * Submit policy request for issuance safely using Claim-Call-Record with in-flight lease.
     *
     * Returns array [PolicyRequest $policy, string $resultAction]
     */
    public function submit(PolicyRequest $policy): array
    {
        // -------------------------------------------------------------
        // Phase 1: Claim Phase (Short DB lock, avoid holding over HTTP)
        // -------------------------------------------------------------
        $claim = DB::transaction(function () use ($policy) {
            /** @var PolicyRequest $locked */
            $locked = PolicyRequest::where('id', $policy->id)->lockForUpdate()->firstOrFail();

            // Guard: Already in terminal states
            if ($locked->state === PolicyState::ISSUED->value) {
                return ['action' => 'ALREADY_ISSUED', 'policy' => $locked];
            }

            if ($locked->state === PolicyState::FAILED->value) {
                return ['action' => 'TERMINAL_FAILED', 'policy' => $locked];
            }

            // Guard: Uncertain or pending outcome must reconcile first
            if (in_array($locked->state, [PolicyState::NEEDS_RECONCILIATION->value, PolicyState::PENDING_CONFIRMATION->value])) {
                return ['action' => 'MUST_RECONCILE', 'policy' => $locked];
            }

            // Guard: Live in-flight lease active (another process is actively submitting)
            if ($locked->submit_lease_until && $locked->submit_lease_until->isFuture()) {
                return ['action' => 'IN_FLIGHT_LEASE_ACTIVE', 'policy' => $locked];
            }

            // Guard: Check max retry attempts
            if ($locked->attempt_count >= self::MAX_ATTEMPTS) {
                $this->stateMachine->transition(
                    $locked,
                    PolicyState::FAILED,
                    'Maximum attempt limit reached without success',
                    'SYSTEM'
                );
                return ['action' => 'MAX_ATTEMPTS_EXHAUSTED', 'policy' => $locked];
            }

            // Acquire lease and advance attempt counter
            $locked->attempt_count += 1;
            $attemptNo = $locked->attempt_count;
            $locked->submit_lease_until = now()->addSeconds(self::LEASE_SECONDS);

            // Record pending attempt
            IssuanceAttempt::create([
                'policy_request_id' => $locked->id,
                'attempt_no' => $attemptNo,
                'idempotency_key' => $locked->provider_idempotency_key,
                'outcome' => 'PENDING',
                'started_at' => now(),
            ]);

            // Transition state to SUBMITTED
            $this->stateMachine->transition(
                $locked,
                PolicyState::SUBMITTED,
                "Issuance submission started (Attempt #{$attemptNo})",
                'API'
            );

            return [
                'action' => 'PROCEED',
                'policy' => $locked,
                'attempt_no' => $attemptNo,
            ];
        });

        if ($claim['action'] === 'MUST_RECONCILE') {
            return $this->reconcile($claim['policy']);
        }

        if ($claim['action'] !== 'PROCEED') {
            return [$claim['policy']->fresh(['stateHistory', 'attempts']), $claim['action']];
        }

        $attemptNo = $claim['attempt_no'];

        // -------------------------------------------------------------
        // Phase 2: Call Provider (NO DB TRANSACTION HELD OVER NETWORK)
        // -------------------------------------------------------------
        $result = $this->insurerClient->submit($policy, $attemptNo);

        // -------------------------------------------------------------
        // Phase 3: Record Phase (Short DB lock to finalize result)
        // -------------------------------------------------------------
        return DB::transaction(function () use ($policy, $attemptNo, $result) {
            /** @var PolicyRequest $locked */
            $locked = PolicyRequest::where('id', $policy->id)->lockForUpdate()->firstOrFail();

            /** @var IssuanceAttempt $attempt */
            $attempt = IssuanceAttempt::where('policy_request_id', $locked->id)
                ->where('attempt_no', $attemptNo)
                ->first();

            // Clear in-flight lease
            $locked->submit_lease_until = null;

            if ($result->isSuccess()) {
                $attempt?->update([
                    'outcome' => 'SUCCESS',
                    'http_status' => $result->httpStatus,
                    'finished_at' => now(),
                    'response_summary' => [
                        'policy_number' => $result->policyNumber,
                        'provider_reference' => $result->providerReference,
                    ],
                ]);

                $locked->policy_number = $result->policyNumber;
                $locked->provider_reference = $result->providerReference;
                $locked->last_provider_response = $result->rawResponse;
                $locked->last_error = null;
                $locked->save();

                $this->stateMachine->transition(
                    $locked,
                    PolicyState::ISSUED,
                    "Provider confirmed policy issuance (Policy: {$result->policyNumber})",
                    'PROVIDER'
                );

                return [$locked->fresh(['stateHistory', 'attempts']), 'ISSUED'];
            }

            if ($result->isPending()) {
                $attempt?->update([
                    'outcome' => 'PENDING',
                    'http_status' => $result->httpStatus,
                    'finished_at' => now(),
                    'response_summary' => $result->rawResponse,
                ]);

                $locked->provider_reference = $result->providerReference;
                $locked->last_provider_response = $result->rawResponse;
                $locked->save();

                $this->stateMachine->transition(
                    $locked,
                    PolicyState::PENDING_CONFIRMATION,
                    'Provider accepted request; issuance pending webhook confirmation',
                    'PROVIDER'
                );

                return [$locked->fresh(['stateHistory', 'attempts']), 'PENDING_CONFIRMATION'];
            }

            if ($result->isTimeout()) {
                $attempt?->update([
                    'outcome' => 'TIMEOUT',
                    'http_status' => $result->httpStatus,
                    'finished_at' => now(),
                    'error_message' => $result->errorMessage,
                ]);

                $locked->last_error = $result->errorMessage;
                $locked->save();

                $this->stateMachine->transition(
                    $locked,
                    PolicyState::NEEDS_RECONCILIATION,
                    'Provider network call timed out; reconciliation required before any retry',
                    'SYSTEM'
                );

                return [$locked->fresh(['stateHistory', 'attempts']), 'NEEDS_RECONCILIATION'];
            }

            if ($result->isRetriable()) {
                $attempt?->update([
                    'outcome' => 'RETRIABLE_FAILURE',
                    'http_status' => $result->httpStatus,
                    'finished_at' => now(),
                    'error_message' => $result->errorMessage,
                ]);

                $locked->last_error = $result->errorMessage;
                $locked->save();

                if ($locked->attempt_count >= self::MAX_ATTEMPTS) {
                    $this->stateMachine->transition(
                        $locked,
                        PolicyState::FAILED,
                        "Retriable error occurred and maximum attempts ({$locked->attempt_count}) exhausted",
                        'SYSTEM'
                    );
                    return [$locked->fresh(['stateHistory', 'attempts']), 'MAX_ATTEMPTS_EXHAUSTED'];
                }

                // Return to VALIDATED so request is safely eligible for retry
                $this->stateMachine->transition(
                    $locked,
                    PolicyState::VALIDATED,
                    "Temporary provider error: {$result->errorMessage}. Rolled back to VALIDATED for retry.",
                    'SYSTEM'
                );

                return [$locked->fresh(['stateHistory', 'attempts']), 'RETRIABLE_FAILURE'];
            }

            // Fatal failure
            $attempt?->update([
                'outcome' => 'FATAL_FAILURE',
                'http_status' => $result->httpStatus,
                'finished_at' => now(),
                'error_message' => $result->errorMessage,
            ]);

            $locked->last_error = $result->errorMessage;
            $locked->save();

            $this->stateMachine->transition(
                $locked,
                PolicyState::FAILED,
                "Fatal rejection by insurer: {$result->errorMessage}",
                'PROVIDER'
            );

            return [$locked->fresh(['stateHistory', 'attempts']), 'FAILED'];
        });
    }

    /**
     * Reconcile uncertain or pending policy status against mock provider lookup.
     */
    public function reconcile(PolicyRequest $policy): array
    {
        $lookup = $this->insurerClient->lookup($policy->provider_idempotency_key);

        return DB::transaction(function () use ($policy, $lookup) {
            /** @var PolicyRequest $locked */
            $locked = PolicyRequest::where('id', $policy->id)->lockForUpdate()->firstOrFail();
            $locked->submit_lease_until = null;

            if ($locked->state === PolicyState::ISSUED->value) {
                return [$locked->fresh(['stateHistory', 'attempts']), 'ALREADY_ISSUED'];
            }

            if ($lookup->isSuccess()) {
                $locked->policy_number = $lookup->policyNumber;
                $locked->provider_reference = $lookup->providerReference;
                $locked->last_error = null;
                $locked->save();

                $this->stateMachine->transition(
                    $locked,
                    PolicyState::ISSUED,
                    "Reconciliation confirmed policy already issued by provider (Policy: {$lookup->policyNumber})",
                    'RECONCILER'
                );

                return [$locked->fresh(['stateHistory', 'attempts']), 'RECONCILED_ISSUED'];
            }

            if ($lookup->isPending()) {
                if ($locked->state !== PolicyState::PENDING_CONFIRMATION->value) {
                    $this->stateMachine->transition(
                        $locked,
                        PolicyState::PENDING_CONFIRMATION,
                        'Reconciliation confirmed provider is processing asynchronously',
                        'RECONCILER'
                    );
                }
                return [$locked->fresh(['stateHistory', 'attempts']), 'RECONCILED_PENDING'];
            }

            // Not found on insurer (404) -> Insurer never received or processed it
            if ($locked->state === PolicyState::NEEDS_RECONCILIATION->value) {
                $this->stateMachine->transition(
                    $locked,
                    PolicyState::VALIDATED,
                    'Reconciliation confirmed no policy exists on provider; safely unlocked for retry',
                    'RECONCILER'
                );
            }

            return [$locked->fresh(['stateHistory', 'attempts']), 'RECONCILED_NOT_FOUND'];
        });
    }
}
