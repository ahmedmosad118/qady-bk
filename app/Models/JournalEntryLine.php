<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'journal_entry_id',
    'account_id',
    'line_number',
    'label',
    'debit',
    'credit',
])]
class JournalEntryLine extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'debit' => '0.00',
        'credit' => '0.00',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'journal_entry_id' => 'integer',
            'account_id' => 'integer',
            'line_number' => 'integer',
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
        ];
    }

    /**
     * Parent journal entry.
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    /**
     * General ledger account for this line.
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }
}
