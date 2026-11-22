<?php

declare(strict_types=1);

namespace App\Services\Expense;

use App\Support\Decimal;
use Illuminate\Validation\ValidationException;

/**
 * Pure BCMath calculator for expense line items and totals.
 * Zero DB queries, zero side effects.
 */
class ExpenseCalculator
{
    /**
     * Validate items and calculate subtotal and total authoritatively on the backend.
     *
     * @param  array<int, array<string, mixed>>  $rawItems
     * @return array{
     *     items: array<int, array<string, mixed>>,
     *     subtotal: string,
     *     total: string
     * }
     */
    public function calculateTotals(array $rawItems): array
    {
        if (empty($rawItems)) {
            throw ValidationException::withMessages([
                'items' => [__('expenses.errors.at_least_one_item')],
            ]);
        }

        $processedItems = [];
        $subtotal = '0.00';

        foreach ($rawItems as $index => $item) {
            $name = trim((string) ($item['name'] ?? ''));
            if ($name === '') {
                throw ValidationException::withMessages([
                    "items.{$index}.name" => ['Item name is required.'],
                ]);
            }

            $parsedAmount = Decimal::parse($item['amount'] ?? null);
            $amountStr = $parsedAmount !== null ? Decimal::round($parsedAmount, Decimal::MONEY_SCALE) : null;
            if ($amountStr === null || ! Decimal::isPositive($amountStr, Decimal::MONEY_SCALE)) {
                throw ValidationException::withMessages([
                    "items.{$index}.amount" => [__('expenses.errors.item_amount_positive')],
                ]);
            }

            $description = isset($item['description']) && trim((string) $item['description']) !== ''
                ? trim((string) $item['description'])
                : null;

            $subtotal = Decimal::add($subtotal, $amountStr, Decimal::MONEY_SCALE);

            $processedItems[] = [
                'id' => isset($item['id']) && $item['id'] !== '' ? (int) $item['id'] : null,
                'name' => $name,
                'description' => $description,
                'amount' => $amountStr,
                'attachments' => is_array($item['attachments'] ?? null) ? $item['attachments'] : [],
                'remove_attachment_ids' => is_array($item['remove_attachment_ids'] ?? null)
                    ? array_values(array_unique(array_map('intval', $item['remove_attachment_ids'])))
                    : [],
            ];
        }

        if (! Decimal::isPositive($subtotal, Decimal::MONEY_SCALE)) {
            throw ValidationException::withMessages([
                'total' => [__('expenses.errors.total_positive')],
            ]);
        }

        return [
            'items' => $processedItems,
            'subtotal' => $subtotal,
            'total' => $subtotal,
        ];
    }
}
