<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PaymentMethodType;
use App\Models\PaymentMethod;
use Illuminate\Database\Seeder;

class PaymentMethodSeeder extends Seeder
{
    /**
     * Seed default payment methods idempotently without overwriting user customizations.
     */
    public function run(): void
    {
        foreach (PaymentMethodType::cases() as $type) {
            $existing = PaymentMethod::query()
                ->where('type', $type->value)
                ->orderBy('id')
                ->first();

            if (! $existing) {
                PaymentMethod::query()->create([
                    'name' => $type->label(),
                    'type' => $type->value,
                    'account_id' => null,
                    'is_active' => true,
                ]);
            }
        }
    }
}
