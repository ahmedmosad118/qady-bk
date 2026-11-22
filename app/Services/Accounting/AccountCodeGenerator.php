<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\ChartOfAccount;
use Illuminate\Support\Facades\DB;

class AccountCodeGenerator
{
    private const INITIAL_SEQUENCE = 1001;

    /**
     * Generate the next unique global account code.
     * Uses row-level locks to prevent duplicate codes under concurrent requests.
     */
    public function generateNextCode(): string
    {
        return DB::transaction(function (): string {
            $existingCodes = ChartOfAccount::query()
                ->lockForUpdate()
                ->pluck('code');

            $maxSequence = 0;
            foreach ($existingCodes as $code) {
                $codeStr = (string) $code;
                if (ctype_digit($codeStr)) {
                    $maxSequence = max($maxSequence, (int) $codeStr);
                }
            }

            $next = $maxSequence > 0 ? $maxSequence + 1 : self::INITIAL_SEQUENCE;
            $candidate = (string) $next;

            while (ChartOfAccount::query()->where('code', $candidate)->lockForUpdate()->exists()) {
                $next++;
                $candidate = (string) $next;
            }

            return $candidate;
        });
    }
}
