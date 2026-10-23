<?php

namespace App\Services\Mock;

use App\Models\MockInsurerPolicy;
use Illuminate\Support\Str;

class MockInsurerService
{
    /**
     * Handle simulated policy submission.
     */
    public function handleSubmission(string $idempotencyKey, string $scenario, array $payload = []): array
    {
        $existing = MockInsurerPolicy::where('idempotency_key', $idempotencyKey)->first();

        if ($existing) {
            $existing->increment('call_count');
            return [
                'status_code' => $existing->status === 'ISSUED' ? 200 : 202,
                'body' => [
                    'status' => $existing->status,
                    'policy_number' => $existing->policy_number,
                    'provider_reference' => $existing->provider_reference,
                    'call_count' => $existing->call_count,
                    'idempotent_replay' => true,
                ],
            ];
        }

        return match ($scenario) {
            'TEMPORARY_FAILURE' => [
                'status_code' => 503,
                'body' => [
                    'status' => 'ERROR',
                    'retriable' => true,
                    'error_code' => 'PROVIDER_SERVICE_UNAVAILABLE',
                    'message' => 'Insurer external gateway congested. Please retry with exponential backoff.',
                ],
            ],

            'TIMEOUT_AFTER_ISSUE' => $this->handleTimeoutAfterIssue($idempotencyKey, $payload),

            'DELAYED_CALLBACK', 'DUPLICATE_CALLBACK' => $this->handlePendingAsync($idempotencyKey, $scenario, $payload),

            default => $this->handleSuccess($idempotencyKey, $payload),
        };
    }

    /**
     * Status lookup by idempotency key for safe reconciliation.
     */
    public function lookup(string $idempotencyKey): ?array
    {
        $policy = MockInsurerPolicy::where('idempotency_key', $idempotencyKey)->first();

        if (!$policy) {
            return null;
        }

        return [
            'found' => true,
            'idempotency_key' => $policy->idempotency_key,
            'status' => $policy->status,
            'policy_number' => $policy->policy_number,
            'provider_reference' => $policy->provider_reference,
            'call_count' => $policy->call_count,
            'created_at' => $policy->created_at?->toIso8601String(),
        ];
    }

    private function handleSuccess(string $idempotencyKey, array $payload): array
    {
        $policyNumber = 'POL-' . date('Y') . '-' . strtoupper(Str::random(8));
        $providerRef = 'REF-' . date('Ymd') . '-' . strtoupper(Str::random(6));

        $record = MockInsurerPolicy::create([
            'idempotency_key' => $idempotencyKey,
            'scenario' => 'SUCCESS',
            'policy_number' => $policyNumber,
            'provider_reference' => $providerRef,
            'status' => 'ISSUED',
            'call_count' => 1,
            'last_payload' => $payload,
        ]);

        return [
            'status_code' => 200,
            'body' => [
                'status' => 'ISSUED',
                'policy_number' => $record->policy_number,
                'provider_reference' => $record->provider_reference,
                'message' => 'Policy issued successfully.',
            ],
        ];
    }

    private function handleTimeoutAfterIssue(string $idempotencyKey, array $payload): array
    {
        // Insurer creates policy in its own database, but client connection will time out
        $policyNumber = 'POL-' . date('Y') . '-' . strtoupper(Str::random(8));
        $providerRef = 'REF-' . date('Ymd') . '-' . strtoupper(Str::random(6));

        MockInsurerPolicy::create([
            'idempotency_key' => $idempotencyKey,
            'scenario' => 'TIMEOUT_AFTER_ISSUE',
            'policy_number' => $policyNumber,
            'provider_reference' => $providerRef,
            'status' => 'ISSUED',
            'call_count' => 1,
            'last_payload' => $payload,
        ]);

        return [
            'status_code' => 504,
            'body' => [
                'status' => 'TIMEOUT',
                'error_code' => 'GATEWAY_TIMEOUT',
                'message' => 'Simulated upstream connection timeout after policy creation.',
            ],
        ];
    }

    private function handlePendingAsync(string $idempotencyKey, string $scenario, array $payload): array
    {
        $policyNumber = 'POL-' . date('Y') . '-' . strtoupper(Str::random(8));
        $providerRef = 'REF-' . date('Ymd') . '-' . strtoupper(Str::random(6));

        $record = MockInsurerPolicy::create([
            'idempotency_key' => $idempotencyKey,
            'scenario' => $scenario,
            'policy_number' => $policyNumber,
            'provider_reference' => $providerRef,
            'status' => 'PENDING',
            'call_count' => 1,
            'last_payload' => $payload,
        ]);

        return [
            'status_code' => 202,
            'body' => [
                'status' => 'PENDING',
                'provider_reference' => $record->provider_reference,
                'policy_number' => $record->policy_number, // Known internally
                'message' => 'Underwriting in progress. Final policy confirmation will be posted via webhook callback.',
            ],
        ];
    }
}
