<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Accounting\FiscalYearStatus;
use App\Models\Concerns\LogsModelActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;

#[Fillable([
    'code',
    'name',
    'start_date',
    'end_date',
    'status',
])]
class FiscalYear extends Model
{
    use LogsModelActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('fiscal_year');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'status' => FiscalYearStatus::class,
        ];
    }

    /**
     * Accounting periods within this fiscal year.
     */
    public function accountingPeriods(): HasMany
    {
        return $this->hasMany(AccountingPeriod::class, 'fiscal_year_id');
    }
}
