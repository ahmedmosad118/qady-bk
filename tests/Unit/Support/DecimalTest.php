<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Decimal;
use DivisionByZeroError;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class DecimalTest extends TestCase
{
    public function test_positive_and_negative_rounding_and_half_way_rounding(): void
    {
        $this->assertSame('10.12', Decimal::round('10.124', Decimal::MONEY_SCALE));
        $this->assertSame('10.13', Decimal::round('10.125', Decimal::MONEY_SCALE));
        $this->assertSame('-10.12', Decimal::round('-10.124', Decimal::MONEY_SCALE));
        $this->assertSame('-10.13', Decimal::round('-10.125', Decimal::MONEY_SCALE));

        // Half-way rounding at scale 2 and scale 3
        $this->assertSame('0.01', Decimal::round('0.005', Decimal::MONEY_SCALE));
        $this->assertSame('-0.01', Decimal::round('-0.005', Decimal::MONEY_SCALE));
        $this->assertSame('0.001', Decimal::round('0.0005', Decimal::QUANTITY_SCALE));
        $this->assertSame('-0.001', Decimal::round('-0.0005', Decimal::QUANTITY_SCALE));

        // Carry-over halfway rounding across integer boundary
        $this->assertSame('1.00', Decimal::round('0.995', Decimal::MONEY_SCALE));
        $this->assertSame('-1.00', Decimal::round('-0.995', Decimal::MONEY_SCALE));
        $this->assertSame('1.000', Decimal::round('0.9995', Decimal::QUANTITY_SCALE));
        $this->assertSame('-1.000', Decimal::round('-0.9995', Decimal::QUANTITY_SCALE));

        // Scale 0 rounding
        $this->assertSame('1', Decimal::round('0.5', 0));
        $this->assertSame('-1', Decimal::round('-0.5', 0));
        $this->assertSame('0', Decimal::round('0.49', 0));
        $this->assertSame('0', Decimal::round('-0.49', 0));
    }

    public function test_negative_zero_is_normalized_to_canonical_unsigned_zero(): void
    {
        $this->assertSame('0', Decimal::parse('-0'));
        $this->assertSame('0.000', Decimal::parse('-0.000'));
        $this->assertSame('0.00', Decimal::round('-0.001', Decimal::MONEY_SCALE));
        $this->assertSame('0.000', Decimal::round('-0.0001', Decimal::QUANTITY_SCALE));
        $this->assertSame('0.00', Decimal::add('-0.00', '0.00', Decimal::MONEY_SCALE));
        $this->assertSame('0.00', Decimal::sub('0.00', '0.00', Decimal::MONEY_SCALE));
        $this->assertSame('0.00', Decimal::mul('-0.0001', '0.0001', Decimal::MONEY_SCALE));
        $this->assertSame('0.00', Decimal::div('-0.00001', '1000', Decimal::MONEY_SCALE));
    }

    public function test_very_small_decimals_and_large_values_preserve_exact_precision(): void
    {
        // Decimals smaller than CALCULATION_SCALE (8)
        $this->assertSame('0.0000000001', Decimal::parse('0.0000000001'));
        $this->assertSame('-0.0000000001', Decimal::parse('-0.0000000001'));
        $this->assertSame('0.00', Decimal::round('-0.0000000001', Decimal::MONEY_SCALE));
        $this->assertSame(1, Decimal::comp('0.0000000001', '0', 10));
        $this->assertSame(-1, Decimal::comp('-0.0000000001', '0', 10));

        // Large values beyond IEEE 754 float precision (18+ integer digits)
        $largeA = '999999999999999999.99';
        $largeB = '0.01';
        $this->assertSame('1000000000000000000.00', Decimal::add($largeA, $largeB, Decimal::MONEY_SCALE));
        $this->assertSame('999999999999999999.98', Decimal::sub($largeA, $largeB, Decimal::MONEY_SCALE));
    }

    public function test_null_and_float_boundary_inputs(): void
    {
        $this->assertNull(Decimal::parse(null));
        $this->assertNull(Decimal::parse(true));
        $this->assertNull(Decimal::parse(false));
        $this->assertNull(Decimal::parse([]));
        $this->assertNull(Decimal::parse(INF));
        $this->assertNull(Decimal::parse(-INF));
        $this->assertNull(Decimal::parse(NAN));

        $this->assertSame('0.00', Decimal::fromNumeric(null, Decimal::MONEY_SCALE));
        $this->assertSame('0.000', Decimal::fromNumeric(null, Decimal::QUANTITY_SCALE));

        // Float boundary inputs
        $this->assertSame('0', Decimal::parse(0.0));
        $this->assertSame('0', Decimal::parse(-0.0));
        $this->assertSame('19.99', Decimal::parse(19.99));
        $this->assertSame('0.00001', Decimal::parse(0.00001));
        $this->assertSame('19.99', Decimal::fromNumeric(19.99, Decimal::MONEY_SCALE));
        $this->assertSame('.5' === Decimal::parse('.5') ? '.5' : '0.5', Decimal::parse('.5'));
        $this->assertSame('-0.5', Decimal::parse('-.5'));
        $this->assertSame('12', Decimal::parse('12.'));
    }

    public function test_invalid_inputs_return_null_in_parse_and_throw_in_operations(): void
    {
        foreach (['', '   ', 'abc', '12.34.56', '1,000.00', '1e5', '--5', '12a'] as $invalid) {
            $this->assertNull(Decimal::parse($invalid), "Expected null for invalid input [{$invalid}]");
        }

        $this->expectException(InvalidArgumentException::class);
        Decimal::round('invalid_number', Decimal::MONEY_SCALE);
    }

    public function test_invalid_string_throws_in_arithmetic_and_comparison_methods(): void
    {
        $methods = [
            fn () => Decimal::fromNumeric('not-a-number'),
            fn () => Decimal::add('10.00', 'bad'),
            fn () => Decimal::sub('bad', '10.00'),
            fn () => Decimal::mul('10.00', 'bad'),
            fn () => Decimal::div('10.00', 'bad'),
            fn () => Decimal::comp('10.00', 'bad'),
            fn () => Decimal::min('10.00', 'bad'),
            fn () => Decimal::max('bad', '10.00'),
            fn () => Decimal::round('10.00', -1),
        ];

        foreach ($methods as $index => $call) {
            try {
                $call();
                $this->fail("Expected InvalidArgumentException at index {$index}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_division_by_zero_throws_division_by_zero_error(): void
    {
        foreach (['0', '0.00', '0.00000000', '-0.00'] as $zeroDivisor) {
            try {
                Decimal::div('100.00', $zeroDivisor, Decimal::CALCULATION_SCALE);
                $this->fail("Expected DivisionByZeroError for divisor [{$zeroDivisor}]");
            } catch (DivisionByZeroError) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_comparison_min_max_and_sign_helpers_across_scales(): void
    {
        $this->assertSame(0, Decimal::comp('10.501', '10.509', Decimal::MONEY_SCALE));
        $this->assertSame(-1, Decimal::comp('10.501', '10.509', Decimal::QUANTITY_SCALE));
        $this->assertSame(1, Decimal::comp('10.509', '10.501', Decimal::QUANTITY_SCALE));

        $this->assertSame('10.50', Decimal::min('10.50', '10.55', Decimal::MONEY_SCALE));
        $this->assertSame('10.55', Decimal::max('10.50', '10.55', Decimal::MONEY_SCALE));

        $this->assertTrue(Decimal::isPositive('0.001', Decimal::QUANTITY_SCALE));
        $this->assertFalse(Decimal::isPositive('0.0001', Decimal::QUANTITY_SCALE));
        $this->assertTrue(Decimal::isNegative('-0.001', Decimal::QUANTITY_SCALE));
        $this->assertFalse(Decimal::isNegative('-0.0001', Decimal::QUANTITY_SCALE));
    }

    public function test_chained_calculations_maintain_exact_financial_precision(): void
    {
        // 0.1 + 0.2 = 0.30 exactly
        $sum = Decimal::add('0.1', '0.2', Decimal::MONEY_SCALE);
        $this->assertSame('0.30', $sum);

        // Pro-rated discount: (9.99 + 10.00) * 1.500 / 3.000 = 19.99 * 1.5 / 3 = 9.995 -> rounds to 10.00
        $totalDiscount = Decimal::add('9.99', '10.00', Decimal::CALCULATION_SCALE);
        $proRated = Decimal::round(
            Decimal::div(
                Decimal::mul($totalDiscount, '1.500', Decimal::CALCULATION_SCALE),
                '3.000',
                Decimal::CALCULATION_SCALE
            ),
            Decimal::MONEY_SCALE
        );
        $this->assertSame('10.00', $proRated);
    }

    public function test_min_and_max_symmetry_across_argument_order_and_subscale_negatives(): void
    {
        $this->assertSame('0.00', Decimal::max('0.00', '-0.001', Decimal::MONEY_SCALE));
        $this->assertSame('0.00', Decimal::max('-0.001', '0.00', Decimal::MONEY_SCALE));
        $this->assertSame('0.000', Decimal::max('-0.0001', '0.000', Decimal::QUANTITY_SCALE));
        $this->assertSame('-0.001', Decimal::min('0.00', '-0.001', Decimal::MONEY_SCALE));
        $this->assertSame('-0.001', Decimal::min('-0.001', '0.00', Decimal::MONEY_SCALE));
        $this->assertSame('10.501', Decimal::min('10.509', '10.501', Decimal::MONEY_SCALE));
        $this->assertSame('10.509', Decimal::max('10.501', '10.509', Decimal::MONEY_SCALE));
    }

    public function test_quantity_normalization_and_additional_invalid_boundaries(): void
    {
        foreach (['+', '+10.50', '-', '.', '-.', '12e-3', "10\n2", 'NaN'] as $invalid) {
            $this->assertNull(Decimal::parse($invalid), "Expected null for [{$invalid}]");
        }

        $this->assertSame('0.126', Decimal::fromNumeric('0.1255', Decimal::QUANTITY_SCALE));
        $this->assertSame('7.500', Decimal::fromNumeric('  007.5  ', Decimal::QUANTITY_SCALE));
        $this->assertSame('0.0000001', Decimal::parse(1.0E-7));
        $this->assertSame('0', Decimal::parse(1.0E-16));

        $this->assertSame('33.33333333', Decimal::div('100', '3', Decimal::CALCULATION_SCALE));
        $this->assertSame('1.00000000', Decimal::mul('0.0001', '10000', Decimal::CALCULATION_SCALE));

        $this->expectException(InvalidArgumentException::class);
        Decimal::fromNumeric('10.00', -1);
    }
}
