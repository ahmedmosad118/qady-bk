<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ExpenseStatus;
use App\Models\Concerns\LogsModelActivity;
use Carbon\Carbon;
use Database\Factories\ExpenseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Str;
use LogicException;
use Spatie\Activitylog\LogOptions;

#[Fillable([
    'expense_number',
    'contact_id',
    'journal_id',
    'expense_date',
    'subtotal',
    'total',
    'status',
    'notes',
    'created_by',
    'posted_by',
    'posted_at',
])]
class Expense extends Model
{
    /** @use HasFactory<ExpenseFactory> */
    use HasFactory, LogsModelActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('expense');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'journal_id' => 'integer',
            'expense_date' => 'date:Y-m-d',
            'subtotal' => 'decimal:2',
            'total' => 'decimal:2',
            'status' => ExpenseStatus::class,
            'posted_by' => 'integer',
            'posted_at' => 'datetime',
        ];
    }

    /**
     * Optional supplier contact.
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    /**
     * Expense journal.
     */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'journal_id');
    }

    /**
     * Line items belonging to this expense.
     */
    public function items(): HasMany
    {
        return $this->hasMany(ExpenseItem::class, 'expense_id');
    }

    /**
     * User who created the expense.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * User who posted the expense.
     */
    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    /**
     * Linked journal entry for this expense.
     */
    public function journalEntry(): MorphOne
    {
        return $this->morphOne(JournalEntry::class, 'source');
    }

    /**
     * Scope to draft expenses.
     */
    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', ExpenseStatus::DRAFT);
    }

    /**
     * Scope to posted expenses.
     */
    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', ExpenseStatus::POSTED);
    }

    /**
     * Search expenses by expense number, notes, contact name, or item details.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term): void {
            $q->where('expense_number', 'like', "%{$term}%")
                ->orWhere('notes', 'like', "%{$term}%")
                ->orWhereHas('contact', function (Builder $contactQuery) use ($term): void {
                    $contactQuery->where('name', 'like', "%{$term}%");
                })
                ->orWhereHas('items', function (Builder $itemQuery) use ($term): void {
                    $itemQuery->where('description', 'like', "%{$term}%")
                        ->orWhere('reference_number', 'like', "%{$term}%")
                        ->orWhereHas('category', function (Builder $categoryQuery) use ($term): void {
                            $categoryQuery->where('name', 'like', "%{$term}%");
                        })
                        ->orWhereHas('account', function (Builder $accountQuery) use ($term): void {
                            $accountQuery->where('code', 'like', "%{$term}%")
                                ->orWhere('name', 'like', "%{$term}%");
                        });
                });
        });
    }

    public function isDraft(): bool
    {
        return $this->status === ExpenseStatus::DRAFT;
    }

    public function isPosted(): bool
    {
        return $this->status === ExpenseStatus::POSTED;
    }

    public function isEditable(): bool
    {
        return $this->isDraft();
    }

    public function isLocked(): bool
    {
        return ! $this->isEditable();
    }

    protected static function booted(): void
    {
        static::creating(function (Expense $expense): void {
            if (empty($expense->expense_number)) {
                $expense->expense_number = 'TMP-'.Str::uuid()->toString();
            }
        });

        static::created(function (Expense $expense): void {
            if (empty($expense->expense_number) || str_starts_with((string) $expense->expense_number, 'TMP-')) {
                $dayStr = ($expense->expense_date ? Carbon::parse($expense->expense_date) : Carbon::now())->format('Ymd');

                $expense->forceFill([
                    'expense_number' => sprintf('EXP-%s-%04d', $dayStr, $expense->id),
                ])->saveQuietly();
            }
        });

        static::deleting(function (Expense $expense): void {
            if (! $expense->isDraft()) {
                throw new LogicException('Cannot delete a posted expense.');
            }
        });
    }
}
