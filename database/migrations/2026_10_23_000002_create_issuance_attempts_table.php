<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issuance_attempts', function (Blueprint $table) {
            $table->id();
            $table->ulid('policy_request_id')->index();
            $table->unsignedInteger('attempt_no');
            $table->string('idempotency_key', 128);
            $table->string('outcome', 32); // SUCCESS, RETRIABLE_FAILURE, FATAL_FAILURE, TIMEOUT, PENDING
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->json('response_summary')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['policy_request_id', 'attempt_no'], 'uq_issuance_attempts_req_attempt');
            $table->foreign('policy_request_id')->references('id')->on('policy_requests')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issuance_attempts');
    }
};
