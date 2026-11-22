<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AccountPurpose;
use App\Enums\AccountType;
use App\Enums\PaymentMethodType;
use App\Models\ChartOfAccount;
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
        $defaultAccountsByPurpose = [];

        foreach ([AccountPurpose::CASH, AccountPurpose::BANK] as $purpose) {
            $candidateId = ChartOfAccount::query()
                ->where('account_type', AccountType::ASSET->value)
                ->where('account_purpose', $purpose->value)
                ->orderBy('id')
                ->value('id');

            if ($candidateId !== null) {
                $defaultAccountsByPurpose[$purpose->value] = (int) $candidateId;
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
}
