<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ExpenseStatus;
use Carbon\Carbon;
use Database\Factories\ExpenseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use LogicException;

#[Fillable([
    'expense_number',
    'expense_category_id',
    'contact_id',
    'expense_date',
    'subtotal',
    'total',
    'status',
    'notes',
    'created_by',
])]
class Expense extends Model
{
    /** @use HasFactory<ExpenseFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expense_date' => 'date:Y-m-d',
            'subtotal' => 'decimal:2',
            'total' => 'decimal:2',
            'status' => ExpenseStatus::class,
        ];
    }

    /**
     * Expense category.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    /**
     * Optional beneficiary/contact.
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
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
     * Search expenses by expense number, notes, category name, or contact name.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term): void {
            $q->where('expense_number', 'like', "%{$term}%")
                ->orWhere('notes', 'like', "%{$term}%")
                ->orWhereHas('category', function (Builder $categoryQuery) use ($term): void {
                    $categoryQuery->where('name', 'like', "%{$term}%");
                })
                ->orWhereHas('contact', function (Builder $contactQuery) use ($term): void {
                    $contactQuery->where('name', 'like', "%{$term}%");
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
