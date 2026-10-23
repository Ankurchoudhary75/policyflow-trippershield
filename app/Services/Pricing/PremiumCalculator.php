<?php

namespace App\Services\Pricing;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

class PremiumCalculator
{
    private const BASE_DAILY_RATES = [
        'BASIC' => '12.00',
        'PLUS' => '20.00',
        'PREMIUM' => '30.00',
    ];

    private const DESTINATION_MULTIPLIERS = [
        'DOMESTIC' => '1.00',
        'INTERNATIONAL' => '1.80',
    ];

    private const COVERAGE_MULTIPLIERS = [
        '100000' => '1.00',
        '300000' => '1.25',
        '500000' => '1.50',
    ];

    /**
     * Compute deterministic policy premium with arbitrary-precision decimal safety.
     *
     * Formula:
     * premium = base_daily_rate * travel_days * age_multiplier * destination_multiplier * coverage_multiplier
     * Rounded HALF_UP to 2 decimal places at the very end.
     */
    public function calculate(
        string $planCode,
        string $dateOfBirth,
        string $destination,
        string $tripStart,
        string $tripEnd,
        string|int|float $sumInsured
    ): PricingResult {
        $plan = strtoupper(trim($planCode));
        if (!isset(self::BASE_DAILY_RATES[$plan])) {
            throw new InvalidArgumentException("Unsupported plan code: {$planCode}. Allowed: BASIC, PLUS, PREMIUM");
        }
        $baseRate = self::BASE_DAILY_RATES[$plan];

        $dest = strtoupper(trim($destination));
        if (!isset(self::DESTINATION_MULTIPLIERS[$dest])) {
            throw new InvalidArgumentException("Unsupported destination: {$destination}. Allowed: DOMESTIC, INTERNATIONAL");
        }
        $destMultiplier = self::DESTINATION_MULTIPLIERS[$dest];

        // Format sum insured to standard string integer without decimals for lookup
        $covKey = (string)(int)$sumInsured;
        if (!isset(self::COVERAGE_MULTIPLIERS[$covKey])) {
            throw new InvalidArgumentException("Unsupported sum insured: {$sumInsured}. Allowed: 100000, 300000, 500000");
        }
        $covMultiplier = self::COVERAGE_MULTIPLIERS[$covKey];

        $birthDate = CarbonImmutable::parse($dateOfBirth)->startOfDay();
        $startDate = CarbonImmutable::parse($tripStart)->startOfDay();
        $endDate = CarbonImmutable::parse($tripEnd)->startOfDay();

        if ($endDate->lessThan($startDate)) {
            throw new InvalidArgumentException("trip_end cannot be before trip_start.");
        }

        if ($startDate->lessThan($birthDate)) {
            throw new InvalidArgumentException("date_of_birth must be before trip_start.");
        }

        // Travel days is inclusive of start and end
        $travelDays = (int) $startDate->diffInDays($endDate) + 1;

        // Age in full completed calendar years on trip start date
        $age = $birthDate->diff($startDate)->y;
        $ageMultiplier = $this->resolveAgeMultiplier($age);

        // Arbitrary precision multiplication without intermediate rounding
        $calc = BigDecimal::of($baseRate)
            ->multipliedBy(BigDecimal::of($travelDays))
            ->multipliedBy(BigDecimal::of($ageMultiplier))
            ->multipliedBy(BigDecimal::of($destMultiplier))
            ->multipliedBy(BigDecimal::of($covMultiplier));

        // Final round to 2 decimals using HALF_UP
        $finalAmount = $calc->toScale(2, RoundingMode::HALF_UP);

        return new PricingResult(
            amount: (string)$finalAmount,
            currency: 'INR',
            breakdown: [
                'plan_code' => $plan,
                'base_daily_rate' => $baseRate,
                'travel_days' => $travelDays,
                'customer_age_on_trip_start' => $age,
                'age_multiplier' => $ageMultiplier,
                'destination' => $dest,
                'destination_multiplier' => $destMultiplier,
                'sum_insured' => (int)$covKey,
                'coverage_multiplier' => $covMultiplier,
                'unrounded_total' => (string)$calc,
                'rounding_mode' => 'HALF_UP',
            ]
        );
    }

    private function resolveAgeMultiplier(int $age): string
    {
        return match (true) {
            $age <= 17 => '1.10',
            $age <= 60 => '1.00',
            $age <= 70 => '1.50',
            default => '2.00',
        };
    }
}
