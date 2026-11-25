<?php

declare(strict_types=1);

namespace App\Http\Resources\Payment;

use App\Models\PaymentAllocation;
use App\Models\PurchaseInvoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PaymentAllocation
 */
class SupplierPaymentAllocationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payment_id' => $this->payment_id,
            'allocatable_type' => $this->allocatable_type,
            'allocatable_id' => $this->allocatable_id,
            'allocated_amount' => $this->allocated_amount,
            'purchase_invoice' => $this->when(
                $this->relationLoaded('allocatable') && $this->allocatable instanceof PurchaseInvoice,
                function (): array {
                    /** @var PurchaseInvoice $invoice */
                    $invoice = $this->allocatable;

                    return [
                        'id' => $invoice->id,
                        'invoice_number' => $invoice->invoice_number,
                        'invoice_date' => $invoice->invoice_date?->toDateString(),
                        'total' => $invoice->total,
                        'status' => $invoice->status?->value,
                    ];
                }
            ),
        ];
    }
}
