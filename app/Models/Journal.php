<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Accounting\JournalType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'code',
    'name',
    'type',
    'is_active',
])]
class Journal extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => JournalType::class,
            'is_active' => 'boolean',
        ];
    }
}
