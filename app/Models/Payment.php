<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Payment\PaymentRelatedType;
use App\Enums\Payment\PaymentStatus;
use App\Enums\Payment\PaymentType;
use App\Models\Concerns\LogsModelActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Spatie\Activitylog\LogOptions;

#[Fillable([
    'payment_number',
    'payment_type',
    'related_type',
    'contact_id',
    'account_id',
    'payment_method_id',
    'journal_id',
    'payment_date',
    'amount',
    'reference',
    'description',
    'status',
    'created_by',
    'posted_by',
    'posted_at',
    'cancelled_by',
    'cancelled_at',
])]
class Payment extends Model
{
    use LogsModelActivity;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'payment_type' => PaymentType::SEND->value,
        'status' => PaymentStatus::DRAFT->value,
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('payment');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payment_type' => PaymentType::class,
            'related_type' => PaymentRelatedType::class,
            'contact_id' => 'integer',
            'account_id' => 'integer',
            'payment_method_id' => 'integer',
            'journal_id' => 'integer',
            'payment_date' => 'date:Y-m-d',
            'amount' => 'decimal:2',
            'status' => PaymentStatus::class,
            'created_by' => 'integer',
            'posted_by' => 'integer',
            'posted_at' => 'datetime',
            'cancelled_by' => 'integer',
            'cancelled_at' => 'datetime',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'journal_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class, 'payment_id');
    }

    public function journalEntry(): MorphOne
    {
        return $this->morphOne(JournalEntry::class, 'source');
    }
}
