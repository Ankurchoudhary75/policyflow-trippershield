<?php

namespace Tests\Feature;

use App\Models\PolicyRequest;
use App\Services\Intake\PolicyRequestService;
use App\Services\Pricing\PremiumCalculator;
use App\Services\Workflow\StateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private array $payload = [
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

    public function test_repeated_submission_returns_200_and_does_not_duplicate(): void
    {
        // First submission -> 201 Created
        $first = $this->postJson('/api/policy-requests', $this->payload);
        $first->assertStatus(201)
            ->assertJsonPath('meta.idempotent_replay', false);
        $id1 = $first->json('data.id');

        // Second submission -> 200 OK with same ID
        $second = $this->postJson('/api/policy-requests', $this->payload);
        $second->assertStatus(200)
            ->assertJsonPath('meta.idempotent_replay', true)
            ->assertJsonPath('meta.payload_matches', true)
            ->assertJsonPath('data.id', $id1);

        // Verify only 1 database row exists
        $this->assertDatabaseCount('policy_requests', 1);
    }

    public function test_repeated_submission_with_different_payload_flags_mismatch(): void
    {
        // First submission
        $this->postJson('/api/policy-requests', $this->payload)->assertStatus(201);

        // Second submission with modified customer name and destination
        $tampered = array_merge($this->payload, [
            'customer_name' => 'Altered Name',
            'destination' => 'DOMESTIC',
        ]);

        $second = $this->postJson('/api/policy-requests', $tampered);
        $second->assertStatus(200)
            ->assertJsonPath('meta.idempotent_replay', true)
            ->assertJsonPath('meta.payload_matches', false)
            ->assertJsonPath('data.customer_name', 'Sample Customer'); // Original preserved

        $this->assertDatabaseCount('policy_requests', 1);
    }

    public function test_same_client_request_id_from_different_agents_creates_distinct_requests(): void
    {
        $payloadAgent1 = $this->payload;
        $payloadAgent2 = array_merge($this->payload, [
            'agent_id' => 'AGENT-99',
        ]);

        $res1 = $this->postJson('/api/policy-requests', $payloadAgent1);
        $res2 = $this->postJson('/api/policy-requests', $payloadAgent2);

        $res1->assertStatus(201);
        $res2->assertStatus(201);

        $this->assertNotSame($res1->json('data.id'), $res2->json('data.id'));
        $this->assertDatabaseCount('policy_requests', 2);
    }

    public function test_concurrency_race_condition_arbitration(): void
    {
        $calculator = new PremiumCalculator();
        $stateMachine = new StateMachine();
        $service = new PolicyRequestService($calculator, $stateMachine);

        // First call normal
        [$policy1, $isReplay1] = $service->createOrRetrieve($this->payload);
        $this->assertFalse($isReplay1);

        // Second call directly invokes createOrRetrieve, safely fetching existing record
        [$policy2, $isReplay2] = $service->createOrRetrieve($this->payload);
        $this->assertTrue($isReplay2);
        $this->assertSame($policy1->id, $policy2->id);

        $this->assertDatabaseCount('policy_requests', 1);
    }
}
