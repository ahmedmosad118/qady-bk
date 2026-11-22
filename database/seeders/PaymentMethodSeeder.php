<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AccountPurpose;
use App\Enums\AccountType;
use App\Enums\PaymentMethodType;
use App\Models\ChartOfAccount;
use App\Models\Contact;
use App\Models\PaymentMethod;
use Illuminate\Database\Seeder;

class PaymentMethodSeeder extends Seeder
{
    /**
     * Seed default payment methods idempotently and link any unmapped methods
     * to compatible Asset accounts without overwriting user customizations.
     */
    public function run(): void
    {
        $contactAccountIds = $this->getContactAccountIdSet();
        $defaultAccountsByPurpose = [];

        foreach ([AccountPurpose::CASH, AccountPurpose::BANK] as $purpose) {
            $candidates = ChartOfAccount::query()
                ->where('account_type', AccountType::ASSET->value)
                ->where('account_purpose', $purpose->value)
                ->orderBy('id')
                ->get(['id']);

            foreach ($candidates as $candidate) {
                if (! isset($contactAccountIds[(int) $candidate->id])) {
                    $defaultAccountsByPurpose[$purpose->value] = (int) $candidate->id;
                    break;
                }
            }
        }

        foreach (PaymentMethodType::cases() as $type) {
            $compatibleAccountId = $defaultAccountsByPurpose[$type->requiredAccountPurpose()->value] ?? null;

            $existing = PaymentMethod::query()
                ->where('type', $type->value)
                ->orderBy('id')
                ->first();

            if (! $existing) {
                PaymentMethod::query()->create([
                    'name' => $type->label(),
                    'type' => $type->value,
                    'chart_of_account_id' => $compatibleAccountId,
                    'is_active' => true,
                ]);

                continue;
            }

            if ($existing->chart_of_account_id === null && $compatibleAccountId !== null) {
                $existing->update(['chart_of_account_id' => $compatibleAccountId]);
            }
        }
    }

    /**
     * @return array<int, true>
     */
    private function getContactAccountIdSet(): array
    {
        $set = [];
        $contacts = Contact::withTrashed()
            ->where(function ($query): void {
                $query->whereNotNull('receivable_account_id')
                    ->orWhereNotNull('payable_account_id');
            })
            ->get(['receivable_account_id', 'payable_account_id']);

        foreach ($contacts as $contact) {
            if ($contact->receivable_account_id !== null) {
                $set[(int) $contact->receivable_account_id] = true;
            }
            if ($contact->payable_account_id !== null) {
                $set[(int) $contact->payable_account_id] = true;
            }
        }

        return $set;
    }
}
