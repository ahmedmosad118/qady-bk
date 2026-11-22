<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\ChartOfAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountCodeGenerator
{
    /**
     * Generate the next unique hierarchical account code under the given parent.
     * Uses row-level locks to prevent duplicate codes under concurrent requests.
     */
    public function generateNextCode(?int $parentId): string
    {
        return DB::transaction(function () use ($parentId): string {
            if ($parentId === null) {
                $existingRootCodes = ChartOfAccount::query()
                    ->whereNull('parent_id')
                    ->lockForUpdate()
                    ->pluck('code');

                $maxSequence = 0;
                foreach ($existingRootCodes as $code) {
                    $codeStr = (string) $code;
                    if (ctype_digit($codeStr)) {
                        $maxSequence = max($maxSequence, (int) $codeStr);
                    }
                }

                $next = $maxSequence + 1;
                $candidate = (string) $next;

                while (ChartOfAccount::query()->where('code', $candidate)->exists()) {
                    $next++;
                    $candidate = (string) $next;
                }

                return $candidate;
            }

            /** @var ChartOfAccount $parent */
            $parent = ChartOfAccount::query()->lockForUpdate()->findOrFail($parentId);

            $siblingCodes = ChartOfAccount::query()
                ->where('parent_id', $parentId)
                ->lockForUpdate()
                ->pluck('code');

            $prefix = $parent->code.'.';
            $prefixLen = strlen($prefix);
            $maxSequence = 0;

            foreach ($siblingCodes as $siblingCode) {
                $codeStr = (string) $siblingCode;
                if (str_starts_with($codeStr, $prefix)) {
                    $suffix = substr($codeStr, $prefixLen);
                    if (ctype_digit($suffix)) {
                        $maxSequence = max($maxSequence, (int) $suffix);

                        continue;
                    }
                }

                $lastDotPos = strrpos($codeStr, '.');
                if ($lastDotPos !== false) {
                    $tail = substr($codeStr, $lastDotPos + 1);
                    if (ctype_digit($tail)) {
                        $maxSequence = max($maxSequence, (int) $tail);
                    }
                }
            }

            $next = $maxSequence + 1;
            $candidate = $this->formatChildCode($parent->code, $next);

            while (ChartOfAccount::query()->where('code', $candidate)->exists()) {
                $next++;
                $candidate = $this->formatChildCode($parent->code, $next);
            }

            return $candidate;
        });
    }

    /**
     * Update descendant codes when a parent account's code changes.
     * Preserves all primary IDs, foreign keys, and settings references.
     */
    public function cascadeDescendantCodes(ChartOfAccount $parent, string $oldCode, string $newCode): void
    {
        if ($oldCode === $newCode) {
            return;
        }

        $oldPrefix = $oldCode.'.';
        $oldPrefixLen = strlen($oldPrefix);
        $newPrefix = $newCode.'.';

        // Breadth-first collection of the entire subtree in O(depth) queries.
        $subtree = [];
        $levelIds = [$parent->id];

        while ($levelIds !== []) {
            $children = ChartOfAccount::query()
                ->whereIn('parent_id', $levelIds)
                ->lockForUpdate()
                ->get(['id', 'code']);

            $levelIds = [];
            foreach ($children as $child) {
                $subtree[] = $child;
                $levelIds[] = $child->id;
            }
        }

        if ($subtree === []) {
            return;
        }

        $plannedUpdates = [];
        $targetCodes = [];
        $subtreeIds = [$parent->id];

        foreach ($subtree as $descendant) {
            $subtreeIds[] = $descendant->id;
            if (str_starts_with($descendant->code, $oldPrefix)) {
                $updatedCode = $newPrefix.substr($descendant->code, $oldPrefixLen);
                $plannedUpdates[$descendant->id] = $updatedCode;
                $targetCodes[] = $updatedCode;
            }
        }

        if ($plannedUpdates === []) {
            return;
        }

        $conflict = ChartOfAccount::query()
            ->whereNotIn('id', $subtreeIds)
            ->whereIn('code', $targetCodes)
            ->value('code');

        if ($conflict !== null) {
            throw ValidationException::withMessages([
                'code' => [(string) __('accounting.errors.descendant_code_conflict', ['code' => $conflict])],
            ]);
        }

        if ($plannedUpdates !== []) {
            foreach (array_chunk($plannedUpdates, 500, true) as $chunk) {
                $cases = [];
                $bindings = [];
                foreach ($chunk as $id => $updatedCode) {
                    $cases[] = 'WHEN ? THEN ?';
                    $bindings[] = (int) $id;
                    $bindings[] = $updatedCode;
                }
                foreach (array_keys($chunk) as $id) {
                    $bindings[] = (int) $id;
                }
                $inPlaceholders = implode(', ', array_fill(0, count($chunk), '?'));
                $caseSql = 'CASE id '.implode(' ', $cases).' END';

                DB::update(
                    "UPDATE chart_of_accounts SET code = {$caseSql} WHERE id IN ({$inPlaceholders})",
                    $bindings
                );
            }
        }
    }

    private function formatChildCode(string $parentCode, int $sequence): string
    {
        return $parentCode.'.'.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT);
    }
}
