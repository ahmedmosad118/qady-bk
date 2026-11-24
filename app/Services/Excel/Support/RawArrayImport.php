<?php

declare(strict_types=1);

namespace App\Services\Excel\Support;

use Maatwebsite\Excel\Concerns\ToArray;

final class RawArrayImport implements ToArray
{
    /**
     * @param  array<int, array<int, mixed>>  $array
     */
    public function array(array $array): void
    {
        // Used exclusively via Excel::toArray() to obtain raw cell arrays.
    }
}
