<?php

namespace Tests\Feature;

use App\Models\PolicyRequest;
use App\Models\ProviderEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReliabilityCallbackTest extends TestCase
{
    use RefreshDatabase;

    private array $payload = [
        'client_request_id' => 'AGT-ASYNC-001',
        'agent_id' => 'AGENT-17',
        'customer_name' => 'Async Customer',
        'date_of_birth' => '1995-07-20',
        'destination' => 'DOMESTIC',
        'trip_start' => '2026-11-01',
        'trip_end' => '2026-11-05',
        'plan_code' => 'PLUS',
        'sum_insured' => 300000,
        'mock_scenario' => 'DELAYED_CALLBACK',
    ];

    public function test_triple_callback_applied_once_and_duplicates_ignored(): void
    {
        // 1. Intake and submit policy request
        $createRes = $this->postJson('/api/policy-requests', $this->payload);
        $id = $createRes->json('data.id');

        $submitRes = $this->postJson("/api/policy-requests/{$id}/submit");
        $submitRes->assertStatus(200)
            ->assertJsonPath('data.state', 'PENDING_CONFIRMATION');

        $eventId = 'EVT-WEBHOOK-999';
        $callbackPayload = [
            'provider_event_id' => $eventId,
            'policy_request_id' => $id,
            'event_type' => 'CONFIRMED',
            'policy_number' => 'POL-ASYNC-777',
            'provider_reference' => 'REF-ASYNC-888',
        ];

        // 2. Deliver callback 1st time -> APPLIED
        $call1 = $this->postJson('/api/mock-insurer/callback', $callbackPayload);
        $call1->assertStatus(200)
            ->assertJsonPath('result', 'applied')
            ->assertJsonPath('state', 'ISSUED');

        $policy = PolicyRequest::find($id);
        $this->assertSame('ISSUED', $policy->state);
        $this->assertSame('POL-ASYNC-777', $policy->policy_number);

        // 3. Deliver callback 2nd time -> DUPLICATE_IGNORED
        $call2 = $this->postJson('/api/mock-insurer/callback', $callbackPayload);
        $call2->assertStatus(200)
            ->assertJsonPath('result', 'duplicate_ignored');

        // 4. Deliver callback 3rd time -> DUPLICATE_IGNORED
        $call3 = $this->postJson('/api/mock-insurer/callback', $callbackPayload);
        $call3->assertStatus(200)
            ->assertJsonPath('result', 'duplicate_ignored');

        // Only one event row exists in provider_events
        $this->assertDatabaseCount('provider_events', 1);
        $this->assertSame('APPLIED', ProviderEvent::first()->result);
    }

    public function test_late_rejected_callback_does_not_overwrite_issued_state(): void
    {
        $createRes = $this->postJson('/api/policy-requests', $this->payload);
        $id = $createRes->json('data.id');
        $this->postJson("/api/policy-requests/{$id}/submit");

        // 1. Confirm policy first
        $this->postJson('/api/mock-insurer/callback', [
            'provider_event_id' => 'EVT-CONFIRM-1',
            'policy_request_id' => $id,
            'event_type' => 'CONFIRMED',
            'policy_number' => 'POL-CONFIRMED-1',
        ])->assertStatus(200);

        $this->assertSame('ISSUED', PolicyRequest::find($id)->state);

        // 2. Late REJECTED event arrives
        $lateRejection = $this->postJson('/api/mock-insurer/callback', [
            'provider_event_id' => 'EVT-REJECT-2',
            'policy_request_id' => $id,
            'event_type' => 'REJECTED',
            'error_reason' => 'Late underwriting rejection',
        ]);

        $lateRejection->assertStatus(200)
            ->assertJsonPath('result', 'no_op_state_preserved')
            ->assertJsonPath('current_state', 'ISSUED');

        // Policy MUST remain in ISSUED state
        $this->assertSame('ISSUED', PolicyRequest::find($id)->state);
    }
}
