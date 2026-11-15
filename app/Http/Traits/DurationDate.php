<?php

namespace App\Http\Traits;

use Carbon\Carbon;

trait DurationDate
{
    public function calculateDuration($startDate, $endDate): string
    {
        if (!$startDate || !$endDate) {
            return "";
        }

        $from = Carbon::parse($startDate);
        $to = Carbon::parse($endDate);

        if ($to->lessThan($from)) {
            [$from, $to] = [$to, $from];
        }

        return $this->humanDuration($from, $to);
    }

    protected function humanDuration(Carbon $from, Carbon $to): string
    {
        $seconds = $from->diffInSeconds($to);

        return match (true) {
            $seconds < 60 =>
            $seconds . ' ' . __('Seconds'),

            $seconds < 3600 =>
            floor($seconds / 60) . ' ' . __('Minutes'),

            $seconds < 86400 =>
            floor($seconds / 3600) . ' ' . __('Hours'),

            $seconds < 2592000 =>
            floor($seconds / 86400) . ' ' . __('Days'),

            $seconds < 31536000 =>
            floor($seconds / 2592000) . ' ' . __('Months'),

            default =>
            floor($seconds / 31536000) . ' ' . __('Years'),
        };
    }
}
