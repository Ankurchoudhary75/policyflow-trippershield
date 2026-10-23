<?php

namespace Tests\Feature;

use App\Models\MockInsurerPolicy;
use App\Models\PolicyRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IssuanceTest extends TestCase
{
    use RefreshDatabase;

    private array $payload = [
        'client_request_id' => 'AGT-2026-9001',
        'agent_id' => 'AGENT-17',
        'customer_name' => 'John Traveler',
        'date_of_birth' => '1990-05-12',
        'destination' => 'INTERNATIONAL',
        'trip_start' => '2026-11-10',
        'trip_end' => '2026-11-14',
        'plan_code' => 'PLUS',
        'sum_insured' => 300000,
        'mock_scenario' => 'SUCCESS',
    ];

    public function test_successful_provider_submission_produces_single_policy_and_issued_state(): void
    {
        $createRes = $this->postJson('/api/policy-requests', $this->payload);
        $createRes->assertStatus(201);
        $id = $createRes->json('data.id');

        // Submit for issuance
        $submitRes = $this->postJson("/api/policy-requests/{$id}/submit");

        $submitRes->assertStatus(200)
            ->assertJsonPath('data.state', 'ISSUED')
            ->assertJsonPath('meta.issuance_action', 'ISSUED');

        $policyNumber = $submitRes->json('data.policy_number');
        $this->assertNotNull($policyNumber);
        $this->assertStringStartsWith('POL-2026-', $policyNumber);

        // Verify database records
        $policy = PolicyRequest::with('stateHistory', 'attempts')->find($id);
        $this->assertSame('ISSUED', $policy->state);
        $this->assertSame(1, $policy->attempt_count);
        $this->assertCount(1, $policy->attempts);
        $this->assertSame('SUCCESS', $policy->attempts->first()->outcome);

        // Verify history trail
        $states = $policy->stateHistory->pluck('to_state')->toArray();
        $this->assertContains('RECEIVED', $states);
        $this->assertContains('VALIDATED', $states);
        $this->assertContains('SUBMITTED', $states);
        $this->assertContains('ISSUED', $states);
    }

    public function test_repeated_submit_on_issued_policy_makes_no_second_provider_call(): void
    {
        $createRes = $this->postJson('/api/policy-requests', $this->payload);
        $id = $createRes->json('data.id');

        // First submit -> ISSUED
        $this->postJson("/api/policy-requests/{$id}/submit")->assertStatus(200);

        $mockPolicy = MockInsurerPolicy::where('idempotency_key', $id)->first();
        $this->assertSame(1, $mockPolicy->call_count);

        // Second submit -> Safely returns current state without re-calling provider
        $secondSubmit = $this->postJson("/api/policy-requests/{$id}/submit");
        $secondSubmit->assertStatus(200)
            ->assertJsonPath('data.state', 'ISSUED')
            ->assertJsonPath('meta.issuance_action', 'ALREADY_ISSUED');

        // Call count on insurer remained 1
        $this->assertSame(1, $mockPolicy->fresh()->call_count);
    }
}
