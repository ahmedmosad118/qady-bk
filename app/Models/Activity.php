<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity as BaseActivity;

class Activity extends BaseActivity
{
    /**
     * Safely normalize the activity properties payload into an associative array.
     *
     * @return array<string, mixed>
     */
    public function getNormalizedProperties(): array
    {
        /** @var mixed $properties */
        $properties = $this->getAttribute('properties');

        if ($properties instanceof Collection) {
            return $properties->toArray();
        }

        if (is_array($properties)) {
            return $properties;
        }

        if (is_string($properties) && $properties !== '') {
            $decoded = json_decode($properties, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
