<?php

namespace Tests\Feature;

use App\Models\PolicyRequest;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class DatabaseConstraintTest extends TestCase
{
    use RefreshDatabase;

    public function test_raw_database_blocks_duplicate_agent_and_client_request_id(): void
    {
        $id1 = (string) Str::ulid();
        $id2 = (string) Str::ulid();

        DB::table('policy_requests')->insert([
            'id' => $id1,
            'agent_id' => 'AGENT-100',
            'client_request_id' => 'CLIENT-REQ-100',
            'customer_name' => 'Alice Test',
            'date_of_birth' => '1995-01-01',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-06-01',
            'trip_end' => '2026-06-05',
            'plan_code' => 'PLUS',
            'sum_insured' => 300000,
            'premium_amount' => 100.00,
            'premium_currency' => 'INR',
            'premium_breakdown' => json_encode([]),
            'state' => 'VALIDATED',
            'provider_idempotency_key' => $id1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Attempting raw second insert with identical (agent_id, client_request_id) must fail at DB layer
        $this->expectException(QueryException::class);

        DB::table('policy_requests')->insert([
            'id' => $id2,
            'agent_id' => 'AGENT-100',
            'client_request_id' => 'CLIENT-REQ-100',
            'customer_name' => 'Bob Test',
            'date_of_birth' => '1990-01-01',
            'destination' => 'INTERNATIONAL',
            'trip_start' => '2026-06-01',
            'trip_end' => '2026-06-05',
            'plan_code' => 'PREMIUM',
            'sum_insured' => 500000,
            'premium_amount' => 200.00,
            'premium_currency' => 'INR',
            'premium_breakdown' => json_encode([]),
            'state' => 'VALIDATED',
            'provider_idempotency_key' => $id2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_raw_database_blocks_duplicate_policy_number(): void
    {
        $id1 = (string) Str::ulid();
        $id2 = (string) Str::ulid();
        $duplicatePolNumber = 'POL-DUPLICATE-999';

        DB::table('policy_requests')->insert([
            'id' => $id1,
            'agent_id' => 'AGENT-1',
            'client_request_id' => 'CLIENT-1',
            'customer_name' => 'Alice Test',
            'date_of_birth' => '1995-01-01',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-06-01',
            'trip_end' => '2026-06-05',
            'plan_code' => 'PLUS',
            'sum_insured' => 300000,
            'premium_amount' => 100.00,
            'premium_currency' => 'INR',
            'premium_breakdown' => json_encode([]),
            'state' => 'ISSUED',
            'policy_number' => $duplicatePolNumber,
            'provider_idempotency_key' => $id1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('policy_requests')->insert([
            'id' => $id2,
            'agent_id' => 'AGENT-2',
            'client_request_id' => 'CLIENT-2',
            'customer_name' => 'Bob Test',
            'date_of_birth' => '1990-01-01',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-06-01',
            'trip_end' => '2026-06-05',
            'plan_code' => 'PLUS',
            'sum_insured' => 300000,
            'premium_amount' => 100.00,
            'premium_currency' => 'INR',
            'premium_breakdown' => json_encode([]),
            'state' => 'ISSUED',
            'policy_number' => $duplicatePolNumber,
            'provider_idempotency_key' => $id2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_model_guard_blocks_tampering_with_immutable_premium_snapshot(): void
    {
        $policy = PolicyRequest::create([
            'agent_id' => 'AGENT-SNAPSHOT',
            'client_request_id' => 'CLIENT-SNAPSHOT-1',
            'customer_name' => 'Snapshot Customer',
            'date_of_birth' => '1990-01-01',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-06-01',
            'trip_end' => '2026-06-05',
            'plan_code' => 'PLUS',
            'sum_insured' => '300000',
            'premium_amount' => '100.00',
            'premium_breakdown' => ['initial' => true],
            'state' => 'VALIDATED',
            'provider_idempotency_key' => (string) Str::ulid(),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Immutable Violation');

        $policy->premium_amount = '999.00';
        $policy->save();
    }
}
