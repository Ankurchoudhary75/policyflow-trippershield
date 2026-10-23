<?php

namespace App\Models;

use App\Enums\PolicyState;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class PolicyRequest extends Model
{
    use HasFactory, HasUlids;

    protected $table = 'policy_requests';

    protected $fillable = [
        'id',
        'agent_id',
        'client_request_id',
        'customer_name',
        'date_of_birth',
        'destination',
        'trip_start',
        'trip_end',
        'plan_code',
        'sum_insured',
        'premium_amount',
        'premium_currency',
        'premium_breakdown',
        'state',
        'mock_scenario',
        'provider_idempotency_key',
        'provider_reference',
        'policy_number',
        'attempt_count',
        'submit_lease_until',
        'last_provider_response',
        'last_error',
    ];

    protected $casts = [
        'date_of_birth' => 'date:Y-m-d',
        'trip_start' => 'date:Y-m-d',
        'trip_end' => 'date:Y-m-d',
        'sum_insured' => 'string',
        'premium_amount' => 'string',
        'premium_breakdown' => 'array',
        'last_provider_response' => 'array',
        'attempt_count' => 'integer',
        'submit_lease_until' => 'datetime',
    ];

    protected function premiumAmount(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            get: fn ($value) => $value !== null ? number_format((float)$value, 2, '.', '') : null,
        );
    }

    protected static function booted(): void
    {
        // Enforce immutable pricing snapshot: once premium_amount is set, prevent mutations
        static::updating(function (PolicyRequest $model) {
            if ($model->isDirty('premium_amount') && $model->getOriginal('premium_amount') !== null) {
                throw new RuntimeException("Immutable Violation: Policy request premium_amount cannot be altered after creation.");
            }
            if ($model->isDirty('premium_breakdown') && $model->getOriginal('premium_breakdown') !== null) {
                throw new RuntimeException("Immutable Violation: Policy request premium_breakdown cannot be altered after creation.");
            }
        });
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(IssuanceAttempt::class, 'policy_request_id')->orderBy('attempt_no', 'asc');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ProviderEvent::class, 'policy_request_id')->orderBy('created_at', 'asc');
    }

    public function stateHistory(): HasMany
    {
        return $this->hasMany(PolicyStateHistory::class, 'policy_request_id')->orderBy('created_at', 'asc');
    }
}
