<?php

declare(strict_types=1);

namespace App\Http\Resources\Payment;

use App\Enums\Payment\PaymentRelatedType;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Payment
 */
class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payment_number' => $this->payment_number,
            'payment_type' => $this->payment_type?->value,
            'related_type' => $this->related_type?->value,
            'contact_id' => $this->contact_id,
            'account_id' => $this->account_id,
            'payment_method_id' => $this->payment_method_id,
            'journal_id' => $this->journal_id,
            'payment_date' => $this->payment_date?->toDateString(),
            'amount' => $this->amount,
            'reference' => $this->reference,
            'description' => $this->description,
            'status' => $this->status?->value,
            'posted_at' => $this->posted_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'contact' => $this->whenLoaded('contact', fn (): ?array => $this->contact ? [
                'id' => $this->contact->id,
                'name' => $this->contact->name,
                'is_supplier' => (bool) $this->contact->is_supplier,
                'is_customer' => (bool) $this->contact->is_customer,
            ] : null),
            'supplier' => $this->whenLoaded('contact', fn (): ?array => ($this->contact && $this->related_type === PaymentRelatedType::SUPPLIER) ? [
                'id' => $this->contact->id,
                'name' => $this->contact->name,
            ] : null),
            'customer' => $this->whenLoaded('contact', fn (): ?array => ($this->contact && $this->related_type === PaymentRelatedType::CUSTOMER) ? [
                'id' => $this->contact->id,
                'name' => $this->contact->name,
            ] : null),
            'account' => $this->whenLoaded('account', fn (): ?array => $this->account ? [
                'id' => $this->account->id,
                'code' => $this->account->code,
                'name' => $this->account->name,
            ] : null),
            'payment_method' => $this->whenLoaded('paymentMethod', fn (): ?array => $this->paymentMethod ? [
                'id' => $this->paymentMethod->id,
                'name' => $this->paymentMethod->name,
                'type' => $this->paymentMethod->type?->value,
                'account_id' => $this->paymentMethod->account_id,
                'account' => $this->paymentMethod->relationLoaded('account') && $this->paymentMethod->account ? [
                    'id' => $this->paymentMethod->account->id,
                    'code' => $this->paymentMethod->account->code,
                    'name' => $this->paymentMethod->account->name,
                ] : null,
            ] : null),
            'journal' => $this->whenLoaded('journal', fn (): ?array => $this->journal ? [
                'id' => $this->journal->id,
                'code' => $this->journal->code,
                'name' => $this->journal->name,
                'type' => $this->journal->type?->value,
            ] : null),
            'creator' => $this->whenLoaded('creator', fn (): ?array => $this->creator ? [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ] : null),
            'posted_by' => $this->whenLoaded('postedBy', fn (): ?array => $this->postedBy ? [
                'id' => $this->postedBy->id,
                'name' => $this->postedBy->name,
            ] : null),
            'cancelled_by' => $this->whenLoaded('cancelledBy', fn (): ?array => $this->cancelledBy ? [
                'id' => $this->cancelledBy->id,
                'name' => $this->cancelledBy->name,
            ] : null),
            'allocations_count' => $this->whenCounted('allocations'),
            'allocations' => SupplierPaymentAllocationResource::collection($this->whenLoaded('allocations')),
            'journal_entry' => $this->whenLoaded('journalEntry', fn (): ?array => $this->journalEntry ? [
                'id' => $this->journalEntry->id,
                'entry_number' => $this->journalEntry->entry_number,
                'status' => $this->journalEntry->status?->value,
                'entry_date' => $this->journalEntry->entry_date?->toDateString(),
                'posted_at' => $this->journalEntry->posted_at?->toISOString(),
                'lines' => $this->journalEntry->relationLoaded('lines')
                    ? $this->journalEntry->lines->map(static fn ($line): array => [
                        'id' => $line->id,
                        'line_number' => $line->line_number,
                        'account_id' => $line->account_id,
                        'label' => $line->label,
                        'debit' => $line->debit,
                        'credit' => $line->credit,
                        'account' => $line->relationLoaded('account') && $line->account ? [
                            'id' => $line->account->id,
                            'code' => $line->account->code,
                            'name' => $line->account->name,
                        ] : null,
                    ])->values()->all()
                    : [],
            ] : null),
        ];
    }
}
