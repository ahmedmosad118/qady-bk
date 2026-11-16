<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;

class SettingService
{
    /**
     * Retrieve a settings record by type, initializing defaults if absent.
     *
     * @param array<string, mixed> $defaults
     */
    public function getByType(string $type, array $defaults = []): Setting
    {
        return Setting::query()->firstOrCreate(
            ['type' => $type],
            ['data' => $defaults]
        );
    }

    /**
     * Update (or create) a settings record by merging into existing data.
     *
     * @param array<string, mixed> $data
     */
    public function updateByType(string $type, array $data): Setting
    {
        return DB::transaction(function () use ($type, $data): Setting {
            /** @var Setting|null $setting */
            $setting = Setting::query()->where('type', $type)->lockForUpdate()->first();

            if (! $setting) {
                return Setting::query()->create([
                    'type' => $type,
                    'data' => $data,
                ]);
            }

            $existingData = is_array($setting->data) ? $setting->data : [];
            $setting->update([
                'data' => array_merge($existingData, $data),
            ]);

            return $setting->fresh();
        });
    }
}
