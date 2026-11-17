<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Pure BCMath helpers for monetary and quantity arithmetic.
 *
 * Rule: money and quantities are always handled as numeric strings.
 * Never cast them to float for calculation or comparison.
 */
final class Decimal
{
    public const MONEY_SCALE = 2;

    public const QUANTITY_SCALE = 3;

    public const CALCULATION_SCALE = 8;

    private function __construct() {}

    /**
     * Normalize user or scalar input into a canonical decimal string.
     * Returns null for anything that is not a plain decimal number (no exponent, no thousands separators).
     */
    public static function parse(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        // Boundary conversion only when a caller passes a PHP float scalar.
        if (is_float($value)) {
            return is_finite($value) ? number_format($value, self::CALCULATION_SCALE, '.', '') : null;
        }

        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        if (! preg_match('/^-?(\d+(\.\d*)?|\.\d+)$/', $normalized)) {
            return null;
        }

        // BCMath requires digits on both sides of the decimal point.
        $normalized = preg_replace('/^(-?)\./', '${1}0.', $normalized);

        return rtrim($normalized, '.');
    }

    /**
     * Normalize a numeric value coming from the database or validated input to a fixed scale.
     */
    public static function fromNumeric(mixed $value, int $scale = self::MONEY_SCALE): string
    {
        return self::round(self::parse($value ?? 0) ?? '0', $scale);
    }

    /**
     * Round half away from zero to the given scale using pure BCMath.
     */
    public static function round(string $value, int $scale = self::MONEY_SCALE): string
    {
        $offset = '0.' . str_repeat('0', $scale) . '5';

        return bccomp($value, '0', self::CALCULATION_SCALE) >= 0
            ? bcadd($value, $offset, $scale)
            : bcsub($value, $offset, $scale);
    }

    public static function add(string $a, string $b, int $scale = self::MONEY_SCALE): string
    {
        return bcadd($a, $b, $scale);
    }

    public static function sub(string $a, string $b, int $scale = self::MONEY_SCALE): string
    {
        return bcsub($a, $b, $scale);
    }

    public static function mul(string $a, string $b, int $scale = self::CALCULATION_SCALE): string
    {
        return bcmul($a, $b, $scale);
    }

    public static function div(string $a, string $b, int $scale = self::CALCULATION_SCALE): string
    {
        return bcdiv($a, $b, $scale);
    }

    public static function comp(string $a, string $b, int $scale = self::CALCULATION_SCALE): int
    {
        return bccomp($a, $b, $scale);
    }

    public static function isPositive(string $value, int $scale = self::CALCULATION_SCALE): bool
    {
        return bccomp($value, '0', $scale) > 0;
    }

    public static function isNegative(string $value, int $scale = self::CALCULATION_SCALE): bool
    {
        return bccomp($value, '0', $scale) < 0;
    }

    /**
     * Return the smaller of two decimal strings.
     */
    public static function min(string $a, string $b, int $scale = self::MONEY_SCALE): string
    {
        return bccomp($a, $b, $scale) > 0 ? $b : $a;
    }

    /**
     * Return the larger of two decimal strings.
     */
    public static function max(string $a, string $b, int $scale = self::MONEY_SCALE): string
    {
        return bccomp($a, $b, $scale) >= 0 ? $a : $b;
    }
}
