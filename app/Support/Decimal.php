<?php

declare(strict_types=1);

namespace App\Support;

use DivisionByZeroError;
use InvalidArgumentException;

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

    private const FLOAT_CONVERT_SCALE = 14;

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
            if (! is_finite($value)) {
                return null;
            }

            if ($value == 0.0) {
                return '0';
            }

            $str = (string) $value;
            if (! preg_match('/^-?\d+(\.\d+)?$/', $str)) {
                $str = rtrim(rtrim(sprintf('%.' . self::FLOAT_CONVERT_SCALE . 'F', $value), '0'), '.');
            }

            return $str === '-0' || $str === '' ? '0' : $str;
        }

        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        if (! preg_match('/^-?(\d+(\.\d*)?|\.\d+)$/', $normalized)) {
            return null;
        }

        // BCMath requires digits on both sides of the decimal point.
        $normalized = (string) preg_replace('/^(-?)\./', '${1}0.', $normalized);
        $normalized = rtrim($normalized, '.');

        if (str_starts_with($normalized, '-') && bccomp($normalized, '0', self::fractionalLength($normalized)) === 0) {
            return ltrim($normalized, '-');
        }

        return $normalized;
    }

    /**
     * Normalize a numeric value coming from the database or validated input to a fixed scale.
     */
    public static function fromNumeric(mixed $value, int $scale = self::MONEY_SCALE): string
    {
        self::assertScale($scale);

        if ($value === null) {
            return self::round('0', $scale);
        }

        $parsed = self::parse($value);
        if ($parsed === null) {
            throw new InvalidArgumentException('Invalid numeric value provided to Decimal::fromNumeric().');
        }

        return self::round($parsed, $scale);
    }

    /**
     * Round half away from zero to the given scale using pure BCMath.
     */
    public static function round(string $value, int $scale = self::MONEY_SCALE): string
    {
        self::assertScale($scale);
        $normalized = self::normalizeOrFail($value);

        $compScale = max($scale + 1, self::CALCULATION_SCALE, self::fractionalLength($normalized));
        $cmp = bccomp($normalized, '0', $compScale);

        if ($cmp === 0) {
            return $scale === 0 ? '0' : '0.' . str_repeat('0', $scale);
        }

        $offset = $scale > 0
            ? '0.' . str_repeat('0', $scale) . '5'
            : '0.5';

        $rounded = $cmp > 0
            ? bcadd($normalized, $offset, $scale)
            : bcsub($normalized, $offset, $scale);

        return self::normalizeZero($rounded, $scale);
    }

    public static function add(string $a, string $b, int $scale = self::MONEY_SCALE): string
    {
        self::assertScale($scale);

        return self::normalizeZero(
            bcadd(self::normalizeOrFail($a), self::normalizeOrFail($b), $scale),
            $scale
        );
    }

    public static function sub(string $a, string $b, int $scale = self::MONEY_SCALE): string
    {
        self::assertScale($scale);

        return self::normalizeZero(
            bcsub(self::normalizeOrFail($a), self::normalizeOrFail($b), $scale),
            $scale
        );
    }

    public static function mul(string $a, string $b, int $scale = self::CALCULATION_SCALE): string
    {
        self::assertScale($scale);

        return self::normalizeZero(
            bcmul(self::normalizeOrFail($a), self::normalizeOrFail($b), $scale),
            $scale
        );
    }

    public static function div(string $a, string $b, int $scale = self::CALCULATION_SCALE): string
    {
        self::assertScale($scale);
        $normA = self::normalizeOrFail($a);
        $normB = self::normalizeOrFail($b);

        $divisorScale = max($scale, self::CALCULATION_SCALE, self::fractionalLength($normB));
        if (bccomp($normB, '0', $divisorScale) === 0) {
            throw new DivisionByZeroError('Division by zero in Decimal::div().');
        }

        return self::normalizeZero(bcdiv($normA, $normB, $scale), $scale);
    }

    public static function comp(string $a, string $b, int $scale = self::CALCULATION_SCALE): int
    {
        self::assertScale($scale);

        return bccomp(self::normalizeOrFail($a), self::normalizeOrFail($b), $scale);
    }

    public static function isPositive(string $value, int $scale = self::CALCULATION_SCALE): bool
    {
        return self::comp($value, '0', $scale) > 0;
    }

    public static function isNegative(string $value, int $scale = self::CALCULATION_SCALE): bool
    {
        return self::comp($value, '0', $scale) < 0;
    }

    /**
     * Return the smaller of two decimal strings.
     */
    public static function min(string $a, string $b, int $scale = self::MONEY_SCALE): string
    {
        return self::comp($a, $b, $scale) > 0 ? self::normalizeOrFail($b) : self::normalizeOrFail($a);
    }

    /**
     * Return the larger of two decimal strings.
     */
    public static function max(string $a, string $b, int $scale = self::MONEY_SCALE): string
    {
        return self::comp($a, $b, $scale) >= 0 ? self::normalizeOrFail($a) : self::normalizeOrFail($b);
    }

    private static function normalizeOrFail(string $value): string
    {
        $parsed = self::parse($value);

        if ($parsed === null) {
            throw new InvalidArgumentException("Invalid decimal string [{$value}].");
        }

        return $parsed;
    }

    private static function assertScale(int $scale): void
    {
        if ($scale < 0) {
            throw new InvalidArgumentException("Decimal scale must be non-negative, {$scale} given.");
        }
    }

    private static function fractionalLength(string $normalized): int
    {
        $dotPos = strpos($normalized, '.');

        return $dotPos === false ? 0 : strlen($normalized) - $dotPos - 1;
    }

    private static function normalizeZero(string $result, int $scale): string
    {
        if (str_starts_with($result, '-') && bccomp($result, '0', $scale) === 0) {
            return ltrim($result, '-');
        }

        return $result;
    }
}
