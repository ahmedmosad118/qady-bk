<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'expense_item_id',
    'disk',
    'path',
    'original_name',
    'mime_type',
    'size',
    'created_by',
])]
class ExpenseItemAttachment extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $hidden = [
        'disk',
        'path',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    /**
     * Parent expense item.
     */
    public function expenseItem(): BelongsTo
    {
        return $this->belongsTo(ExpenseItem::class, 'expense_item_id');
    }

    /**
     * User who uploaded this attachment.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
