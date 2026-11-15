<?php

namespace App\Http\Traits;

use App\Models\Activity;
use Spatie\Activitylog\Traits\LogsActivity as SpatieLogsActivity;

trait LogsActivity
{
    use SpatieLogsActivity;

    public function activity()
    {
        return $this->morphMany(Activity::class, 'subject')->orderBy('created_at', 'desc');
    }
}
