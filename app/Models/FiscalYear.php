<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Accounting\FiscalYearStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'code',
    'name',
    'start_date',
    'end_date',
    'status',
])]
class FiscalYear extends Model
{
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
