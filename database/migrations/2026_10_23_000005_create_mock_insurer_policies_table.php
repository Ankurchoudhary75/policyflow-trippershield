<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mock_insurer_policies', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key', 128)->unique();
            $table->string('scenario', 64);
            $table->string('policy_number', 128)->unique();
            $table->string('provider_reference', 128)->unique();
            $table->string('status', 32)->default('ISSUED'); // ISSUED, PENDING, FAILED
            $table->unsignedInteger('call_count')->default(1);
            $table->json('last_payload')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mock_insurer_policies');
    }
};
