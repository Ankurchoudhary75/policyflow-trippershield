<?php

namespace App\Services\Callback;

use App\Enums\PolicyState;
use App\Models\PolicyRequest;
use App\Models\ProviderEvent;
use App\Services\Workflow\StateMachine;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class CallbackProcessor
{
    public function __construct(
        private readonly StateMachine $stateMachine
    ) {}

    /**
     * Process an asynchronous insurer webhook callback idempotently.
     */
    public function process(array $data): array
    {
        $eventId = $data['provider_event_id'];

        // Fast-path duplicate check
        if (ProviderEvent::where('provider_event_id', $eventId)->exists()) {
            return [
                'status_code' => 200,
                'result' => 'duplicate_ignored',
                'provider_event_id' => $eventId,
                'message' => 'Duplicate provider event previously processed.',
            ];
        }

        // Resolve associated policy request
        $policy = $this->resolvePolicy($data);

        if (!$policy) {
            ProviderEvent::create([
                'provider_event_id' => $eventId,
                'policy_request_id' => null,
                'event_type' => $data['event_type'],
                'payload' => $data,
                'result' => 'NOT_FOUND',
                'processed_at' => now(),
            ]);

            return [
                'status_code' => 404,
                'result' => 'not_found',
                'provider_event_id' => $eventId,
                'message' => 'Associated policy request could not be located.',
            ];
        }

        try {
            return DB::transaction(function () use ($data, $policy, $eventId) {
                // Record event under UNIQUE constraint
                $event = ProviderEvent::create([
                    'provider_event_id' => $eventId,
                    'policy_request_id' => $policy->id,
                    'event_type' => $data['event_type'],
                    'payload' => $data,
                    'result' => 'PENDING',
                    'processed_at' => now(),
                ]);

                /** @var PolicyRequest $locked */
                $locked = PolicyRequest::where('id', $policy->id)->lockForUpdate()->first();

                // Guard: If request is already ISSUED, reject any downgrade or modification
                if ($locked->state === PolicyState::ISSUED->value) {
                    $event->update(['result' => 'REJECTED_INVALID_TRANSITION']);
                    return [
                        'status_code' => 200,
                        'result' => 'no_op_state_preserved',
                        'provider_event_id' => $eventId,
                        'current_state' => $locked->state,
                        'message' => 'Policy already confirmed in ISSUED state; subsequent events cannot alter terminal state.',
                    ];
                }

                if ($data['event_type'] === 'CONFIRMED') {
                    $policyNumber = $data['policy_number'] ?? ('POL-' . date('Y') . '-' . strtoupper(substr(md5($eventId), 0, 8)));
                    $providerRef = $data['provider_reference'] ?? $locked->provider_reference;

                    $locked->policy_number = $policyNumber;
                    $locked->provider_reference = $providerRef;
                    $locked->last_error = null;
                    $locked->save();

                    $this->stateMachine->transition(
                        $locked,
                        PolicyState::ISSUED,
                        "Confirmed via asynchronous provider callback (Event: {$eventId})",
                        'PROVIDER'
                    );

                    $event->update(['result' => 'APPLIED']);

                    return [
                        'status_code' => 200,
                        'result' => 'applied',
                        'provider_event_id' => $eventId,
                        'state' => 'ISSUED',
                        'policy_number' => $locked->policy_number,
                    ];
                }

                if ($data['event_type'] === 'REJECTED') {
                    $locked->last_error = $data['error_reason'] ?? 'Provider rejected policy issuance.';
                    $locked->save();

                    $this->stateMachine->transition(
                        $locked,
                        PolicyState::FAILED,
                        "Provider callback rejection: {$locked->last_error} (Event: {$eventId})",
                        'PROVIDER'
                    );

                    $event->update(['result' => 'APPLIED']);

                    return [
                        'status_code' => 200,
                        'result' => 'applied',
                        'provider_event_id' => $eventId,
                        'state' => 'FAILED',
                    ];
                }

                $event->update(['result' => 'UNKNOWN_EVENT_TYPE']);
                return [
                    'status_code' => 400,
                    'result' => 'unknown_event_type',
                    'provider_event_id' => $eventId,
                ];
            });
        } catch (QueryException $e) {
            // Unique violation on provider_event_id race condition
            return [
                'status_code' => 200,
                'result' => 'duplicate_ignored',
                'provider_event_id' => $eventId,
                'message' => 'Concurrent duplicate provider event ignored.',
            ];
        }
    }

    private function resolvePolicy(array $data): ?PolicyRequest
    {
        if (!empty($data['policy_request_id'])) {
            $p = PolicyRequest::find($data['policy_request_id']);
            if ($p) return $p;
        }

        if (!empty($data['idempotency_key'])) {
            $p = PolicyRequest::where('provider_idempotency_key', $data['idempotency_key'])->first();
            if ($p) return $p;
        }

        if (!empty($data['provider_reference'])) {
            $p = PolicyRequest::where('provider_reference', $data['provider_reference'])->first();
            if ($p) return $p;
        }

        return null;
    }
}
