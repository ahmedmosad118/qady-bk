<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\InventoryDocumentType;
use App\Models\InventoryDocument;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class InventoryDocumentNumberService
{
    /**
     * Map document types to their 2-letter uppercase prefixes.
     *
     * @var array<string, string>
     */
    protected const TYPE_PREFIXES = [
        'goods_receipt' => 'GR',
        'goods_delivery' => 'GD',
        'warehouse_transfer' => 'WT',
        'stock_adjustment' => 'SA',
        'inventory_return' => 'IR',
        'stock_write_off' => 'SW',
        'opening_balance' => 'OB',
    ];

    /**
     * Generate the next unique document number atomically for the given type and date.
     */
    public function generate(InventoryDocumentType|string $type, ?Carbon $date = null): string
    {
        $typeValue = $type instanceof InventoryDocumentType ? $type->value : $type;
        $prefix = self::TYPE_PREFIXES[$typeValue] ?? 'DOC';
        $year = ($date ?? Carbon::now())->format('Y');

        $searchPrefix = "DOC-{$prefix}-{$year}-";

        // Query with lock inside transaction to protect against concurrent generation
        return DB::transaction(function () use ($searchPrefix): string {
            $lastNumber = InventoryDocument::query()
                ->where('document_number', 'like', "{$searchPrefix}%")
                ->lockForUpdate()
                ->orderByDesc('id')
                ->value('document_number');

            $nextSequence = 1;
            if ($lastNumber && preg_match('/(\d+)$/', $lastNumber, $matches)) {
                $nextSequence = (int) $matches[1] + 1;
            }

            return sprintf('%s%05d', $searchPrefix, $nextSequence);
        });
    }
}
