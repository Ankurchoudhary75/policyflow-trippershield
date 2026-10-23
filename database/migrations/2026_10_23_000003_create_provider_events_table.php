<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider_event_id', 128)->unique();
            $table->ulid('policy_request_id')->nullable()->index();
            $table->string('event_type', 64); // CONFIRMED, REJECTED, PENDING
            $table->json('payload');
            $table->string('result', 64); // APPLIED, DUPLICATE_IGNORED, REJECTED_INVALID_TRANSITION, NOT_FOUND
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->foreign('policy_request_id')->references('id')->on('policy_requests')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_events');
    }
};
