<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\Accounting\AccountTypeCode;
use App\Enums\Accounting\JournalType;
use App\Enums\Payment\PaymentRelatedType;
use App\Enums\Payment\PaymentStatus;
use App\Enums\Payment\PaymentType;
use App\Enums\PurchaseInvoiceStatus;
use App\Events\Payment\PaymentCancelled;
use App\Events\Payment\PaymentCreated;
use App\Events\Payment\PaymentDeleted;
use App\Events\Payment\PaymentPosted;
use App\Events\Payment\PaymentUpdated;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Journal;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentMethod;
use App\Models\PurchaseInvoice;
use App\Models\User;
use App\Services\Support\DocumentSequenceGenerator;
use App\Support\Decimal;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupplierPaymentService
{
    public const TRANSACTION_ATTEMPTS = 3;

    /** @var list<string> */
    private const ALLOWED_SORT_COLUMNS = [
        'id',
        'payment_number',
        'payment_date',
        'amount',
        'status',
        'created_at',
    ];

    public function __construct(
        protected DocumentSequenceGenerator $sequenceGenerator,
    ) {}

    /**
     * Paginate supplier payments with server-side filters and safe sorting.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $perPage = min(max($perPage, 1), 100);

        $query = Payment::query()
            ->where('payment_type', PaymentType::SEND->value)
            ->where('related_type', PaymentRelatedType::SUPPLIER->value)
            ->with([
                'contact:id,name',
                'paymentMethod:id,name,type',
                'journal:id,code,name,type',
                'creator:id,name',
                'postedBy:id,name',
                'cancelledBy:id,name',
                'journalEntry:id,entry_number,status,source_type,source_id,entry_date,posted_at',
            ])
            ->withCount('allocations');

        $this->applyFilters($query, $filters);
        $this->applySorting($query, $filters);

        return $query->paginate($perPage);
    }

    /**
     * Load a single supplier payment with all related details.
     */
    public function getDetails(Payment $payment): Payment
    {
        return $this->loadPaymentRelations($payment->id);
    }

    /**
     * List posted Purchase Invoices with positive outstanding balances for an active supplier.
     *
     * @return list<array<string, mixed>>
     */
    public function getOutstandingInvoicesForSupplier(int $supplierId): array
    {
        $supplier = $this->resolveActiveSupplier($supplierId);

        $invoices = PurchaseInvoice::query()
            ->where('supplier_id', $supplier->id)
            ->where('status', PurchaseInvoiceStatus::POSTED->value)
            ->orderBy('invoice_date')
            ->orderBy('id')
            ->get(['id', 'invoice_number', 'invoice_date', 'due_date', 'total']);

        if ($invoices->isEmpty()) {
            return [];
        }

        $invoiceIds = $invoices->pluck('id')->all();
        $morphType = (new PurchaseInvoice)->getMorphClass();

        // Only POSTED payment allocations reduce invoice outstanding balances; advances without allocations are excluded.
        $postedPaidByInvoice = PaymentAllocation::query()
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->where('payment_allocations.allocatable_type', $morphType)
            ->whereIn('payment_allocations.allocatable_id', $invoiceIds)
            ->where('payments.status', PaymentStatus::POSTED->value)
            ->groupBy('payment_allocations.allocatable_id')
            ->selectRaw('payment_allocations.allocatable_id, COALESCE(SUM(payment_allocations.allocated_amount), 0) as paid_total')
            ->pluck('paid_total', 'allocatable_id');

        $zero = Decimal::fromNumeric(0, Decimal::MONEY_SCALE);
        $result = [];

        foreach ($invoices as $invoice) {
            $total = Decimal::fromNumeric($invoice->total, Decimal::MONEY_SCALE);
            $paid = Decimal::fromNumeric($postedPaidByInvoice->get($invoice->id, 0), Decimal::MONEY_SCALE);
            $outstanding = Decimal::sub($total, $paid, Decimal::MONEY_SCALE);

            if (Decimal::comp($outstanding, $zero, Decimal::MONEY_SCALE) <= 0) {
                continue;
            }

            $result[] = [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'invoice_date' => $invoice->invoice_date?->toDateString(),
                'due_date' => $invoice->due_date?->toDateString(),
                'total' => $total,
                'paid_amount' => $paid,
                'outstanding_amount' => $outstanding,
            ];
        }

        return $result;
    }

    /**
     * Create a Draft Supplier Payment, its allocations, and its linked Draft Journal Entry atomically.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $user): Payment
    {
        return DB::transaction(function () use ($data, $user): Payment {
            $this->assertSupplierPaymentClassification($data);

            $supplier = $this->resolveActiveSupplier((int) ($data['contact_id'] ?? 0));
            $paymentMethod = $this->resolveActivePaymentMethod((int) ($data['payment_method_id'] ?? 0));
            $journal = $this->resolveActivePaymentJournal((int) ($data['journal_id'] ?? 0));

            $paymentDate = Carbon::parse((string) $data['payment_date'])->toDateString();
            $amount = $this->parsePositiveAmount($data['amount'] ?? 0, 'amount', 'payments.errors.amount_must_be_positive');

            $preparedAllocations = $this->validateAndPrepareAllocations(
                $data['allocations'] ?? [],
                $supplier->id,
                $amount,
            );

            $year = Carbon::parse($paymentDate)->format('Y');
            $paymentNumber = $this->sequenceGenerator->next(
                Payment::class,
                'payment_number',
                "PAY-{$year}-",
                5,
            );

            /** @var Payment $payment */
            $payment = Payment::query()->create([
                'payment_number' => $paymentNumber,
                'payment_type' => PaymentType::SEND,
                'related_type' => PaymentRelatedType::SUPPLIER,
                'contact_id' => $supplier->id,
                'account_id' => null,
                'payment_method_id' => $paymentMethod->id,
                'journal_id' => $journal->id,
                'payment_date' => $paymentDate,
                'amount' => $amount,
                'reference' => isset($data['reference']) && trim((string) $data['reference']) !== ''
                    ? trim((string) $data['reference'])
                    : null,
                'description' => isset($data['description']) && trim((string) $data['description']) !== ''
                    ? trim((string) $data['description'])
                    : null,
                'status' => PaymentStatus::DRAFT,
                'created_by' => $user->id,
            ]);

            $this->insertAllocations($payment, $preparedAllocations);

            $payment->setRelation('contact', $supplier);
            $payment->setRelation('paymentMethod', $paymentMethod);
            $payment->setRelation('journal', $journal);

            PaymentCreated::dispatch($payment, $user);

            return $this->loadPaymentRelations($payment->id);
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * Update a Draft Supplier Payment, replace its allocations, and synchronize the linked Draft Journal Entry.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Payment $payment, array $data, User $user): Payment
    {
        return DB::transaction(function () use ($payment, $data, $user): Payment {
            /** @var Payment $lockedPayment */
            $lockedPayment = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($lockedPayment->status !== PaymentStatus::DRAFT) {
                throw ValidationException::withMessages([
                    'status' => [(string) __('payments.errors.only_draft_can_be_updated')],
                ]);
            }

            $this->assertSupplierPaymentClassification($data);

            $supplier = $this->resolveActiveSupplier((int) ($data['contact_id'] ?? 0));
            $paymentMethod = $this->resolveActivePaymentMethod((int) ($data['payment_method_id'] ?? 0));
            $journal = $this->resolveActivePaymentJournal((int) ($data['journal_id'] ?? 0));

            $paymentDate = Carbon::parse((string) $data['payment_date'])->toDateString();
            $amount = $this->parsePositiveAmount($data['amount'] ?? 0, 'amount', 'payments.errors.amount_must_be_positive');

            $preparedAllocations = $this->validateAndPrepareAllocations(
                $data['allocations'] ?? [],
                $supplier->id,
                $amount,
            );

            $lockedPayment->update([
                'payment_type' => PaymentType::SEND,
                'related_type' => PaymentRelatedType::SUPPLIER,
                'contact_id' => $supplier->id,
                'account_id' => null,
                'payment_method_id' => $paymentMethod->id,
                'journal_id' => $journal->id,
                'payment_date' => $paymentDate,
                'amount' => $amount,
                'reference' => isset($data['reference']) && trim((string) $data['reference']) !== ''
                    ? trim((string) $data['reference'])
                    : null,
                'description' => isset($data['description']) && trim((string) $data['description']) !== ''
                    ? trim((string) $data['description'])
                    : null,
            ]);

            $lockedPayment->allocations()->delete();
            $this->insertAllocations($lockedPayment, $preparedAllocations);

            $lockedPayment->setRelation('contact', $supplier);
            $lockedPayment->setRelation('paymentMethod', $paymentMethod);
            $lockedPayment->setRelation('journal', $journal);

            PaymentUpdated::dispatch($lockedPayment, $user);

            return $this->loadPaymentRelations($lockedPayment->id);
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * Post a Draft Supplier Payment after locking and revalidating target invoice outstanding balances.
     */
    public function post(Payment $payment, User $user): Payment
    {
        return DB::transaction(function () use ($payment, $user): Payment {
            /** @var Payment $lockedPayment */
            $lockedPayment = Payment::query()
                ->with(['allocations' => static fn ($q) => $q->orderBy('allocatable_id')])
                ->lockForUpdate()
                ->findOrFail($payment->id);

            if ($lockedPayment->status !== PaymentStatus::DRAFT) {
                throw ValidationException::withMessages([
                    'status' => [(string) __('payments.errors.only_draft_can_be_posted')],
                ]);
            }

            $this->assertSupplierPaymentClassification([
                'payment_type' => $lockedPayment->payment_type?->value,
                'related_type' => $lockedPayment->related_type?->value,
                'account_id' => $lockedPayment->account_id,
            ]);

            $supplier = $this->resolveActiveSupplier((int) ($lockedPayment->contact_id ?? 0));
            $paymentMethod = $this->resolveActivePaymentMethod((int) ($lockedPayment->payment_method_id ?? 0));
            $journal = $this->resolveActivePaymentJournal((int) ($lockedPayment->journal_id ?? 0));

            $amount = $this->parsePositiveAmount($lockedPayment->amount, 'amount', 'payments.errors.amount_must_be_positive');

            $rawAllocations = $lockedPayment->allocations->map(static fn (PaymentAllocation $allocation): array => [
                'purchase_invoice_id' => $allocation->allocatable_id,
                'allocated_amount' => $allocation->allocated_amount,
            ])->all();

            $this->validateAndPrepareAllocations($rawAllocations, $supplier->id, $amount);

            $lockedPayment->setRelation('contact', $supplier);
            $lockedPayment->setRelation('paymentMethod', $paymentMethod);
            $lockedPayment->setRelation('journal', $journal);

            PaymentPosted::dispatch($lockedPayment, $user);

            $lockedPayment->update([
                'status' => PaymentStatus::POSTED,
                'posted_by' => $user->id,
                'posted_at' => now(),
            ]);

            return $this->loadPaymentRelations($lockedPayment->id);
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * Cancel a Draft Supplier Payment, delete its linked Draft Journal Entry, and preserve allocation history.
     */
    public function cancel(Payment $payment, User $user): Payment
    {
        return DB::transaction(function () use ($payment, $user): Payment {
            /** @var Payment $lockedPayment */
            $lockedPayment = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($lockedPayment->status !== PaymentStatus::DRAFT) {
                throw ValidationException::withMessages([
                    'status' => [(string) __('payments.errors.only_draft_can_be_cancelled')],
                ]);
            }

            $lockedPayment->update([
                'status' => PaymentStatus::CANCELLED,
                'cancelled_by' => $user->id,
                'cancelled_at' => now(),
            ]);

            PaymentCancelled::dispatch($lockedPayment, $user);

            return $this->loadPaymentRelations($lockedPayment->id);
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * Delete a Draft Supplier Payment, its allocations, and its linked Draft Journal Entry atomically.
     */
    public function delete(Payment $payment): bool
    {
        return DB::transaction(function () use ($payment): bool {
            /** @var Payment $lockedPayment */
            $lockedPayment = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($lockedPayment->status !== PaymentStatus::DRAFT) {
                throw ValidationException::withMessages([
                    'status' => [(string) __('payments.errors.only_draft_can_be_deleted')],
                ]);
            }

            PaymentDeleted::dispatch($lockedPayment);

            $lockedPayment->allocations()->delete();

            return (bool) $lockedPayment->delete();
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * Calculate a Purchase Invoice's remaining payable balance from posted payment allocations only.
     */
    public function calculateInvoiceOutstandingAmount(PurchaseInvoice $invoice): string
    {
        $paid = PaymentAllocation::query()
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->where('payment_allocations.allocatable_type', $invoice->getMorphClass())
            ->where('payment_allocations.allocatable_id', $invoice->id)
            ->where('payments.status', PaymentStatus::POSTED->value)
            ->sum('payment_allocations.allocated_amount');

        $totalStr = Decimal::fromNumeric($invoice->total, Decimal::MONEY_SCALE);
        $paidStr = Decimal::fromNumeric($paid ?? 0, Decimal::MONEY_SCALE);

        return Decimal::sub($totalStr, $paidStr, Decimal::MONEY_SCALE);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertSupplierPaymentClassification(array $data): void
    {
        if (($data['payment_type'] ?? null) !== PaymentType::SEND->value) {
            throw ValidationException::withMessages([
                'payment_type' => [(string) __('payments.errors.invalid_payment_type')],
            ]);
        }

        if (($data['related_type'] ?? null) !== PaymentRelatedType::SUPPLIER->value) {
            throw ValidationException::withMessages([
                'related_type' => [(string) __('payments.errors.invalid_related_type')],
            ]);
        }

        if (array_key_exists('account_id', $data) && $data['account_id'] !== null && $data['account_id'] !== '') {
            throw ValidationException::withMessages([
                'account_id' => [(string) __('payments.errors.account_id_must_be_null')],
            ]);
        }
    }

    private function resolveActiveSupplier(int $contactId): Contact
    {
        if ($contactId <= 0) {
            throw ValidationException::withMessages([
                'contact_id' => [(string) __('payments.errors.supplier_invalid')],
            ]);
        }

        $supplier = Contact::query()
            ->where('is_supplier', true)
            ->where('is_active', true)
            ->find($contactId);

        if (! $supplier) {
            throw ValidationException::withMessages([
                'contact_id' => [(string) __('payments.errors.supplier_invalid')],
            ]);
        }

        return $supplier;
    }

    private function resolveActivePaymentMethod(int $paymentMethodId): PaymentMethod
    {
        if ($paymentMethodId <= 0) {
            throw ValidationException::withMessages([
                'payment_method_id' => [(string) __('payments.errors.payment_method_invalid')],
            ]);
        }

        $paymentMethod = PaymentMethod::query()
            ->where('is_active', true)
            ->find($paymentMethodId);

        if (! $paymentMethod || $paymentMethod->account_id === null) {
            throw ValidationException::withMessages([
                'payment_method_id' => [(string) __('payments.errors.payment_method_invalid')],
            ]);
        }

        $account = Account::query()
            ->with('accountType:id,code')
            ->find((int) $paymentMethod->account_id);

        if (! $account || ! $account->is_active || $account->accountType?->code !== AccountTypeCode::BankCash->value) {
            throw ValidationException::withMessages([
                'payment_method_id' => [(string) __('payments.errors.payment_method_account_invalid')],
            ]);
        }

        return $paymentMethod;
    }

    private function resolveActivePaymentJournal(int $journalId): Journal
    {
        if ($journalId <= 0) {
            throw ValidationException::withMessages([
                'journal_id' => [(string) __('payments.errors.journal_invalid')],
            ]);
        }

        $journal = Journal::query()
            ->where('is_active', true)
            ->whereIn('type', [JournalType::Cash->value, JournalType::Bank->value])
            ->find($journalId);

        if (! $journal) {
            throw ValidationException::withMessages([
                'journal_id' => [(string) __('payments.errors.journal_invalid')],
            ]);
        }

        return $journal;
    }

    private function parsePositiveAmount(mixed $raw, string $field, string $errorKey): string
    {
        $parsed = Decimal::parse($raw);
        if ($parsed === null) {
            throw ValidationException::withMessages([
                $field => [(string) __($errorKey)],
            ]);
        }

        $amount = Decimal::round($parsed, Decimal::MONEY_SCALE);
        $zero = Decimal::fromNumeric(0, Decimal::MONEY_SCALE);

        if (Decimal::comp($amount, $zero, Decimal::MONEY_SCALE) <= 0) {
            throw ValidationException::withMessages([
                $field => [(string) __($errorKey)],
            ]);
        }

        return $amount;
    }

    /**
     * Validate allocation rows, lock target Purchase Invoices in ascending ID order, and verify balances.
     *
     * @param  array<int, mixed>  $rawAllocations
     * @return list<array{allocatable_type: string, allocatable_id: int, allocated_amount: string}>
     */
    private function validateAndPrepareAllocations(
        array $rawAllocations,
        int $supplierId,
        string $paymentAmount,
    ): array {
        if ($rawAllocations === []) {
            return [];
        }

        $invoiceIds = [];
        $parsedRows = [];

        foreach (array_values($rawAllocations) as $index => $row) {
            if (! is_array($row)) {
                throw ValidationException::withMessages([
                    "allocations.{$index}" => [(string) __('payments.errors.invoice_not_found')],
                ]);
            }

            $invoiceId = (int) ($row['purchase_invoice_id'] ?? 0);
            if ($invoiceId <= 0) {
                throw ValidationException::withMessages([
                    "allocations.{$index}.purchase_invoice_id" => [(string) __('payments.errors.invoice_not_found')],
                ]);
            }

            if (in_array($invoiceId, $invoiceIds, true)) {
                throw ValidationException::withMessages([
                    "allocations.{$index}.purchase_invoice_id" => [(string) __('payments.errors.duplicate_invoice_allocation')],
                ]);
            }

            $allocatedAmount = $this->parsePositiveAmount(
                $row['allocated_amount'] ?? 0,
                "allocations.{$index}.allocated_amount",
                'payments.errors.allocated_amount_must_be_positive',
            );

            $invoiceIds[] = $invoiceId;
            $parsedRows[] = [
                'index' => $index,
                'invoice_id' => $invoiceId,
                'allocated_amount' => $allocatedAmount,
            ];
        }

        // Lock target invoices in ascending ID order to prevent deadlocks and concurrent over-allocation.
        $sortedIds = $invoiceIds;
        sort($sortedIds, SORT_NUMERIC);

        $invoices = PurchaseInvoice::query()
            ->whereIn('id', $sortedIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $morphType = (new PurchaseInvoice)->getMorphClass();

        $postedPaidByInvoice = PaymentAllocation::query()
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->where('payment_allocations.allocatable_type', $morphType)
            ->whereIn('payment_allocations.allocatable_id', $sortedIds)
            ->where('payments.status', PaymentStatus::POSTED->value)
            ->groupBy('payment_allocations.allocatable_id')
            ->selectRaw('payment_allocations.allocatable_id, COALESCE(SUM(payment_allocations.allocated_amount), 0) as paid_total')
            ->pluck('paid_total', 'allocatable_id');

        $allocationsTotal = Decimal::fromNumeric(0, Decimal::MONEY_SCALE);
        $prepared = [];

        foreach ($parsedRows as $row) {
            $idx = $row['index'];
            $invoiceId = $row['invoice_id'];
            /** @var PurchaseInvoice|null $invoice */
            $invoice = $invoices->get($invoiceId);

            if (! $invoice) {
                throw ValidationException::withMessages([
                    "allocations.{$idx}.purchase_invoice_id" => [(string) __('payments.errors.invoice_not_found')],
                ]);
            }

            if ($invoice->status !== PurchaseInvoiceStatus::POSTED) {
                throw ValidationException::withMessages([
                    "allocations.{$idx}.purchase_invoice_id" => [(string) __('payments.errors.invoice_not_posted')],
                ]);
            }

            if ((int) $invoice->supplier_id !== $supplierId) {
                throw ValidationException::withMessages([
                    "allocations.{$idx}.purchase_invoice_id" => [(string) __('payments.errors.invoice_supplier_mismatch')],
                ]);
            }

            $invoiceTotal = Decimal::fromNumeric($invoice->total, Decimal::MONEY_SCALE);
            $alreadyPaid = Decimal::fromNumeric($postedPaidByInvoice->get($invoiceId, 0), Decimal::MONEY_SCALE);
            $outstanding = Decimal::sub($invoiceTotal, $alreadyPaid, Decimal::MONEY_SCALE);

            if (Decimal::comp($row['allocated_amount'], $outstanding, Decimal::MONEY_SCALE) > 0) {
                throw ValidationException::withMessages([
                    "allocations.{$idx}.allocated_amount" => [(string) __('payments.errors.allocation_exceeds_outstanding')],
                ]);
            }

            $allocationsTotal = Decimal::add($allocationsTotal, $row['allocated_amount'], Decimal::MONEY_SCALE);

            $prepared[] = [
                'allocatable_type' => $morphType,
                'allocatable_id' => $invoiceId,
                'allocated_amount' => $row['allocated_amount'],
            ];
        }

        if (Decimal::comp($allocationsTotal, $paymentAmount, Decimal::MONEY_SCALE) !== 0) {
            throw ValidationException::withMessages([
                'allocations' => [(string) __('payments.errors.allocations_total_mismatch')],
            ]);
        }

        return $prepared;
    }

    /**
     * @param  list<array{allocatable_type: string, allocatable_id: int, allocated_amount: string}>  $allocations
     */
    private function insertAllocations(Payment $payment, array $allocations): void
    {
        if ($allocations === []) {
            return;
        }

        $now = now();
        $rows = array_map(static fn (array $row): array => [
            'payment_id' => $payment->id,
            'allocatable_type' => $row['allocatable_type'],
            'allocatable_id' => $row['allocatable_id'],
            'allocated_amount' => $row['allocated_amount'],
            'created_at' => $now,
            'updated_at' => $now,
        ], $allocations);

        PaymentAllocation::query()->insert($rows);
    }

    private function loadPaymentRelations(int $paymentId): Payment
    {
        return Payment::query()
            ->with([
                'contact:id,name,is_supplier,is_customer,payable_account_id',
                'paymentMethod:id,name,type,account_id',
                'journal:id,code,name,type',
                'creator:id,name',
                'postedBy:id,name',
                'cancelledBy:id,name',
                'allocations.allocatable',
                'journalEntry.lines.account:id,code,name',
            ])
            ->findOrFail($paymentId);
    }

    /**
     * @param  Builder<Payment>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(static function (Builder $q) use ($search): void {
                $q->where('payment_number', 'like', "%{$search}%")
                    ->orWhere('reference', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('contact', static function (Builder $contactQuery) use ($search): void {
                        $contactQuery->where('name', 'like', "%{$search}%");
                    });
            });
        }

        if (! empty($filters['payment_number'])) {
            $number = trim((string) $filters['payment_number']);
            $query->where('payment_number', 'like', "%{$number}%");
        }

        $supplierId = $filters['contact_id'] ?? $filters['supplier_id'] ?? null;
        if (! empty($supplierId)) {
            $query->where('contact_id', (int) $supplierId);
        }

        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        if (! empty($filters['payment_date'])) {
            $query->whereDate('payment_date', (string) $filters['payment_date']);
        }

        if (! empty($filters['payment_date_from'])) {
            $query->whereDate('payment_date', '>=', (string) $filters['payment_date_from']);
        }

        if (! empty($filters['payment_date_to'])) {
            $query->whereDate('payment_date', '<=', (string) $filters['payment_date_to']);
        }

        if (! empty($filters['payment_method_id'])) {
            $query->where('payment_method_id', (int) $filters['payment_method_id']);
        }

        if (! empty($filters['journal_id'])) {
            $query->where('journal_id', (int) $filters['journal_id']);
        }
    }

    /**
     * @param  Builder<Payment>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applySorting(Builder $query, array $filters): void
    {
        $sortBy = (string) ($filters['sort_by'] ?? '');
        $sortOrder = strtolower((string) ($filters['sort_order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        if (in_array($sortBy, self::ALLOWED_SORT_COLUMNS, true)) {
            $query->orderBy($sortBy, $sortOrder);
            if ($sortBy !== 'id') {
                $query->orderBy('id', $sortOrder);
            }

            return;
        }

        $query->orderByDesc('payment_date')
            ->orderByDesc('id');
    }
}
