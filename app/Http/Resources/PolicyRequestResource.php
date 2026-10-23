<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PolicyRequestResource extends JsonResource
{
    private bool $isReplay = false;
    private bool $payloadMatches = true;

    public function withReplayMeta(bool $isReplay, bool $payloadMatches = true): self
    {
        $this->isReplay = $isReplay;
        $this->payloadMatches = $payloadMatches;
        return $this;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_request_id' => $this->client_request_id,
            'agent_id' => $this->agent_id,
            'customer_name' => $this->customer_name,
            'date_of_birth' => $this->date_of_birth?->format('Y-m-d') ?? $this->date_of_birth,
            'destination' => $this->destination,
            'trip_start' => $this->trip_start?->format('Y-m-d') ?? $this->trip_start,
            'trip_end' => $this->trip_end?->format('Y-m-d') ?? $this->trip_end,
            'plan_code' => $this->plan_code,
            'sum_insured' => (string)$this->sum_insured,
            'state' => $this->state,
            'mock_scenario' => $this->mock_scenario,
            'premium' => [
                'amount' => number_format((float)$this->premium_amount, 2, '.', ''),
                'currency' => $this->premium_currency ?? 'INR',
                'breakdown' => $this->premium_breakdown,
            ],
            'provider_idempotency_key' => $this->provider_idempotency_key,
            'provider_reference' => $this->provider_reference,
            'policy_number' => $this->policy_number,
            'attempt_count' => (int)$this->attempt_count,
            'last_error' => $this->last_error,
            'history' => $this->relationLoaded('stateHistory') || $this->stateHistory()->exists()
                ? $this->stateHistory->map(fn($h) => [
                    'from' => $h->from_state,
                    'to' => $h->to_state,
                    'reason' => $h->reason,
                    'actor' => $h->actor,
                    'metadata' => $h->metadata,
                    'at' => $h->created_at?->toIso8601String(),
                ])
                : [],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    public function with(Request $request): array
    {
        return [
            'meta' => [
                'idempotent_replay' => $this->isReplay,
                'payload_matches' => $this->payloadMatches,
            ],
        ];
    }
}
