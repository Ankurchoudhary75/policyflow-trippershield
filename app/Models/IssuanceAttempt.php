<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IssuanceAttempt extends Model
{
    use HasFactory;

    protected $table = 'issuance_attempts';

    protected $fillable = [
        'policy_request_id',
        'attempt_no',
        'idempotency_key',
        'outcome',
        'http_status',
        'response_summary',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'attempt_no' => 'integer',
        'http_status' => 'integer',
        'response_summary' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function policyRequest(): BelongsTo
    {
        return $this->belongsTo(PolicyRequest::class, 'policy_request_id');
    }
}
