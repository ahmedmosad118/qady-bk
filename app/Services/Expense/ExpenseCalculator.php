<?php

declare(strict_types=1);

namespace App\Services\Expense;

use App\Support\Decimal;
use Illuminate\Validation\ValidationException;

class ExpenseCalculator
{
    public function calculateTotals(array $rawItems): array
    {
        if (empty($rawItems)) {
            throw ValidationException::withMessages([
                'items' => [__('expenses.errors.at_least_one_item')],
            ]);
        }

        $subtotal = '0.00';
        $items = [];

        foreach ($rawItems as $index => $item) {
            $parsedAmount = Decimal::parse($item['amount'] ?? null);

            if ($parsedAmount === null) {
                throw ValidationException::withMessages([
                    "items.{$index}.amount" => [__('expenses.errors.item_amount_positive')],
                ]);
            }

            $amount = Decimal::round($parsedAmount, Decimal::MONEY_SCALE);

            $subtotal = Decimal::add(
                $subtotal,
                $amount,
                Decimal::MONEY_SCALE
            );

            $item['amount'] = $amount;
            $items[] = $item;
        }

        return [
            'items' => $items,
            'subtotal' => $subtotal,
            'total' => $subtotal,
        ];
    }
}