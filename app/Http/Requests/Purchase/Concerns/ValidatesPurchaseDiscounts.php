<?php

declare(strict_types=1);

namespace App\Http\Requests\Purchase\Concerns;

use App\Enums\DiscountType;
use App\Support\Decimal;
use Illuminate\Validation\Validator;

/**
 * Early discount sanity checks shared by every purchase order / invoice request
 * (the calculator re-validates authoritatively; this gives field-level errors up front).
 * Comparisons use App\Support\Decimal, never float.
 */
trait ValidatesPurchaseDiscounts
{
    /**
     * @param  bool  $requireTypeWithValue  Also require a discount type whenever a positive value is sent.
     */
    protected function validatePurchaseDiscounts(Validator $validator, bool $requireTypeWithValue): void
    {
        $this->checkDiscount(
            $validator,
            $this->input('discount_type'),
            $this->input('discount_value'),
            'discount_type',
            'discount_value',
            'document_discount',
            $requireTypeWithValue
        );

        $items = $this->input('items', []);
        if (! is_array($items)) {
            return;
        }

        foreach ($items as $idx => $item) {
            if (! is_array($item)) {
                continue;
            }

            $this->checkDiscount(
                $validator,
                $item['discount_type'] ?? null,
                $item['discount_value'] ?? null,
                "items.{$idx}.discount_type",
                "items.{$idx}.discount_value",
                'item_discount',
                $requireTypeWithValue
            );
        }
    }

    private function checkDiscount(
        Validator $validator,
        mixed $type,
        mixed $value,
        string $typeKey,
        string $valueKey,
        string $labelKey,
        bool $requireTypeWithValue,
    ): void {
        if ($value === null || $value === '') {
            return;
        }

        $parsed = Decimal::parse($value);
        if ($parsed === null) {
            return; // not numeric: the `numeric` rule already reported it
        }

        $label = (string) __("purchases.labels.{$labelKey}");

        if ($type === DiscountType::PERCENTAGE->value && Decimal::comp($parsed, '100') > 0) {
            $validator->errors()->add($valueKey, (string) __('purchases.errors.discount_percentage_max', ['label' => $label]));
        }

        if ($requireTypeWithValue && $type === null && Decimal::isPositive($parsed)) {
            $validator->errors()->add($typeKey, (string) __('purchases.errors.discount_type_required', ['label' => $label]));
        }
    }

    /**
     * Shared validation rules for optional purchase additional expenses.
     *
     * @return array<string, mixed>
     */
    protected function purchaseExpensesRules(): array
    {
        return [
            'expenses' => ['nullable', 'array'],
            'expenses.*.name' => ['required', 'string', 'max:255'],
            'expenses.*.amount' => ['required', 'numeric', 'min:0'],
            'expenses.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Shared validation rules for optional purchase document attachment upload/removal.
     *
     * @return array<string, mixed>
     */
    protected function purchaseAttachmentRules(): array
    {
        return [
            'attachment' => ['nullable', 'file', 'mimes:pdf,png,jpg,jpeg,webp,doc,docx', 'max:10240'],
            'remove_attachment' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Normalize empty discount_type strings (e.g. from multipart/form-data submissions) to null.
     */
    protected function normalizeEmptyDiscountTypes(): void
    {
        $merge = [];

        if ($this->has('discount_type') && $this->input('discount_type') === '') {
            $merge['discount_type'] = null;
        }

        if (is_array($this->input('items'))) {
            $items = $this->input('items');
            foreach ($items as $idx => $item) {
                if (is_array($item) && array_key_exists('discount_type', $item) && $item['discount_type'] === '') {
                    $items[$idx]['discount_type'] = null;
                }
            }
            $merge['items'] = $items;
        }

        if (! empty($merge)) {
            $this->merge($merge);
        }
    }

    /**
     * Localized attribute names shared by all purchase requests (extra keys are harmless).
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return (array) __('purchases.attributes');
    }
}
