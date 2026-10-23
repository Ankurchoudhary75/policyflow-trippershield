<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('agent_id', 64);
            $table->string('client_request_id', 128);
            $table->string('customer_name', 255);
            $table->date('date_of_birth');
            $table->string('destination', 32); // DOMESTIC, INTERNATIONAL
            $table->date('trip_start');
            $table->date('trip_end');
            $table->string('plan_code', 32); // BASIC, PLUS, PREMIUM
            $table->decimal('sum_insured', 12, 2); // 100000, 300000, 500000
            
            // Calculated immutable pricing snapshot
            $table->decimal('premium_amount', 12, 2);
            $table->string('premium_currency', 8)->default('INR');
            $table->json('premium_breakdown');

            // Operational status & lifecycle
            $table->string('state', 32)->default('VALIDATED');
            $table->string('mock_scenario', 64)->nullable(); // Dev/testing scenario override
            
            // Provider integration & idempotency
            $table->string('provider_idempotency_key', 128)->index();
            $table->string('provider_reference', 128)->nullable()->index();
            $table->string('policy_number', 128)->nullable()->unique();
            $table->unsignedInteger('attempt_count')->default(0);
            
            // Concurrency control lease & safe audit
            $table->timestamp('submit_lease_until')->nullable()->index();
            $table->json('last_provider_response')->nullable();
            $table->text('last_error')->nullable();

            $table->timestamps();

            // Core database-enforced uniqueness
            $table->unique(['agent_id', 'client_request_id'], 'uq_policy_requests_agent_client_req');
            $table->index('state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_requests');
    }
};
