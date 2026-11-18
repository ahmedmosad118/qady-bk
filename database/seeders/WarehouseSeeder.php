<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class WarehouseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            // Check if any default warehouse exists
            $defaultExists = Warehouse::query()->where('is_default', true)->exists();

            if (! $defaultExists) {
                // If MAIN code exists, make it default without overwriting its data
                $existingMain = Warehouse::query()->where('code', 'MAIN')->first();

                if ($existingMain) {
                    $existingMain->update([
                        'is_default' => true,
                    ]);
                } else {
                    Warehouse::query()->create([
                        'code' => 'MAIN',
                        'name' => 'المخزن الرئيسي',
                        'description' => 'المخزن الرئيسي الافتراضي للنظام',
                        'address' => 'يُحدد لاحقًا',
                        'phone' => null,
                        'manager_name' => null,
                        'is_default' => true,
                    ]);
                }
            }

            // Ensure exactly one warehouse is marked as default
            $firstDefaultId = Warehouse::query()->where('is_default', true)->value('id');
            if ($firstDefaultId) {
                Warehouse::query()
                    ->where('id', '!=', $firstDefaultId)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }
        });
    }
}
