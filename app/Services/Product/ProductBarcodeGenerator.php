<?php

declare(strict_types=1);

namespace App\Services\Product;

use App\Models\Product;

/**
 * Generates internal EAN-13 barcodes.
 *
 * Internal (in-store) codes use the GS1 restricted-distribution prefix `200`
 * so they never collide with real manufacturer ISBN/EAN codes:
 * `200` + 9-digit running sequence + 1 check digit.
 */
class ProductBarcodeGenerator
{
    public const PREFIX = '200';

    private const SEQUENCE_LENGTH = 9;

    private const MAX_SEQUENCE = 999_999_999;

    /**
     * Build the next $count free internal barcodes.
     *
     * Must run inside a transaction: it locks the highest existing internal
     * barcode so concurrent generators cannot receive the same sequence.
     *
     * @return list<string>
     */
    public function next(int $count): array
    {
        if ($count < 1) {
            return [];
        }

        $last = Product::withTrashed()
            ->where('barcode', 'like', self::PREFIX.str_repeat('_', self::SEQUENCE_LENGTH + 1))
            ->orderByDesc('barcode')
            ->lockForUpdate()
            ->value('barcode');

        $sequence = $last !== null && ctype_digit((string) $last)
            ? (int) substr((string) $last, strlen(self::PREFIX), self::SEQUENCE_LENGTH)
            : 0;

        if ($sequence + $count > self::MAX_SEQUENCE) {
            throw new \RuntimeException('Internal barcode sequence exhausted.');
        }

        $barcodes = [];
        for ($i = 1; $i <= $count; $i++) {
            $barcodes[] = $this->fromSequence($sequence + $i);
        }

        return $barcodes;
    }

    public function fromSequence(int $sequence): string
    {
        $body = self::PREFIX.str_pad((string) $sequence, self::SEQUENCE_LENGTH, '0', STR_PAD_LEFT);

        return $body.$this->checkDigit($body);
    }

    /**
     * EAN-13 check digit for a 12-digit body.
     */
    public function checkDigit(string $body): int
    {
        $sum = 0;
        foreach (str_split($body) as $i => $digit) {
            $sum += (int) $digit * ($i % 2 === 0 ? 1 : 3);
        }

        return (10 - ($sum % 10)) % 10;
    }
}
