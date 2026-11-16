<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Enums\InventoryDocumentType;
use App\Enums\InventoryReturnDirection;
use App\Enums\StockAdjustmentDirection;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreInventoryDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'document_type' => ['required', Rule::enum(InventoryDocumentType::class)],
            'document_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'write_off_reason' => ['nullable', 'string', 'max:2000'],
            'source_warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'destination_warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'adjustment_direction' => ['nullable', Rule::enum(StockAdjustmentDirection::class)],
            'return_direction' => ['nullable', Rule::enum(InventoryReturnDirection::class)],
            'referenceable_type' => ['nullable', 'string', 'max:255'],
            'referenceable_id' => ['nullable', 'integer'],

            // Items
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id', 'distinct'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Custom business validation for inventory document types and warehouse rules.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $docType = $this->input('document_type');
            $sourceId = $this->input('source_warehouse_id') ? (int) $this->input('source_warehouse_id') : null;
            $destId = $this->input('destination_warehouse_id') ? (int) $this->input('destination_warehouse_id') : null;
            $adjDirection = $this->input('adjustment_direction');
            $retDirection = $this->input('return_direction');
            $writeOffReason = trim((string) $this->input('write_off_reason'));

            // Check active status for source warehouse if provided
            if ($sourceId) {
                $source = Warehouse::query()->find($sourceId);
                if ($source && ! $source->is_active) {
                    $v->errors()->add('source_warehouse_id', __('inventory.errors.source_warehouse_inactive'));
                }
            }

            // Check active status for destination warehouse if provided
            if ($destId) {
                $dest = Warehouse::query()->find($destId);
                if ($dest && ! $dest->is_active) {
                    $v->errors()->add('destination_warehouse_id', __('inventory.errors.destination_warehouse_inactive'));
                }
            }

            // Check each document type specific rules
            switch ($docType) {
                case InventoryDocumentType::GOODS_RECEIPT->value:
                case InventoryDocumentType::OPENING_BALANCE->value:
                    if (! $destId) {
                        $v->errors()->add('destination_warehouse_id', __('inventory.errors.destination_warehouse_required'));
                    }
                    if ($sourceId) {
                        $v->errors()->add('source_warehouse_id', __('inventory.errors.source_warehouse_forbidden'));
                    }
                    if ($adjDirection) {
                        $v->errors()->add('adjustment_direction', __('inventory.errors.adjustment_direction_forbidden'));
                    }
                    if ($retDirection) {
                        $v->errors()->add('return_direction', __('inventory.errors.return_direction_forbidden'));
                    }
                    break;

                case InventoryDocumentType::GOODS_DELIVERY->value:
                    if (! $sourceId) {
                        $v->errors()->add('source_warehouse_id', __('inventory.errors.source_warehouse_required'));
                    }
                    if ($destId) {
                        $v->errors()->add('destination_warehouse_id', __('inventory.errors.destination_warehouse_forbidden'));
                    }
                    if ($adjDirection) {
                        $v->errors()->add('adjustment_direction', __('inventory.errors.adjustment_direction_forbidden'));
                    }
                    if ($retDirection) {
                        $v->errors()->add('return_direction', __('inventory.errors.return_direction_forbidden'));
                    }
                    break;

                case InventoryDocumentType::WAREHOUSE_TRANSFER->value:
                    if (! $sourceId) {
                        $v->errors()->add('source_warehouse_id', __('inventory.errors.source_warehouse_required'));
                    }
                    if (! $destId) {
                        $v->errors()->add('destination_warehouse_id', __('inventory.errors.destination_warehouse_required'));
                    }
                    if ($sourceId && $destId && $sourceId === $destId) {
                        $v->errors()->add('destination_warehouse_id', __('inventory.errors.transfer_warehouses_must_differ'));
                    }
                    if ($adjDirection) {
                        $v->errors()->add('adjustment_direction', __('inventory.errors.adjustment_direction_forbidden'));
                    }
                    if ($retDirection) {
                        $v->errors()->add('return_direction', __('inventory.errors.return_direction_forbidden'));
                    }
                    break;

                case InventoryDocumentType::STOCK_ADJUSTMENT->value:
                    if (! $adjDirection) {
                        $v->errors()->add('adjustment_direction', __('inventory.errors.adjustment_direction_required'));
                    }
                    if ($retDirection) {
                        $v->errors()->add('return_direction', __('inventory.errors.return_direction_forbidden'));
                    }
                    if ($adjDirection === StockAdjustmentDirection::INCREASE->value) {
                        if (! $destId) {
                            $v->errors()->add('destination_warehouse_id', __('inventory.errors.destination_warehouse_required'));
                        }
                        if ($sourceId) {
                            $v->errors()->add('source_warehouse_id', __('inventory.errors.source_warehouse_forbidden'));
                        }
                    } elseif ($adjDirection === StockAdjustmentDirection::DECREASE->value) {
                        if (! $sourceId) {
                            $v->errors()->add('source_warehouse_id', __('inventory.errors.source_warehouse_required'));
                        }
                        if ($destId) {
                            $v->errors()->add('destination_warehouse_id', __('inventory.errors.destination_warehouse_forbidden'));
                        }
                    }
                    break;

                case InventoryDocumentType::INVENTORY_RETURN->value:
                    if (! $retDirection) {
                        $v->errors()->add('return_direction', __('inventory.errors.return_direction_required'));
                    }
                    if ($adjDirection) {
                        $v->errors()->add('adjustment_direction', __('inventory.errors.adjustment_direction_forbidden'));
                    }
                    if ($retDirection === InventoryReturnDirection::INBOUND->value) {
                        if (! $destId) {
                            $v->errors()->add('destination_warehouse_id', __('inventory.errors.destination_warehouse_required'));
                        }
                        if ($sourceId) {
                            $v->errors()->add('source_warehouse_id', __('inventory.errors.source_warehouse_forbidden'));
                        }
                    } elseif ($retDirection === InventoryReturnDirection::OUTBOUND->value) {
                        if (! $sourceId) {
                            $v->errors()->add('source_warehouse_id', __('inventory.errors.source_warehouse_required'));
                        }
                        if ($destId) {
                            $v->errors()->add('destination_warehouse_id', __('inventory.errors.destination_warehouse_forbidden'));
                        }
                    }
                    break;

                case InventoryDocumentType::STOCK_WRITE_OFF->value:
                    if (! $sourceId) {
                        $v->errors()->add('source_warehouse_id', __('inventory.errors.source_warehouse_required'));
                    }
                    if ($destId) {
                        $v->errors()->add('destination_warehouse_id', __('inventory.errors.destination_warehouse_forbidden'));
                    }
                    if ($writeOffReason === '') {
                        $v->errors()->add('write_off_reason', __('inventory.errors.write_off_reason_required'));
                    }
                    if ($adjDirection) {
                        $v->errors()->add('adjustment_direction', __('inventory.errors.adjustment_direction_forbidden'));
                    }
                    if ($retDirection) {
                        $v->errors()->add('return_direction', __('inventory.errors.return_direction_forbidden'));
                    }
                    break;
            }

            // Verify active status of items
            $items = $this->input('items', []);
            if (is_array($items)) {
                $productIds = array_filter(array_column($items, 'product_id'));
                if (! empty($productIds)) {
                    $inactiveCount = Product::query()
                        ->whereIn('id', $productIds)
                        ->where('is_active', false)
                        ->count();

                    if ($inactiveCount > 0) {
                        $v->errors()->add('items', __('inventory.errors.contains_inactive_products'));
                    }
                }
            }
        });
    }
}
