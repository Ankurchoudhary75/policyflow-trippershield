<?php

namespace Tests\Unit;

use App\Enums\PolicyState;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\PolicyRequest;
use App\Models\PolicyStateHistory;
use App\Services\Workflow\StateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StateMachineTest extends TestCase
{
    use RefreshDatabase;

    private StateMachine $stateMachine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stateMachine = new StateMachine();
    }

    private function createStubPolicy(string $state = 'VALIDATED'): PolicyRequest
    {
        return PolicyRequest::create([
            'agent_id' => 'AGENT-1',
            'client_request_id' => 'REQ-' . uniqid(),
            'customer_name' => 'Alice Test',
            'date_of_birth' => '1995-01-01',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-06-01',
            'trip_end' => '2026-06-05',
            'plan_code' => 'PLUS',
            'sum_insured' => '300000',
            'premium_amount' => '100.00',
            'premium_breakdown' => ['test' => true],
            'state' => $state,
            'provider_idempotency_key' => 'IDEM-' . uniqid(),
        ]);
    }

    public function test_can_transition_through_happy_path(): void
    {
        $policy = $this->createStubPolicy('VALIDATED');

        // VALIDATED -> SUBMITTED
        $this->stateMachine->transition($policy, PolicyState::SUBMITTED, 'Submission started');
        $this->assertSame(PolicyState::SUBMITTED->value, $policy->fresh()->state);

        // SUBMITTED -> ISSUED
        $this->stateMachine->transition($policy, PolicyState::ISSUED, 'Provider confirmed');
        $this->assertSame(PolicyState::ISSUED->value, $policy->fresh()->state);

        // Verify state history created 2 rows
        $history = PolicyStateHistory::where('policy_request_id', $policy->id)->get();
        $this->assertCount(2, $history);
        $this->assertSame('VALIDATED', $history[0]->from_state);
        $this->assertSame('SUBMITTED', $history[0]->to_state);
        $this->assertSame('SUBMITTED', $history[1]->from_state);
        $this->assertSame('ISSUED', $history[1]->to_state);
    }

    public function test_rejects_regression_from_issued_to_submitted(): void
    {
        $policy = $this->createStubPolicy('ISSUED');

        $this->expectException(InvalidStateTransitionException::class);
        $this->stateMachine->transition($policy, PolicyState::SUBMITTED, 'Illegal retry attempt');
    }

    public function test_rejects_regression_from_issued_to_failed(): void
    {
        $policy = $this->createStubPolicy('ISSUED');

        $this->expectException(InvalidStateTransitionException::class);
        $this->stateMachine->transition($policy, PolicyState::FAILED, 'Late failure message');
    }

    public function test_allows_retriable_failure_rollback_to_validated(): void
    {
        $policy = $this->createStubPolicy('SUBMITTED');

        $this->stateMachine->transition($policy, PolicyState::VALIDATED, 'Temporary insurer error, ready to retry');
        $this->assertSame(PolicyState::VALIDATED->value, $policy->fresh()->state);
    }
}
