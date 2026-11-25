<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\Accounting\AccountTypeCode;
use App\Enums\Accounting\JournalType;
use App\Enums\Payment\PaymentRelatedType;
use App\Enums\Payment\PaymentStatus;
use App\Enums\Payment\PaymentType;
use App\Events\Payment\PaymentCancelled;
use App\Events\Payment\PaymentCreated;
use App\Events\Payment\PaymentDeleted;
use App\Events\Payment\PaymentPosted;
use App\Events\Payment\PaymentUpdated;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Journal;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Support\DocumentSequenceGenerator;
use App\Support\Decimal;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public const TRANSACTION_ATTEMPTS = 3;

    /** @var list<string> */
    private const ALLOWED_SORT_COLUMNS = [
        'id',
        'payment_number',
        'payment_type',
        'related_type',
        'payment_date',
        'amount',
        'status',
        'created_at',
    ];

    public function __construct(
        protected SupplierPaymentService $supplierPaymentService,
        protected DocumentSequenceGenerator $sequenceGenerator,
    ) {}

    /**
     * Paginate all payment types with server-side filters and safe sorting.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $perPage = min(max($perPage, 1), 100);

        $query = Payment::query()
            ->with([
                'contact:id,name,is_supplier,is_customer',
                'account:id,code,name',
                'paymentMethod:id,name,type,account_id',
                'paymentMethod.account:id,code,name',
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
     * Load a single payment with all related details.
     */
    public function getDetails(Payment $payment): Payment
    {
        return $this->loadPaymentRelations($payment->id);
    }

    /**
     * Create a Draft Payment (Send, Receive, or Transfer) and its linked Draft Journal Entry atomically.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $user): Payment
    {
        if ($this->isSupplierSendPayload($data)) {
            $created = $this->supplierPaymentService->create($data, $user);

            return $this->loadPaymentRelations($created->id);
        }

        return DB::transaction(function () use ($data, $user): Payment {
            $classification = $this->resolveClassification($data);

            $contact = null;
            $account = null;
            $paymentMethod = $this->resolveActivePaymentMethod((int) ($data['payment_method_id'] ?? 0));
            $journal = $this->resolveActivePaymentJournal((int) ($data['journal_id'] ?? 0));

            if ($classification['payment_type'] === PaymentType::RECEIVE) {
                $contact = $this->resolveActiveCustomer((int) ($data['contact_id'] ?? 0));
                $this->assertEmptyAllocations($data['allocations'] ?? [], 'payments.errors.customer_allocations_not_supported');
            } else {
                $account = $this->resolveActiveTransferDestinationAccount((int) ($data['account_id'] ?? 0), $paymentMethod);
                $this->assertEmptyAllocations($data['allocations'] ?? [], 'payments.errors.transfer_allocations_not_supported');
            }

            $paymentDate = Carbon::parse((string) $data['payment_date'])->toDateString();
            $amount = $this->parsePositiveAmount($data['amount'] ?? 0);

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
                'payment_type' => $classification['payment_type'],
                'related_type' => $classification['related_type'],
                'contact_id' => $contact?->id,
                'account_id' => $account?->id,
                'payment_method_id' => $paymentMethod->id,
                'journal_id' => $journal->id,
                'payment_date' => $paymentDate,
                'amount' => $amount,
                'reference' => $this->normalizeOptionalText($data['reference'] ?? null),
                'description' => $this->normalizeOptionalText($data['description'] ?? null),
                'status' => PaymentStatus::DRAFT,
                'created_by' => $user->id,
            ]);

            if ($contact !== null) {
                $payment->setRelation('contact', $contact);
            }
            if ($account !== null) {
                $payment->setRelation('account', $account);
            }
            $payment->setRelation('paymentMethod', $paymentMethod);
            $payment->setRelation('journal', $journal);

            PaymentCreated::dispatch($payment, $user);

            return $this->loadPaymentRelations($payment->id);
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * Update a Draft Payment (including payment_type changes) and synchronize its linked Draft Journal Entry.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Payment $payment, array $data, User $user): Payment
    {
        if ($this->isSupplierSendPayload($data)) {
            $updated = $this->supplierPaymentService->update($payment, $data, $user);

            return $this->loadPaymentRelations($updated->id);
        }

        return DB::transaction(function () use ($payment, $data, $user): Payment {
            /** @var Payment $lockedPayment */
            $lockedPayment = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($lockedPayment->status !== PaymentStatus::DRAFT) {
                throw ValidationException::withMessages([
                    'status' => [(string) __('payments.errors.only_draft_can_be_updated')],
                ]);
            }

            $classification = $this->resolveClassification($data);

            $contact = null;
            $account = null;
            $paymentMethod = $this->resolveActivePaymentMethod((int) ($data['payment_method_id'] ?? 0));
            $journal = $this->resolveActivePaymentJournal((int) ($data['journal_id'] ?? 0));

            if ($classification['payment_type'] === PaymentType::RECEIVE) {
                $contact = $this->resolveActiveCustomer((int) ($data['contact_id'] ?? 0));
                $this->assertEmptyAllocations($data['allocations'] ?? [], 'payments.errors.customer_allocations_not_supported');
            } else {
                $account = $this->resolveActiveTransferDestinationAccount((int) ($data['account_id'] ?? 0), $paymentMethod);
                $this->assertEmptyAllocations($data['allocations'] ?? [], 'payments.errors.transfer_allocations_not_supported');
            }

            $paymentDate = Carbon::parse((string) $data['payment_date'])->toDateString();
            $amount = $this->parsePositiveAmount($data['amount'] ?? 0);

            $lockedPayment->update([
                'payment_type' => $classification['payment_type'],
                'related_type' => $classification['related_type'],
                'contact_id' => $contact?->id,
                'account_id' => $account?->id,
                'payment_method_id' => $paymentMethod->id,
                'journal_id' => $journal->id,
                'payment_date' => $paymentDate,
                'amount' => $amount,
                'reference' => $this->normalizeOptionalText($data['reference'] ?? null),
                'description' => $this->normalizeOptionalText($data['description'] ?? null),
            ]);

            // Clear any prior supplier invoice allocations when switching away from Send + Supplier.
            $lockedPayment->allocations()->delete();

            if ($contact !== null) {
                $lockedPayment->setRelation('contact', $contact);
            } else {
                $lockedPayment->unsetRelation('contact');
            }
            if ($account !== null) {
                $lockedPayment->setRelation('account', $account);
            } else {
                $lockedPayment->unsetRelation('account');
            }
            $lockedPayment->setRelation('paymentMethod', $paymentMethod);
            $lockedPayment->setRelation('journal', $journal);

            PaymentUpdated::dispatch($lockedPayment, $user);

            return $this->loadPaymentRelations($lockedPayment->id);
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * Post a Draft Payment and post its linked Journal Entry atomically.
     */
    public function post(Payment $payment, User $user): Payment
    {
        if ($payment->payment_type === PaymentType::SEND && $payment->related_type === PaymentRelatedType::SUPPLIER) {
            $posted = $this->supplierPaymentService->post($payment, $user);

            return $this->loadPaymentRelations($posted->id);
        }

        return DB::transaction(function () use ($payment, $user): Payment {
            /** @var Payment $lockedPayment */
            $lockedPayment = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($lockedPayment->status !== PaymentStatus::DRAFT) {
                throw ValidationException::withMessages([
                    'status' => [(string) __('payments.errors.only_draft_can_be_posted')],
                ]);
            }

            $classification = $this->resolveClassification([
                'payment_type' => $lockedPayment->payment_type?->value,
                'related_type' => $lockedPayment->related_type?->value,
                'contact_id' => $lockedPayment->contact_id,
                'account_id' => $lockedPayment->account_id,
            ]);

            $paymentMethod = $this->resolveActivePaymentMethod((int) ($lockedPayment->payment_method_id ?? 0));
            $journal = $this->resolveActivePaymentJournal((int) ($lockedPayment->journal_id ?? 0));
            $this->parsePositiveAmount($lockedPayment->amount);

            if ($classification['payment_type'] === PaymentType::RECEIVE) {
                $contact = $this->resolveActiveCustomer((int) ($lockedPayment->contact_id ?? 0));
                $lockedPayment->setRelation('contact', $contact);
            } elseif ($classification['payment_type'] === PaymentType::TRANSFER) {
                $account = $this->resolveActiveTransferDestinationAccount((int) ($lockedPayment->account_id ?? 0), $paymentMethod);
                $lockedPayment->setRelation('account', $account);
            } else {
                // In case a Draft was mutated to send+supplier externally before post()
                return $this->loadPaymentRelations($this->supplierPaymentService->post($lockedPayment, $user)->id);
            }

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
     * Cancel a Draft Payment, delete its linked Draft Journal Entry, and preserve allocation history.
     */
    public function cancel(Payment $payment, User $user): Payment
    {
        return DB::transaction(function () use ($payment, $user): Payment {
            /** @var Payment $lockedPayment */
            $lockedPayment = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            $this->resolveClassification([
                'payment_type' => $lockedPayment->payment_type?->value,
                'related_type' => $lockedPayment->related_type?->value,
                'contact_id' => $lockedPayment->contact_id,
                'account_id' => $lockedPayment->account_id,
            ]);

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
     * Delete a Draft Payment, its allocations, and its linked Draft Journal Entry atomically.
     */
    public function delete(Payment $payment): bool
    {
        return DB::transaction(function () use ($payment): bool {
            /** @var Payment $lockedPayment */
            $lockedPayment = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            $this->resolveClassification([
                'payment_type' => $lockedPayment->payment_type?->value,
                'related_type' => $lockedPayment->related_type?->value,
                'contact_id' => $lockedPayment->contact_id,
                'account_id' => $lockedPayment->account_id,
            ]);

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
     * @param  array<string, mixed>  $data
     */
    private function isSupplierSendPayload(array $data): bool
    {
        return ($data['payment_type'] ?? null) === PaymentType::SEND->value
            && ($data['related_type'] ?? null) === PaymentRelatedType::SUPPLIER->value;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{payment_type: PaymentType, related_type: ?PaymentRelatedType}
     */
    private function resolveClassification(array $data): array
    {
        $paymentType = PaymentType::tryFrom((string) ($data['payment_type'] ?? ''));
        $rawRelated = $data['related_type'] ?? null;
        $relatedType = $rawRelated !== null && $rawRelated !== ''
            ? PaymentRelatedType::tryFrom((string) $rawRelated)
            : null;

        if ($paymentType === PaymentType::SEND && $relatedType === PaymentRelatedType::SUPPLIER) {
            if (! empty($data['account_id'])) {
                throw ValidationException::withMessages([
                    'account_id' => [(string) __('payments.errors.account_id_must_be_null')],
                ]);
            }

            return ['payment_type' => $paymentType, 'related_type' => $relatedType];
        }

        if ($paymentType === PaymentType::RECEIVE && $relatedType === PaymentRelatedType::CUSTOMER) {
            if (! empty($data['account_id'])) {
                throw ValidationException::withMessages([
                    'account_id' => [(string) __('payments.errors.account_id_must_be_null')],
                ]);
            }

            return ['payment_type' => $paymentType, 'related_type' => $relatedType];
        }

        if ($paymentType === PaymentType::TRANSFER && $relatedType === null) {
            if (! empty($data['contact_id'])) {
                throw ValidationException::withMessages([
                    'contact_id' => [(string) __('payments.errors.transfer_related_must_be_null')],
                ]);
            }

            return ['payment_type' => $paymentType, 'related_type' => null];
        }

        throw ValidationException::withMessages([
            'payment_type' => [(string) __('payments.errors.unsupported_payment_classification')],
        ]);
    }

    private function resolveActiveCustomer(int $contactId): Contact
    {
        if ($contactId <= 0) {
            throw ValidationException::withMessages([
                'contact_id' => [(string) __('payments.errors.customer_invalid')],
            ]);
        }

        $customer = Contact::query()
            ->where('is_customer', true)
            ->where('is_active', true)
            ->find($contactId);

        if (! $customer) {
            throw ValidationException::withMessages([
                'contact_id' => [(string) __('payments.errors.customer_invalid')],
            ]);
        }

        return $customer;
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

    private function resolveActiveTransferDestinationAccount(int $accountId, PaymentMethod $sourceMethod): Account
    {
        if ($accountId <= 0) {
            throw ValidationException::withMessages([
                'account_id' => [(string) __('payments.errors.transfer_account_invalid')],
            ]);
        }

        if ($accountId === (int) $sourceMethod->account_id) {
            throw ValidationException::withMessages([
                'account_id' => [(string) __('payments.errors.transfer_same_account')],
            ]);
        }

        $account = Account::query()
            ->with('accountType:id,code')
            ->find($accountId);

        if (! $account || ! $account->is_active || $account->accountType?->code !== AccountTypeCode::BankCash->value) {
            throw ValidationException::withMessages([
                'account_id' => [(string) __('payments.errors.transfer_account_invalid')],
            ]);
        }

        return $account;
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

    private function assertEmptyAllocations(mixed $allocations, string $errorKey): void
    {
        if (is_array($allocations) && $allocations !== []) {
            throw ValidationException::withMessages([
                'allocations' => [(string) __($errorKey)],
            ]);
        }
    }

    private function parsePositiveAmount(mixed $value): string
    {
        if (! is_numeric($value)) {
            throw ValidationException::withMessages([
                'amount' => [(string) __('payments.errors.amount_must_be_positive')],
            ]);
        }

        $amount = Decimal::fromNumeric($value, Decimal::MONEY_SCALE);
        $zero = Decimal::fromNumeric(0, Decimal::MONEY_SCALE);

        if (Decimal::comp($amount, $zero, Decimal::MONEY_SCALE) <= 0) {
            throw ValidationException::withMessages([
                'amount' => [(string) __('payments.errors.amount_must_be_positive')],
            ]);
        }

        return $amount;
    }

    private function normalizeOptionalText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function loadPaymentRelations(int $paymentId): Payment
    {
        return Payment::query()
            ->with([
                'contact:id,name,is_supplier,is_customer,payable_account_id,receivable_account_id',
                'account:id,code,name,account_type_id,is_active',
                'paymentMethod:id,name,type,account_id',
                'paymentMethod.account:id,code,name',
                'journal:id,code,name,type',
                'creator:id,name',
                'postedBy:id,name',
                'cancelledBy:id,name',
                'allocations.allocatable',
                'journalEntry.lines.account:id,code,name',
            ])
            ->withCount('allocations')
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
                    })
                    ->orWhereHas('account', static function (Builder $accountQuery) use ($search): void {
                        $accountQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('code', 'like', "%{$search}%");
                    });
            });
        }

        if (! empty($filters['payment_number'])) {
            $number = trim((string) $filters['payment_number']);
            $query->where('payment_number', 'like', "%{$number}%");
        }

        if (! empty($filters['payment_type'])) {
            $query->where('payment_type', (string) $filters['payment_type']);
        }

        if (! empty($filters['related_type'])) {
            $query->where('related_type', (string) $filters['related_type']);
        }

        if (! empty($filters['contact_id'])) {
            $query->where('contact_id', (int) $filters['contact_id']);
        }

        if (! empty($filters['account_id'])) {
            $query->where('account_id', (int) $filters['account_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
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
