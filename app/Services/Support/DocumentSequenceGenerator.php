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
            $lastNumber = $modelClass::query()
                ->where($column, 'like', "{$prefix}%")
                ->lockForUpdate()
                ->orderByDesc('id')
                ->value($column);

            $next = 1;
            if ($lastNumber && preg_match('/(\d+)$/', (string) $lastNumber, $matches)) {
                $next = (int) $matches[1] + 1;
            }

            return sprintf('%s%0'.$padLength.'d', $prefix, $next);
        });
    }
}
