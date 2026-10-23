<?php

namespace Tests\Feature;

use App\Models\PolicyRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReliabilityRetryTest extends TestCase
{
    use RefreshDatabase;

    private array $payload = [
        'client_request_id' => 'AGT-RETRY-001',
        'agent_id' => 'AGENT-17',
        'customer_name' => 'Retry Customer',
        'date_of_birth' => '1991-08-14',
        'destination' => 'DOMESTIC',
        'trip_start' => '2026-11-01',
        'trip_end' => '2026-11-05',
        'plan_code' => 'BASIC',
        'sum_insured' => 100000,
        'mock_scenario' => 'TEMPORARY_FAILURE',
    ];

    public function test_temporary_failure_preserves_immutable_premium_and_allows_safe_retry_until_max_attempts(): void
    {
        $createRes = $this->postJson('/api/policy-requests', $this->payload);
        $id = $createRes->json('data.id');
        $originalPremium = $createRes->json('data.premium.amount');

        // Attempt 1: Fails retriably -> rolls back to VALIDATED
        $sub1 = $this->postJson("/api/policy-requests/{$id}/submit");
        $sub1->assertStatus(200)
            ->assertJsonPath('data.state', 'VALIDATED')
            ->assertJsonPath('data.attempt_count', 1)
            ->assertJsonPath('meta.issuance_action', 'RETRIABLE_FAILURE');

        $policy = PolicyRequest::find($id);
        $this->assertSame('VALIDATED', $policy->state);
        $this->assertSame(1, $policy->attempt_count);
        $this->assertSame($originalPremium, (string)$policy->premium_amount);

        // Attempt 2: Fails retriably -> still VALIDATED, attempt_count = 2
        $sub2 = $this->postJson("/api/policy-requests/{$id}/submit");
        $sub2->assertStatus(200)
            ->assertJsonPath('data.state', 'VALIDATED')
            ->assertJsonPath('data.attempt_count', 2);

        // Attempt 3: Reaches max attempts (3) -> transitions to terminal FAILED
        $sub3 = $this->postJson("/api/policy-requests/{$id}/submit");
        $sub3->assertStatus(200)
            ->assertJsonPath('data.state', 'FAILED')
            ->assertJsonPath('data.attempt_count', 3);

        $this->assertSame('FAILED', $policy->fresh()->state);
        $this->assertSame($originalPremium, (string)$policy->fresh()->premium_amount);

        // Further submit attempt on terminal FAILED returns 409 Conflict
        $this->postJson("/api/policy-requests/{$id}/submit")
            ->assertStatus(409)
            ->assertJsonPath('error', 'INVALID_STATE');
    }
}
