<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MockInsurerPolicy extends Model
{
    use HasFactory;

    protected $table = 'mock_insurer_policies';

    protected $fillable = [
        'idempotency_key',
        'scenario',
        'policy_number',
        'provider_reference',
        'status',
        'call_count',
        'last_payload',
    ];

    protected $casts = [
        'call_count' => 'integer',
        'last_payload' => 'array',
    ];
}
