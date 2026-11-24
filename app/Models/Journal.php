<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Accounting\JournalType;
use App\Models\Concerns\LogsModelActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;

#[Fillable([
    'code',
    'name',
    'type',
    'is_active',
])]
class Journal extends Model
{
    use LogsModelActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('journal');
    }

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
