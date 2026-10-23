<?php

namespace App\Services\Workflow;

use App\Enums\PolicyState;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\PolicyRequest;
use App\Models\PolicyStateHistory;
use Illuminate\Support\Facades\DB;

class StateMachine
{
    /**
     * Complete transition rule matrix.
     * Maps current state to all permissible next states.
     */
    private const ALLOWED_TRANSITIONS = [
        PolicyState::RECEIVED->value => [
            PolicyState::VALIDATED->value,
            PolicyState::FAILED->value,
        ],
        PolicyState::VALIDATED->value => [
            PolicyState::SUBMITTED->value,
            PolicyState::FAILED->value,
        ],
        PolicyState::SUBMITTED->value => [
            PolicyState::PENDING_CONFIRMATION->value,
            PolicyState::ISSUED->value,
            PolicyState::VALIDATED->value, // Safe retriable rollback
            PolicyState::NEEDS_RECONCILIATION->value,
            PolicyState::FAILED->value,
        ],
        PolicyState::PENDING_CONFIRMATION->value => [
            PolicyState::ISSUED->value,
            PolicyState::FAILED->value,
            PolicyState::NEEDS_RECONCILIATION->value,
        ],
        PolicyState::NEEDS_RECONCILIATION->value => [
            PolicyState::ISSUED->value,
            PolicyState::PENDING_CONFIRMATION->value,
            PolicyState::FAILED->value,
            PolicyState::SUBMITTED->value, // Re-attempt after verified not issued
        ],
        PolicyState::ISSUED->value => [], // Strictly terminal
        PolicyState::FAILED->value => [], // Strictly terminal
    ];

    /**
     * Verify whether a transition from $fromState to $toState is legally permitted.
     */
    public function canTransition(string $fromState, string $toState): bool
    {
        if ($fromState === $toState) {
            return false;
        }

        $allowed = self::ALLOWED_TRANSITIONS[$fromState] ?? [];
        return in_array($toState, $allowed, true);
    }

    /**
     * Execute an atomic, audited state transition for the given PolicyRequest.
     *
     * @throws InvalidStateTransitionException
     */
    public function transition(
        PolicyRequest $policy,
        PolicyState|string $targetState,
        string $reason,
        string $actor = 'SYSTEM',
        array $metadata = []
    ): PolicyRequest {
        $toState = $targetState instanceof PolicyState ? $targetState->value : $targetState;
        $fromState = $policy->state;

        if (!$this->canTransition($fromState, $toState)) {
            throw new InvalidStateTransitionException($fromState, $toState, $policy->id);
        }

        return DB::transaction(function () use ($policy, $fromState, $toState, $reason, $actor, $metadata) {
            // Update request row state
            $policy->state = $toState;
            $policy->save();

            // Append unalterable audit log
            PolicyStateHistory::create([
                'policy_request_id' => $policy->id,
                'from_state' => $fromState,
                'to_state' => $toState,
                'reason' => $reason,
                'actor' => $actor,
                'metadata' => $metadata ?: null,
                'created_at' => now(),
            ]);

            return $policy;
        });
    }

    /**
     * Record the initial intake states for an incoming policy request.
     */
    public function recordIntakeHistory(PolicyRequest $policy, string $actor = 'API'): void
    {
        DB::transaction(function () use ($policy, $actor) {
            PolicyStateHistory::create([
                'policy_request_id' => $policy->id,
                'from_state' => null,
                'to_state' => PolicyState::RECEIVED->value,
                'reason' => 'Policy intake received and verified',
                'actor' => $actor,
                'metadata' => ['client_request_id' => $policy->client_request_id],
                'created_at' => now(),
            ]);

            PolicyStateHistory::create([
                'policy_request_id' => $policy->id,
                'from_state' => PolicyState::RECEIVED->value,
                'to_state' => PolicyState::VALIDATED->value,
                'reason' => 'Inputs validated and premium snapshot persisted',
                'actor' => $actor,
                'metadata' => [
                    'premium_amount' => $policy->premium_amount,
                    'plan_code' => $policy->plan_code,
                ],
                'created_at' => now(),
            ]);
        });
    }
}
