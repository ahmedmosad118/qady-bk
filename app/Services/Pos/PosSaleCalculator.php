<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Enums\DiscountType;
use App\Models\Product;
use App\Support\Decimal;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Pure POS invoice math. No database access, no side effects.
 *
 * Input is the raw request payload; output is a fully computed invoice
 * (line items + totals) ready to be persisted by PosSaleService.
 * All arithmetic is BCMath on numeric strings (see App\Support\Decimal).
 */
class PosSaleCalculator
{
    private const ITEM_SCOPE = 'item';

    private const INVOICE_SCOPE = 'invoice';

    /**
     * Validate raw items and merge duplicate product lines into one line per product.
     * The first occurrence of a product keeps its price/discount fields; quantities are summed.
     *
     * @param array<int, mixed> $rawItems
     * @return array<int, array<string, mixed>> keyed by product_id
     */
    public function consolidateItems(array $rawItems): array
    {
        if ($rawItems === []) {
            throw $this->itemsError(__('pos.errors.items_required'));
        }

        $consolidated = [];

        foreach ($rawItems as $raw) {
            $productId = is_array($raw) && is_numeric($raw['product_id'] ?? null) ? (int) $raw['product_id'] : 0;

            if ($productId <= 0) {
                throw $this->itemsError(__('pos.errors.invalid_product_id'));
            }

            $quantity = $this->parseQuantity($raw['quantity'] ?? null);

            if (isset($consolidated[$productId])) {
                $consolidated[$productId]['quantity'] = Decimal::add(
                    $consolidated[$productId]['quantity'],
                    $quantity,
                    Decimal::QUANTITY_SCALE
                );

                continue;
            }

            $consolidated[$productId] = [
                'product_id'     => $productId,
                'quantity'       => $quantity,
                'unit_price'     => $raw['unit_price'] ?? null,
                'discount_type'  => $raw['discount_type'] ?? null,
                'discount_value' => $raw['discount_value'] ?? null,
            ];
        }

        return $consolidated;
    }

    /**
     * Compute line items, invoice discount, its allocation over lines and the grand total.
     *
     * @param array<int, array<string, mixed>> $consolidatedItems output of consolidateItems()
     * @param Collection<int, Product> $products keyed by product id
     * @return array{
     *     items: array<int, array<string, mixed>>,
     *     subtotal: string,
     *     items_discount_total: string,
     *     invoice_discount_type: ?string,
     *     invoice_discount_value: ?string,
     *     invoice_discount_amount: string,
     *     grand_total: string
     * }
     */
    public function calculate(
        array $consolidatedItems,
        Collection $products,
        mixed $invoiceDiscountType = null,
        mixed $invoiceDiscountValue = null
    ): array {
        $items = [];
        $subtotal = '0.00';
        $itemsDiscountTotal = '0.00';
        $netItemsTotal = '0.00';

        foreach ($consolidatedItems as $productId => $itemData) {
            /** @var Product|null $product */
            $product = $products->get($productId);

            if ($product === null) {
                throw $this->itemsError(__('pos.errors.product_not_found', ['id' => $productId]));
            }

            $line = $this->calculateLine($itemData, $product);

            $subtotal           = Decimal::add($subtotal, $line['subtotal'], Decimal::MONEY_SCALE);
            $itemsDiscountTotal = Decimal::add($itemsDiscountTotal, $line['discount_amount'], Decimal::MONEY_SCALE);
            $netItemsTotal      = Decimal::add($netItemsTotal, $line['total'], Decimal::MONEY_SCALE);

            $items[] = $line;
        }

        $invoiceDiscount = $this->calculateDiscount(
            $netItemsTotal,
            $invoiceDiscountType,
            $invoiceDiscountValue,
            self::INVOICE_SCOPE,
            'invoice_discount_type',
            'invoice_discount_value'
        );

        return [
            'items'                   => $this->allocateInvoiceDiscount($items, $netItemsTotal, $invoiceDiscount['amount']),
            'subtotal'                => $subtotal,
            'items_discount_total'    => $itemsDiscountTotal,
            'invoice_discount_type'   => $invoiceDiscount['type'],
            'invoice_discount_value'  => $invoiceDiscount['value'],
            'invoice_discount_amount' => $invoiceDiscount['amount'],
            'grand_total'             => Decimal::sub($netItemsTotal, $invoiceDiscount['amount'], Decimal::MONEY_SCALE),
        ];
    }

    /**
     * @param array<string, mixed> $itemData
     * @return array<string, mixed>
     */
    protected function calculateLine(array $itemData, Product $product): array
    {
        $quantity = (string) $itemData['quantity'];
        $originalPrice = Decimal::round((string) $product->selling_price, Decimal::MONEY_SCALE);
        $unitPrice = $this->resolveUnitPrice($itemData['unit_price'] ?? null, $originalPrice, $product);

        $lineSubtotal = Decimal::round(
            Decimal::mul($quantity, $unitPrice, Decimal::CALCULATION_SCALE),
            Decimal::MONEY_SCALE
        );

        $discount = $this->calculateDiscount(
            $lineSubtotal,
            $itemData['discount_type'] ?? null,
            $itemData['discount_value'] ?? null,
            self::ITEM_SCOPE,
            'items',
            'items'
        );

        return [
            'product_id'          => $product->id,
            'product_snapshot'    => $this->buildProductSnapshot($product),
            'quantity'            => $quantity,
            'original_unit_price' => $originalPrice,
            'unit_price'          => $unitPrice,
            'discount_type'       => $discount['type'],
            'discount_value'      => $discount['value'],
            'discount_amount'     => $discount['amount'],
            'subtotal'            => $lineSubtotal,
            'total'               => Decimal::sub($lineSubtotal, $discount['amount'], Decimal::MONEY_SCALE),
        ];
    }

    /**
     * Historical snapshot stored on the sale line so later product edits never alter old invoices.
     *
     * @return array{name: string, sku: ?string, barcode: ?string, unit_name: string}
     */
    public function buildProductSnapshot(Product $product): array
    {
        // Read raw attributes to stay safe for columns that may not exist (sku, unit_name).
        $attributes = $product->getAttributes();

        return [
            'name'      => (string) $product->name,
            'sku'       => isset($attributes['sku']) ? (string) $attributes['sku'] : null,
            'barcode'   => $product->barcode !== null ? (string) $product->barcode : null,
            'unit_name' => (string) ($attributes['unit_name'] ?? __('pos.unit_piece')),
        ];
    }

    /**
     * Spread the invoice discount over lines proportionally to their totals.
     * The last line absorbs the rounding remainder so the allocations sum exactly to the discount.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    public function allocateInvoiceDiscount(array $items, string $netItemsTotal, string $invoiceDiscountAmount): array
    {
        $canAllocate = Decimal::isPositive($netItemsTotal, Decimal::MONEY_SCALE)
            && Decimal::isPositive($invoiceDiscountAmount, Decimal::MONEY_SCALE);

        $allocatedTotal = '0.00';
        $lastIndex = array_key_last($items);

        foreach ($items as $index => $item) {
            if ($index === $lastIndex) {
                $items[$index]['allocated_invoice_discount'] = Decimal::sub(
                    $invoiceDiscountAmount,
                    $allocatedTotal,
                    Decimal::MONEY_SCALE
                );

                break;
            }

            $allocated = $canAllocate
                ? Decimal::round(
                    Decimal::div(
                        Decimal::mul($invoiceDiscountAmount, (string) $item['total'], Decimal::CALCULATION_SCALE),
                        $netItemsTotal,
                        Decimal::CALCULATION_SCALE
                    ),
                    Decimal::MONEY_SCALE
                )
                : '0.00';

            $allocatedTotal = Decimal::add($allocatedTotal, $allocated, Decimal::MONEY_SCALE);
            $items[$index]['allocated_invoice_discount'] = $allocated;
        }

        return $items;
    }

    /**
     * Shared discount rules for line and invoice discounts.
     * Translation keys follow the pattern pos.errors.{invalid_|negative_}{scope}_discount... (see lang/{ar,en}/pos.php).
     *
     * @return array{type: ?string, value: ?string, amount: string}
     */
    protected function calculateDiscount(
        string $baseAmount,
        mixed $rawType,
        mixed $rawValue,
        string $scope,
        string $typeField,
        string $valueField
    ): array {
        $isBlankType = $rawType === null || (is_string($rawType) && trim($rawType) === '');
        $type = $isBlankType ? null : (is_string($rawType) ? trim($rawType) : null);
        $discountType = $type !== null ? DiscountType::tryFrom($type) : null;

        if (! $isBlankType && $discountType === null) {
            throw ValidationException::withMessages([
                $typeField => [__("pos.errors.invalid_{$scope}_discount_type")],
            ]);
        }

        if ($rawValue === null || $rawValue === '') {
            return ['type' => $type, 'value' => null, 'amount' => '0.00'];
        }

        $value = Decimal::parse($rawValue);

        if ($value === null) {
            throw ValidationException::withMessages([
                $valueField => [__("pos.errors.invalid_{$scope}_discount_value")],
            ]);
        }

        if (Decimal::isNegative($value)) {
            throw ValidationException::withMessages([
                $valueField => [__("pos.errors.negative_{$scope}_discount")],
            ]);
        }

        $amount = match ($discountType) {
            DiscountType::PERCENTAGE => $this->percentageAmount($baseAmount, $value, $scope, $valueField),
            DiscountType::FIXED => Decimal::round($value, Decimal::MONEY_SCALE),
            null => '0.00',
        };

        return [
            'type' => $type,
            'value' => Decimal::round($value, Decimal::MONEY_SCALE),
            'amount' => Decimal::min($amount, $baseAmount),
        ];
    }

    protected function percentageAmount(string $baseAmount, string $percentage, string $scope, string $valueField): string
    {
        if (Decimal::comp($percentage, '100', Decimal::CALCULATION_SCALE) > 0) {
            throw ValidationException::withMessages([
                $valueField => [__("pos.errors.{$scope}_discount_percentage_range")],
            ]);
        }

        return Decimal::round(
            Decimal::div(Decimal::mul($baseAmount, $percentage, Decimal::CALCULATION_SCALE), '100', Decimal::CALCULATION_SCALE),
            Decimal::MONEY_SCALE
        );
    }

    protected function resolveUnitPrice(mixed $rawUnitPrice, string $originalPrice, Product $product): string
    {
        if ($rawUnitPrice === null || $rawUnitPrice === '') {
            return $originalPrice;
        }

        $unitPrice = Decimal::parse($rawUnitPrice);

        if ($unitPrice === null) {
            throw $this->itemsError(__('pos.errors.invalid_unit_price'));
        }

        if (Decimal::isNegative($unitPrice)) {
            throw $this->itemsError(__('pos.errors.negative_unit_price', ['product' => $product->name]));
        }

        return Decimal::round($unitPrice, Decimal::MONEY_SCALE);
    }

    protected function parseQuantity(mixed $rawQuantity): string
    {
        $parsed = Decimal::parse($rawQuantity);
        $quantity = $parsed !== null ? Decimal::round($parsed, Decimal::QUANTITY_SCALE) : null;

        if ($quantity === null || ! Decimal::isPositive($quantity, Decimal::QUANTITY_SCALE)) {
            throw $this->itemsError(__('pos.errors.invalid_item_quantity'));
        }

        return $quantity;
    }

    protected function itemsError(string $message): ValidationException
    {
        return ValidationException::withMessages(['items' => [$message]]);
    }
}
