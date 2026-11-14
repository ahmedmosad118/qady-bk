<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\PaymentMethod;
use Illuminate\Database\Seeder;

class PaymentMethodSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $methods = [
            'نقدي',
            'فيزا',
            'تحويل بنكي',
        ];

        foreach ($methods as $name) {
            PaymentMethod::query()->firstOrCreate(
                ['name' => $name],
                ['is_active' => true]
            );
        }
    }
}
