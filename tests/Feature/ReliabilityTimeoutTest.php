<?php

namespace Tests\Feature;

use App\Models\MockInsurerPolicy;
use App\Models\PolicyRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReliabilityTimeoutTest extends TestCase
{
    use RefreshDatabase;

    private array $payload = [
        'client_request_id' => 'AGT-TIMEOUT-001',
        'agent_id' => 'AGENT-17',
        'customer_name' => 'Timeout Customer',
        'date_of_birth' => '1992-03-15',
        'destination' => 'INTERNATIONAL',
        'trip_start' => '2026-12-01',
        'trip_end' => '2026-12-10',
        'plan_code' => 'PREMIUM',
        'sum_insured' => 500000,
        'mock_scenario' => 'TIMEOUT_AFTER_ISSUE',
    ];

    public function test_timeout_after_success_moves_to_reconciliation_and_reconciles_safely_without_double_issue(): void
    {
        // 1. Intake policy request
        $createRes = $this->postJson('/api/policy-requests', $this->payload);
        $createRes->assertStatus(201);
        $id = $createRes->json('data.id');

        // 2. Submit -> Upstream times out after issuing on mock provider
        $submitRes = $this->postJson("/api/policy-requests/{$id}/submit");
        $submitRes->assertStatus(200)
            ->assertJsonPath('data.state', 'NEEDS_RECONCILIATION')
            ->assertJsonPath('meta.issuance_action', 'NEEDS_RECONCILIATION');

        $policy = PolicyRequest::with('attempts')->find($id);
        $this->assertSame('NEEDS_RECONCILIATION', $policy->state);
        $this->assertCount(1, $policy->attempts);
        $this->assertSame('TIMEOUT', $policy->attempts->first()->outcome);

        // Verify policy was created on the insurer side
        $mockPolicy = MockInsurerPolicy::where('idempotency_key', $id)->first();
        $this->assertNotNull($mockPolicy);
        $this->assertSame('ISSUED', $mockPolicy->status);
        $originalPolicyNumber = $mockPolicy->policy_number;

        // 3. Second submit triggers reconciliation lookup instead of blind re-issuance
        $reconcileRes = $this->postJson("/api/policy-requests/{$id}/submit");
        $reconcileRes->assertStatus(200)
            ->assertJsonPath('data.state', 'ISSUED')
            ->assertJsonPath('data.policy_number', $originalPolicyNumber)
            ->assertJsonPath('meta.issuance_action', 'RECONCILED_ISSUED');

        // Verify no duplicate policy was created on provider
        $this->assertDatabaseCount('mock_insurer_policies', 1);
        $this->assertSame($originalPolicyNumber, $policy->fresh()->policy_number);
    }

    public function test_artisan_reconciliation_command_resolves_timeout_state(): void
    {
        $createRes = $this->postJson('/api/policy-requests', $this->payload);
        $id = $createRes->json('data.id');

        // Submit -> Enters NEEDS_RECONCILIATION
        $this->postJson("/api/policy-requests/{$id}/submit");
        $this->assertSame('NEEDS_RECONCILIATION', PolicyRequest::find($id)->state);

        // Run artisan reconciliation command
        $this->artisan('policies:reconcile')
            ->expectsOutputToContain('Successfully processed 1 policy reconciliation(s).')
            ->assertSuccessful();

        $this->assertSame('ISSUED', PolicyRequest::find($id)->state);
    }
}
