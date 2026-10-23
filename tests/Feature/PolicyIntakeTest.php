<?php

namespace Tests\Feature;

use App\Models\PolicyRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PolicyIntakeTest extends TestCase
{
    use RefreshDatabase;

    private array $validPayload = [
        'client_request_id' => 'AGT-2026-0001',
        'agent_id' => 'AGENT-17',
        'customer_name' => 'Sample Customer',
        'date_of_birth' => '1998-04-10',
        'destination' => 'INTERNATIONAL',
        'trip_start' => '2026-11-10',
        'trip_end' => '2026-11-14',
        'plan_code' => 'PLUS',
        'sum_insured' => 300000,
    ];

    public function test_creates_policy_request_with_correct_premium_and_status(): void
    {
        $response = $this->postJson('/api/policy-requests', $this->validPayload);

        $response->assertStatus(201)
            ->assertJsonPath('data.client_request_id', 'AGT-2026-0001')
            ->assertJsonPath('data.agent_id', 'AGENT-17')
            ->assertJsonPath('data.state', 'VALIDATED')
            ->assertJsonPath('data.premium.amount', '225.00')
            ->assertJsonPath('data.premium.currency', 'INR')
            ->assertJsonPath('data.premium.breakdown.travel_days', 5)
            ->assertJsonPath('meta.idempotent_replay', false);

        $this->assertDatabaseCount('policy_requests', 1);
        $this->assertDatabaseHas('policy_requests', [
            'client_request_id' => 'AGT-2026-0001',
            'agent_id' => 'AGENT-17',
            'state' => 'VALIDATED',
            'premium_amount' => '225.00',
        ]);
    }

    public function test_rejects_missing_required_fields(): void
    {
        $response = $this->postJson('/api/policy-requests', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'client_request_id',
                'agent_id',
                'customer_name',
                'date_of_birth',
                'destination',
                'trip_start',
                'trip_end',
                'plan_code',
                'sum_insured',
            ]);
    }

    public function test_rejects_trip_end_before_trip_start(): void
    {
        $payload = array_merge($this->validPayload, [
            'trip_start' => '2026-11-14',
            'trip_end' => '2026-11-10',
        ]);

        $response = $this->postJson('/api/policy-requests', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['trip_end']);
    }

    public function test_rejects_unsupported_plan_code(): void
    {
        $payload = array_merge($this->validPayload, [
            'plan_code' => 'DIAMOND',
        ]);

        $response = $this->postJson('/api/policy-requests', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['plan_code']);
    }

    public function test_rejects_unsupported_sum_insured(): void
    {
        $payload = array_merge($this->validPayload, [
            'sum_insured' => 200000,
        ]);

        $response = $this->postJson('/api/policy-requests', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['sum_insured']);
    }

    public function test_rejects_date_of_birth_after_trip_start(): void
    {
        $payload = array_merge($this->validPayload, [
            'date_of_birth' => '2027-01-01',
            'trip_start' => '2026-11-10',
        ]);

        $response = $this->postJson('/api/policy-requests', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['date_of_birth']);
    }

    public function test_can_retrieve_policy_request_by_id(): void
    {
        $createResponse = $this->postJson('/api/policy-requests', $this->validPayload);
        $id = $createResponse->json('data.id');

        $showResponse = $this->getJson("/api/policy-requests/{$id}");

        $showResponse->assertStatus(200)
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.premium.amount', '225.00')
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'client_request_id',
                    'agent_id',
                    'state',
                    'premium',
                    'history',
                ],
            ]);
    }

    public function test_returns_404_for_unknown_policy_id(): void
    {
        $this->getJson('/api/policy-requests/non-existent-id')
            ->assertStatus(404);
    }

    public function test_lists_policy_requests_with_pagination_and_filter(): void
    {
        $this->postJson('/api/policy-requests', $this->validPayload);

        $response = $this->getJson('/api/policy-requests?status=VALIDATED');
        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $emptyResponse = $this->getJson('/api/policy-requests?status=ISSUED');
        $emptyResponse->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_returns_422_on_invalid_status_filter(): void
    {
        $this->getJson('/api/policy-requests?status=UNKNOWN_STATUS')
            ->assertStatus(422);
    }
}
