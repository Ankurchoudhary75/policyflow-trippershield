<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_state_history', function (Blueprint $table) {
            $table->id();
            $table->ulid('policy_request_id')->index();
            $table->string('from_state', 32)->nullable();
            $table->string('to_state', 32);
            $table->string('reason', 255);
            $table->string('actor', 64); // SYSTEM, PROVIDER, RECONCILER, API
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('policy_request_id')->references('id')->on('policy_requests')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_state_history');
    }
};
