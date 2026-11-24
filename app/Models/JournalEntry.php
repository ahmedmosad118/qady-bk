<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Accounting\JournalEntryStatus;
use App\Models\Concerns\LogsModelActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\Activitylog\LogOptions;

#[Fillable([
    'journal_id',
    'fiscal_year_id',
    'accounting_period_id',
    'entry_number',
    'entry_date',
    'reference',
    'description',
    'source_type',
    'source_id',
    'status',
    'created_by',
    'posted_by',
    'posted_at',
])]
class JournalEntry extends Model
{
    use LogsModelActivity;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => JournalEntryStatus::Draft->value,
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('journal_entry');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'journal_id' => 'integer',
            'fiscal_year_id' => 'integer',
            'accounting_period_id' => 'integer',
            'entry_date' => 'date',
            'source_id' => 'integer',
            'status' => JournalEntryStatus::class,
            'created_by' => 'integer',
            'posted_by' => 'integer',
            'posted_at' => 'datetime',
        ];
    }

    /**
     * Journal this entry belongs to.
     */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'journal_id');
    }

    /**
     * Fiscal year this entry belongs to.
     */
    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class, 'fiscal_year_id');
    }

    /**
     * Accounting period this entry belongs to.
     */
    public function accountingPeriod(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'accounting_period_id');
    }

    /**
     * Debit/credit lines belonging to this journal entry.
     */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class, 'journal_entry_id');
    }

    /**
     * User who created this journal entry.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * User who posted this journal entry.
     */
    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    /**
     * Originating source document (polymorphic).
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function isDraft(): bool
    {
        return $this->status === JournalEntryStatus::Draft;
    }

    public function isPosted(): bool
    {
        return $this->status === JournalEntryStatus::Posted;
    }
}
