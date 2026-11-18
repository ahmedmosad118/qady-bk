<?php

declare(strict_types=1);

namespace App\Services\Purchase;

use App\Models\Contact;
use Illuminate\Validation\ValidationException;

/**
 * Single rule for "who can be the supplier of a purchase document" (orders and invoices):
 * a contact that is flagged as supplier AND is active.
 */
class PurchaseSupplierResolver
{
    /**
     * Resolve an active supplier or fail with a validation error on `supplier_id`.
     */
    public function resolveActive(int $supplierId): Contact
    {
        /** @var Contact|null $supplier */
        $supplier = Contact::query()
            ->where('id', $supplierId)
            ->where('is_supplier', true)
            ->where('is_active', true)
            ->first();

        if (! $supplier) {
            $this->fail();
        }

        return $supplier;
    }

    /**
     * Assert an already loaded contact (e.g. a relation) is a usable supplier.
     */
    public function assertUsable(?Contact $supplier): void
    {
        if (! $supplier || ! $supplier->is_supplier || ! $supplier->is_active) {
            $this->fail();
        }
    }

    /**
     * @return never
     */
    protected function fail(): never
    {
        throw ValidationException::withMessages([
            'supplier_id' => [(string) __('purchases.errors.supplier_invalid')],
        ]);
    }
}
