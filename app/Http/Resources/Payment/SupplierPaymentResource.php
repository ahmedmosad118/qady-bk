<?php

declare(strict_types=1);

namespace App\Http\Resources\Payment;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Payment
 */
class SupplierPaymentResource extends JsonResource
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
            'supplier' => $this->whenLoaded('contact', fn (): array => [
                'id' => $this->contact->id,
                'name' => $this->contact->name,
            ]),
            'payment_method' => $this->whenLoaded('paymentMethod', fn (): array => [
                'id' => $this->paymentMethod->id,
                'name' => $this->paymentMethod->name,
                'type' => $this->paymentMethod->type?->value,
            ]),
            'journal' => $this->whenLoaded('journal', fn (): array => [
                'id' => $this->journal->id,
                'code' => $this->journal->code,
                'name' => $this->journal->name,
                'type' => $this->journal->type?->value,
            ]),
            'creator' => $this->whenLoaded('creator', fn (): array => [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ]),
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
            ] : null),
        ];
    }
}
