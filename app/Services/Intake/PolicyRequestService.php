<?php

namespace App\Services\Intake;

use App\Enums\PolicyState;
use App\Models\PolicyRequest;
use App\Services\Pricing\PremiumCalculator;
use App\Services\Workflow\StateMachine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PolicyRequestService
{
    public function __construct(
        private readonly PremiumCalculator $calculator,
        private readonly StateMachine $stateMachine
    ) {}

    /**
     * Intake a policy request idempotently.
     *
     * Returns array [PolicyRequest $policy, bool $isReplay, bool $payloadMatches]
     */
    public function createOrRetrieve(array $data): array
    {
        $agentId = $data['agent_id'];
        $clientRequestId = $data['client_request_id'];

        // Fast-path lookup
        $existing = PolicyRequest::with('stateHistory')
            ->where('agent_id', $agentId)
            ->where('client_request_id', $clientRequestId)
            ->first();

        if ($existing) {
            $matches = $this->payloadMatches($existing, $data);
            return [$existing, true, $matches];
        }

        // Deterministic pricing calculation
        $pricing = $this->calculator->calculate(
            planCode: $data['plan_code'],
            dateOfBirth: $data['date_of_birth'],
            destination: $data['destination'],
            tripStart: $data['trip_start'],
            tripEnd: $data['trip_end'],
            sumInsured: $data['sum_insured']
        );

        $id = (string) Str::ulid();

        try {
            return DB::transaction(function () use ($data, $pricing, $id) {
                $policy = new PolicyRequest();
                $policy->id = $id;
                $policy->agent_id = $data['agent_id'];
                $policy->client_request_id = $data['client_request_id'];
                $policy->customer_name = $data['customer_name'];
                $policy->date_of_birth = $data['date_of_birth'];
                $policy->destination = $data['destination'];
                $policy->trip_start = $data['trip_start'];
                $policy->trip_end = $data['trip_end'];
                $policy->plan_code = $data['plan_code'];
                $policy->sum_insured = (string)$data['sum_insured'];
                $policy->mock_scenario = $data['mock_scenario'] ?? null;
                $policy->provider_idempotency_key = $id;
                $policy->state = PolicyState::VALIDATED->value;
                $policy->premium_amount = $pricing->amount;
                $policy->premium_currency = $pricing->currency;
                $policy->premium_breakdown = $pricing->breakdown;
                $policy->attempt_count = 0;
                $policy->save();

                $this->stateMachine->recordIntakeHistory($policy, 'API');

                return [$policy->load('stateHistory'), false, true];
            });
        } catch (QueryException $e) {
            // Concurrency arbitration: If a concurrent insert occurred simultaneously,
            // the database unique index 'uq_policy_requests_agent_client_req' blocks the duplicate.
            $duplicate = PolicyRequest::with('stateHistory')
                ->where('agent_id', $agentId)
                ->where('client_request_id', $clientRequestId)
                ->first();

            if ($duplicate) {
                $matches = $this->payloadMatches($duplicate, $data);
                return [$duplicate, true, $matches];
            }

            throw $e;
        }
    }

    public function findById(string $id): PolicyRequest
    {
        return PolicyRequest::with(['stateHistory', 'attempts', 'events'])->findOrFail($id);
    }

    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = PolicyRequest::with('stateHistory')->orderBy('created_at', 'desc');

        if (!empty($filters['status'])) {
            $query->where('state', strtoupper($filters['status']));
        }

        if (!empty($filters['agent_id'])) {
            $query->where('agent_id', $filters['agent_id']);
        }

        $pageSize = min(100, max(1, $perPage));
        return $query->paginate($pageSize);
    }

    private function payloadMatches(PolicyRequest $existing, array $incoming): bool
    {
        $fields = [
            'customer_name',
            'destination',
            'plan_code',
        ];

        foreach ($fields as $field) {
            if (isset($incoming[$field]) && $existing->{$field} !== $incoming[$field]) {
                return false;
            }
        }

        if (isset($incoming['sum_insured']) && (int)$existing->sum_insured !== (int)$incoming['sum_insured']) {
            return false;
        }

        if (isset($incoming['date_of_birth']) && $existing->date_of_birth?->format('Y-m-d') !== $incoming['date_of_birth']) {
            return false;
        }

        if (isset($incoming['trip_start']) && $existing->trip_start?->format('Y-m-d') !== $incoming['trip_start']) {
            return false;
        }

        if (isset($incoming['trip_end']) && $existing->trip_end?->format('Y-m-d') !== $incoming['trip_end']) {
            return false;
        }

        return true;
    }
}
