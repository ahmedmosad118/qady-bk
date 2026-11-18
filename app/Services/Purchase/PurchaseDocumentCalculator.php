<?php

declare(strict_types=1);

namespace App\Services\Purchase;

use App\Enums\DiscountType;
use App\Models\Product;
use App\Support\Decimal;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Pure domain math shared by Purchase Orders and Purchase Invoices.
 *
 * Zero DB queries and zero side effects: callers load the products (one `whereIn`)
 * and pass them in. All money/quantity math goes through App\Support\Decimal (BCMath).
 *
 * Calculation order:
 *  1. Consolidate duplicate products by summing quantities.
 *  2. gross_line_total = quantity * unit_price            (rounded half-up, MONEY_SCALE)
 *  3. item discount (percentage|fixed) -> line_total = gross - discount
 *  4. subtotal = sum(gross_line_total) (gross, before any discount) -- same meaning for orders and invoices;
 *     net_lines_total = sum(line_total) is the base the document discount is applied on
 *  5. document discount applied on net_lines_total
 *  6. total = net_lines_total - document discount + additional expenses
 */
class PurchaseDocumentCalculator
{
    /**
     * Distinct, valid product IDs referenced by the raw items (for the caller's bulk load).
     *
     * @param array<int, array<string, mixed>> $rawItems
     * @return array<int, int>
     */
    public function productIds(array $rawItems): array
    {
        $ids = [];
        foreach ($rawItems as $item) {
            $id = (int) ($item['product_id'] ?? 0);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * @param array<int, array<string, mixed>> $rawItems
     * @param Collection<int, Product> $products Keyed by product id.
     * @param array<int, array<string, mixed>> $rawExpenses
     * @return array{
     *     items: array<int, array<string, mixed>>,
     *     expenses: array<int, array<string, mixed>>,
     *     subtotal: string,
     *     items_discount_total: string,
     *     net_lines_total: string,
     *     discount_value: string,
     *     discount_amount: string,
     *     additional_expenses_total: string,
     *     total: string
     * }
     */
    public function calculate(
        array $rawItems,
        Collection $products,
        ?string $discountType = null,
        mixed $discountValue = null,
        array $rawExpenses = [],
        string $unitName = 'piece',
    ): array {
        if ($rawItems === []) {
            $this->fail('items', 'items_required');
        }

        $consolidated = $this->consolidate($rawItems);

        $lines = [];
        $subtotal = '0.00';
        $itemsDiscountTotal = '0.00';
        $netLinesTotal = '0.00';

        foreach ($consolidated as $productId => $row) {
            /** @var Product|null $product */
            $product = $products->get($productId);
            if (! $product) {
                $this->fail('items', 'product_missing', ['id' => $productId]);
            }

            if (! $product->is_active) {
                $this->fail('items', 'product_inactive', ['name' => $product->name]);
            }

            $gross = Decimal::round(
                Decimal::mul($row['quantity'], $row['unit_price'], Decimal::CALCULATION_SCALE),
                Decimal::MONEY_SCALE
            );

            [$itemDiscValue, $itemDiscAmount] = $this->discount(
                $gross,
                $row['discount_type'],
                $row['discount_value'],
                "items.{$row['index']}.discount_value",
                'item_discount'
            );

            $lineTotal = Decimal::sub($gross, $itemDiscAmount);

            $subtotal = Decimal::add($subtotal, $gross);
            $itemsDiscountTotal = Decimal::add($itemsDiscountTotal, $itemDiscAmount);
            $netLinesTotal = Decimal::add($netLinesTotal, $lineTotal);

            $lines[] = [
                'product_id' => $product->id,
                'product_snapshot' => [
                    'name' => $product->name,
                    'barcode' => $product->barcode,
                    'unit_name' => $unitName,
                ],
                'quantity' => $row['quantity'],
                'unit_price' => $row['unit_price'],
                'discount_type' => $row['discount_type'],
                'discount_value' => $itemDiscValue,
                'discount_amount' => $itemDiscAmount,
                'line_total' => $lineTotal,
            ];
        }

        [$docDiscValue, $docDiscAmount] = $this->discount(
            $netLinesTotal,
            ! empty($discountType) ? $discountType : null,
            $discountValue,
            'discount_value',
            'document_discount'
        );

        [$expenses, $expensesTotal] = $this->expenses($rawExpenses);

        $total = Decimal::add(Decimal::sub($netLinesTotal, $docDiscAmount), $expensesTotal);

        if (Decimal::isNegative($total, Decimal::MONEY_SCALE)) {
            $this->fail('total', 'total_negative');
        }

        return [
            'items' => $lines,
            'expenses' => $expenses,
            'subtotal' => $subtotal,
            'items_discount_total' => $itemsDiscountTotal,
            'net_lines_total' => $netLinesTotal,
            'discount_value' => $docDiscValue,
            'discount_amount' => $docDiscAmount,
            'additional_expenses_total' => $expensesTotal,
            'total' => $total,
        ];
    }

    /**
     * Validate each raw line and merge duplicate products (quantities summed;
     * price and discount are taken from the first occurrence of the product).
     *
     * @param array<int, array<string, mixed>> $rawItems
     * @return array<int, array{index: int|string, quantity: string, unit_price: string, discount_type: ?string, discount_value: mixed}>
     */
    protected function consolidate(array $rawItems): array
    {
        $consolidated = [];

        foreach ($rawItems as $index => $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $rawQty = $item['quantity'] ?? null;
            $rawPrice = $item['unit_price'] ?? null;

            if ($productId <= 0 || $rawQty === null) {
                $this->fail("items.{$index}.quantity", 'invalid_product_or_quantity');
            }

            $qty = $this->parseScaled($rawQty, Decimal::QUANTITY_SCALE, "items.{$index}.quantity", 'quantity');
            if (! Decimal::isPositive($qty, Decimal::QUANTITY_SCALE)) {
                $this->fail("items.{$index}.quantity", 'quantity_positive');
            }

            if ($rawPrice === null) {
                $this->fail("items.{$index}.unit_price", 'unit_price_required');
            }

            $price = $this->parseScaled($rawPrice, Decimal::MONEY_SCALE, "items.{$index}.unit_price", 'unit_price');
            if (Decimal::isNegative($price, Decimal::MONEY_SCALE)) {
                $this->fail("items.{$index}.unit_price", 'unit_price_negative');
            }

            if (isset($consolidated[$productId])) {
                $consolidated[$productId]['quantity'] = Decimal::add(
                    $consolidated[$productId]['quantity'],
                    $qty,
                    Decimal::QUANTITY_SCALE
                );

                continue;
            }

            $consolidated[$productId] = [
                'index' => $index,
                'quantity' => $qty,
                'unit_price' => $price,
                'discount_type' => ! empty($item['discount_type']) ? (string) $item['discount_type'] : null,
                'discount_value' => isset($item['discount_value']) && $item['discount_value'] !== ''
                    ? $item['discount_value']
                    : null,
            ];
        }

        return $consolidated;
    }

    /**
     * @param array<int, array<string, mixed>> $rawExpenses
     * @return array{0: array<int, array<string, mixed>>, 1: string}
     */
    protected function expenses(array $rawExpenses): array
    {
        $processed = [];
        $total = '0.00';

        foreach ($rawExpenses as $index => $expense) {
            $name = trim((string) ($expense['name'] ?? ''));
            if ($name === '') {
                $this->fail("expenses.{$index}.name", 'expense_name_required');
            }

            $rawAmount = $expense['amount'] ?? null;
            if ($rawAmount === null) {
                $this->fail("expenses.{$index}.amount", 'expense_amount_required');
            }

            $amount = $this->parseScaled($rawAmount, Decimal::MONEY_SCALE, "expenses.{$index}.amount", 'expense_amount');
            if (Decimal::isNegative($amount, Decimal::MONEY_SCALE)) {
                $this->fail("expenses.{$index}.amount", 'expense_amount_negative');
            }

            $notes = isset($expense['notes']) ? trim((string) $expense['notes']) : '';

            $total = Decimal::add($total, $amount);
            $processed[] = [
                'name' => $name,
                'amount' => $amount,
                'notes' => $notes !== '' ? $notes : null,
            ];
        }

        return [$processed, $total];
    }

    /**
     * Validate and compute a percentage or fixed discount against an applicable base.
     *
     * @return array{0: string, 1: string} [normalized_discount_value, discount_amount]
     */
    protected function discount(
        string $base,
        ?string $type,
        mixed $value,
        string $errorKey,
        string $label,
    ): array {
        $hasValue = $value !== null && $value !== '';
        $valueStr = $hasValue
            ? $this->parseScaled($value, Decimal::MONEY_SCALE, $errorKey, $label)
            : '0.00';

        if (Decimal::isNegative($valueStr, Decimal::MONEY_SCALE)) {
            $this->fail($errorKey, 'discount_negative', ['label' => $this->label($label)]);
        }

        if ($type === null) {
            if (Decimal::isPositive($valueStr, Decimal::MONEY_SCALE)) {
                $this->fail($errorKey, 'discount_type_required', ['label' => $this->label($label)]);
            }

            return ['0.00', '0.00'];
        }

        if ($type === DiscountType::PERCENTAGE->value) {
            if (Decimal::comp($valueStr, '100', Decimal::MONEY_SCALE) > 0) {
                $this->fail($errorKey, 'discount_percentage_max', ['label' => $this->label($label)]);
            }

            $amount = Decimal::round(
                Decimal::div(
                    Decimal::mul($base, $valueStr, Decimal::CALCULATION_SCALE),
                    '100',
                    Decimal::CALCULATION_SCALE
                ),
                Decimal::MONEY_SCALE
            );

            return [$valueStr, $amount];
        }

        if ($type === DiscountType::FIXED->value) {
            if (Decimal::comp($valueStr, $base, Decimal::MONEY_SCALE) > 0) {
                $this->fail($errorKey, 'discount_exceeds_base', ['label' => $this->label($label)]);
            }

            return [$valueStr, $valueStr];
        }

        $this->fail($errorKey, 'discount_type_invalid', ['label' => $this->label($label)]);
    }

    /**
     * Parse numeric input into a fixed-scale decimal string without float casting.
     *
     * Rejects non-numeric input and values with more fractional digits than the column
     * scale allows, instead of silently rounding what the user typed.
     */
    protected function parseScaled(mixed $raw, int $scale, string $errorKey, string $label): string
    {
        $parsed = Decimal::parse($raw);

        if ($parsed === null) {
            $this->fail($errorKey, 'number_invalid', ['label' => $this->label($label)]);
        }

        $dot = strpos($parsed, '.');
        $fraction = $dot === false ? '' : rtrim(substr($parsed, $dot + 1), '0');

        if (strlen($fraction) > $scale) {
            $this->fail($errorKey, 'too_many_decimals', ['label' => $this->label($label), 'scale' => $scale]);
        }

        return Decimal::fromNumeric($parsed, $scale);
    }

    protected function label(string $key): string
    {
        return (string) __("purchases.labels.{$key}");
    }

    /**
     * @param array<string, scalar> $replace
     * @return never
     */
    protected function fail(string $field, string $messageKey, array $replace = []): never
    {
        throw ValidationException::withMessages([
            $field => [(string) __("purchases.errors.{$messageKey}", $replace)],
        ]);
    }
}
