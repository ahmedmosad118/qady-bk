<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PaymentMethodType;
use App\Models\PaymentMethod;
use Illuminate\Database\Seeder;

class PaymentMethodSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (PaymentMethodType::cases() as $type) {
            PaymentMethod::query()->updateOrCreate(
                ['name' => $type->label()],
                [
                    'type' => $type->value,
                ]
            );
        }
    }
}
