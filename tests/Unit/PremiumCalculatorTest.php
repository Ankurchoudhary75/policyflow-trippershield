<?php

namespace Tests\Unit;

use App\Services\Pricing\PremiumCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PremiumCalculatorTest extends TestCase
{
    private PremiumCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new PremiumCalculator();
    }

    public function test_matches_trippershield_official_brief_example(): void
    {
        // Brief: PLUS, 5 days, age 28, INTERNATIONAL, 300k
        // 20 * 5 * 1.00 * 1.80 * 1.25 = 225.00
        $result = $this->calculator->calculate(
            planCode: 'PLUS',
            dateOfBirth: '1998-04-10',
            destination: 'INTERNATIONAL',
            tripStart: '2026-11-10',
            tripEnd: '2026-11-14',
            sumInsured: 300000
        );

        $this->assertSame('225.00', $result->amount);
        $this->assertSame('INR', $result->currency);
        $this->assertSame(5, $result->breakdown['travel_days']);
        $this->assertSame(28, $result->breakdown['customer_age_on_trip_start']);
        $this->assertSame('1.00', $result->breakdown['age_multiplier']);
        $this->assertSame('1.80', $result->breakdown['destination_multiplier']);
        $this->assertSame('1.25', $result->breakdown['coverage_multiplier']);
    }

    public function test_single_day_domestic_basic_plan(): void
    {
        // 12 * 1 * 1.00 * 1.00 * 1.00 = 12.00
        $result = $this->calculator->calculate(
            planCode: 'BASIC',
            dateOfBirth: '1996-05-15',
            destination: 'DOMESTIC',
            tripStart: '2026-06-01',
            tripEnd: '2026-06-01',
            sumInsured: 100000
        );

        $this->assertSame('12.00', $result->amount);
        $this->assertSame(1, $result->breakdown['travel_days']);
    }

    public function test_child_under_18_multiplier(): void
    {
        // 17-year-old: 12 * 1 * 1.10 * 1.00 * 1.00 = 13.20
        $result = $this->calculator->calculate(
            planCode: 'BASIC',
            dateOfBirth: '2009-06-02',
            destination: 'DOMESTIC',
            tripStart: '2026-06-01', // Exactly 16 or 17
            tripEnd: '2026-06-01',
            sumInsured: 100000
        );

        $this->assertSame('13.20', $result->amount);
        $this->assertSame('1.10', $result->breakdown['age_multiplier']);
    }

    public function test_age_18_boundary(): void
    {
        // Day before 18th birthday: age is 17 -> 1.10
        $resultPre = $this->calculator->calculate(
            planCode: 'BASIC',
            dateOfBirth: '2008-06-02',
            destination: 'DOMESTIC',
            tripStart: '2026-06-01',
            tripEnd: '2026-06-01',
            sumInsured: 100000
        );
        $this->assertSame(17, $resultPre->breakdown['customer_age_on_trip_start']);
        $this->assertSame('1.10', $resultPre->breakdown['age_multiplier']);

        // Exactly on 18th birthday: age is 18 -> 1.00
        $resultOn = $this->calculator->calculate(
            planCode: 'BASIC',
            dateOfBirth: '2008-06-01',
            destination: 'DOMESTIC',
            tripStart: '2026-06-01',
            tripEnd: '2026-06-01',
            sumInsured: 100000
        );
        $this->assertSame(18, $resultOn->breakdown['customer_age_on_trip_start']);
        $this->assertSame('1.00', $resultOn->breakdown['age_multiplier']);
    }

    public function test_age_60_to_61_boundary(): void
    {
        // Exactly on 60th birthday: 1.00
        $result60 = $this->calculator->calculate(
            planCode: 'BASIC',
            dateOfBirth: '1966-06-01',
            destination: 'DOMESTIC',
            tripStart: '2026-06-01',
            tripEnd: '2026-06-01',
            sumInsured: 100000
        );
        $this->assertSame(60, $result60->breakdown['customer_age_on_trip_start']);
        $this->assertSame('1.00', $result60->breakdown['age_multiplier']);

        // Exactly on 61st birthday: 1.50
        $result61 = $this->calculator->calculate(
            planCode: 'BASIC',
            dateOfBirth: '1965-06-01',
            destination: 'DOMESTIC',
            tripStart: '2026-06-01',
            tripEnd: '2026-06-01',
            sumInsured: 100000
        );
        $this->assertSame(61, $result61->breakdown['customer_age_on_trip_start']);
        $this->assertSame('1.50', $result61->breakdown['age_multiplier']);
    }

    public function test_age_70_to_71_boundary(): void
    {
        // Exactly on 70th birthday: 1.50
        $result70 = $this->calculator->calculate(
            planCode: 'BASIC',
            dateOfBirth: '1956-06-01',
            destination: 'DOMESTIC',
            tripStart: '2026-06-01',
            tripEnd: '2026-06-01',
            sumInsured: 100000
        );
        $this->assertSame(70, $result70->breakdown['customer_age_on_trip_start']);
        $this->assertSame('1.50', $result70->breakdown['age_multiplier']);

        // Exactly on 71st birthday: 2.00
        $result71 = $this->calculator->calculate(
            planCode: 'BASIC',
            dateOfBirth: '1955-06-01',
            destination: 'DOMESTIC',
            tripStart: '2026-06-01',
            tripEnd: '2026-06-01',
            sumInsured: 100000
        );
        $this->assertSame(71, $result71->breakdown['customer_age_on_trip_start']);
        $this->assertSame('2.00', $result71->breakdown['age_multiplier']);
    }

    public function test_senior_international_premium(): void
    {
        // 30 * 10 * 2.00 * 1.80 * 1.50 = 1620.00
        $result = $this->calculator->calculate(
            planCode: 'PREMIUM',
            dateOfBirth: '1954-01-01',
            destination: 'INTERNATIONAL',
            tripStart: '2026-06-01',
            tripEnd: '2026-06-10',
            sumInsured: 500000
        );

        $this->assertSame('1620.00', $result->amount);
    }

    public function test_decimal_rounding_half_up(): void
    {
        // BASIC (12) * 1 day * 1.10 (age 10) * 1.80 (intl) * 1.25 (300k)
        // 12 * 1 * 1.10 * 1.80 * 1.25 = 29.70
        $result = $this->calculator->calculate(
            planCode: 'BASIC',
            dateOfBirth: '2016-01-01',
            destination: 'INTERNATIONAL',
            tripStart: '2026-06-01',
            tripEnd: '2026-06-01',
            sumInsured: 300000
        );

        $this->assertSame('29.70', $result->amount);
    }

    public function test_rejects_unsupported_plan(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calculator->calculate('GOLD', '1990-01-01', 'DOMESTIC', '2026-06-01', '2026-06-05', 100000);
    }

    public function test_rejects_unsupported_coverage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calculator->calculate('BASIC', '1990-01-01', 'DOMESTIC', '2026-06-01', '2026-06-05', 200000);
    }

    public function test_rejects_trip_end_before_start(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calculator->calculate('BASIC', '1990-01-01', 'DOMESTIC', '2026-06-10', '2026-06-05', 100000);
    }
}
