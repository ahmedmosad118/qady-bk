<?php

declare(strict_types=1);

namespace App\Services\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class DocumentSequenceGenerator
{
    /**
     * Atomically generate the next sequence code matching the given prefix.
     *
     * @param  class-string<Model>  $modelClass
     */
    public function next(string $modelClass, string $column, string $prefix, int $padLength = 5): string
    {
        return DB::transaction(function () use ($modelClass, $column, $prefix, $padLength): string {
            $query = $modelClass::query();
            if (method_exists($modelClass, 'withTrashed')) {
                $query = $modelClass::withTrashed();
            }

            $existing = $query
                ->where($column, 'like', "{$prefix}%")
                ->lockForUpdate()
                ->pluck($column);

            $max = 0;
            foreach ($existing as $number) {
                if (preg_match('/(\d+)$/', (string) $number, $matches)) {
                    $max = max($max, (int) $matches[1]);
                }
            }

            return sprintf('%s%0'.$padLength.'d', $prefix, $max + 1);
        });
    }
}
