<?php

declare(strict_types=1);

namespace App\Services\Product;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

class ProductBarcodeLabelService
{
    public function __construct(
        protected ProductBarcodeGenerator $generator,
    ) {}

    /**
     * Prepare label data for printing. Products without a barcode get an
     * internal EAN-13 generated and persisted in the same transaction.
     *
     * @param  list<array{product_id: int, copies: int}>  $items
     * @return list<array<string, mixed>>
     */
    public function prepare(array $items): array
    {
        $copies = [];
        foreach ($items as $item) {
            $id = (int) $item['product_id'];
            $copies[$id] = ($copies[$id] ?? 0) + (int) $item['copies'];
        }

        return DB::transaction(function () use ($copies) {
            $products = Product::query()
                ->whereIn('id', array_keys($copies))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $missing = $products->filter(fn (Product $p) => $p->barcode === null || trim($p->barcode) === '');
            $generated = [];

            if ($missing->isNotEmpty()) {
                $codes = $this->generator->next($missing->count());
                $cases = [];
                $bindings = [];
                $missingIds = [];

                foreach ($missing->values() as $i => $product) {
                    $cases[] = 'WHEN ? THEN ?';
                    $bindings[] = (int) $product->id;
                    $bindings[] = $codes[$i];
                    $missingIds[] = (int) $product->id;
                    $product->barcode = $codes[$i];
                    $generated[$product->id] = true;
                }

                $bindings[] = now()->toDateTimeString();
                foreach ($missingIds as $id) {
                    $bindings[] = $id;
                }

                $inPlaceholders = implode(', ', array_fill(0, count($missingIds), '?'));
                $caseSql = 'CASE id '.implode(' ', $cases).' END';

                DB::update(
                    "UPDATE products SET barcode = {$caseSql}, updated_at = ? WHERE id IN ({$inPlaceholders})",
                    $bindings
                );
            }

            $labels = [];
            foreach ($copies as $id => $count) {
                $product = $products->get($id);
                if (! $product) {
                    continue;
                }

                $labels[] = [
                    'product_id' => $product->id,
                    'name' => $product->name,
                    'barcode' => $product->barcode,
                    'selling_price' => $product->selling_price,
                    'author' => $product->author,
                    'publisher' => $product->publisher,
                    'copies' => $count,
                    'barcode_generated' => isset($generated[$id]),
                ];
            }

            return $labels;
        });
    }
}
