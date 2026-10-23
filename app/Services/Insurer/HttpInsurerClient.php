<?php

namespace App\Services\Insurer;

use App\Contracts\InsurerClientInterface;
use App\Models\PolicyRequest;
use App\Services\Mock\MockInsurerService;

class HttpInsurerClient implements InsurerClientInterface
{
    public function __construct(
        private readonly MockInsurerService $mockService
    ) {}

    public function submit(PolicyRequest $policy, int $attemptNo): InsurerResult
    {
        $scenario = $policy->mock_scenario ?? 'SUCCESS';

        // Redacted payload for provider call: no unneeded PII
        $payload = [
            'idempotency_key' => $policy->provider_idempotency_key,
            'plan_code' => $policy->plan_code,
            'sum_insured' => (int)$policy->sum_insured,
            'destination' => $policy->destination,
            'trip_start' => $policy->trip_start?->format('Y-m-d'),
            'trip_end' => $policy->trip_end?->format('Y-m-d'),
            'attempt_no' => $attemptNo,
        ];

        $response = $this->mockService->handleSubmission(
            idempotencyKey: $policy->provider_idempotency_key,
            scenario: $scenario,
            payload: $payload
        );

        $status = $response['status_code'];
        $body = $response['body'];

        return match ($status) {
            200 => InsurerResult::success(
                policyNumber: $body['policy_number'],
                providerReference: $body['provider_reference'],
                httpStatus: 200,
                raw: $body
            ),
            202 => InsurerResult::pending(
                providerReference: $body['provider_reference'],
                httpStatus: 202,
                raw: $body
            ),
            504 => InsurerResult::timeout(
                errorMessage: $body['message'] ?? 'Gateway connection timed out',
                raw: $body
            ),
            503 => InsurerResult::retriableFailure(
                errorMessage: $body['message'] ?? 'Provider temporarily unavailable',
                httpStatus: 503,
                raw: $body
            ),
            default => InsurerResult::fatalFailure(
                errorMessage: $body['message'] ?? 'Provider rejected submission',
                httpStatus: $status,
                raw: $body
            ),
        };
    }

    public function lookup(string $idempotencyKey): InsurerResult
    {
        $record = $this->mockService->lookup($idempotencyKey);

        if (!$record) {
            return InsurerResult::fatalFailure(
                errorMessage: 'Policy record not found on insurer',
                httpStatus: 404,
                raw: ['found' => false]
            );
        }

        if ($record['status'] === 'ISSUED') {
            return InsurerResult::success(
                policyNumber: $record['policy_number'],
                providerReference: $record['provider_reference'],
                httpStatus: 200,
                raw: $record
            );
        }

        if ($record['status'] === 'PENDING') {
            return InsurerResult::pending(
                providerReference: $record['provider_reference'],
                httpStatus: 202,
                raw: $record
            );
        }

        return InsurerResult::fatalFailure(
            errorMessage: "Insurer returned terminal non-success status: {$record['status']}",
            httpStatus: 400,
            raw: $record
        );
    }
}
