<?php

namespace App\Services\Pricing;

class PricingResult
{
    public function __construct(
        public readonly string $amount,
        public readonly string $currency,
        public readonly array $breakdown
    ) {}

    public function toArray(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => $this->currency,
            'breakdown' => $this->breakdown,
        ];
    }
}
