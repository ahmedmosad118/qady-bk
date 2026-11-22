<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\StocktakeItemStatus;
use App\Enums\StocktakeStatus;
use App\Enums\StocktakeVarianceDirection;
use App\Models\Stocktake;
use App\Models\StocktakeItem;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class StocktakeQueryService
{
    /**
     * Load stocktake details, enforce Blind Count masking, and compute summary counters.
     *
     * @return array<string, mixed>
     */
    public function getDetailsForDisplay(Stocktake $stocktake, ?User $user): array
    {
        $stocktake->load([
            'warehouse',
            'creator',
            'starter',
            'submitter',
            'approver',
            'rejecter',
            'canceller',
            'completer',
            'inventoryDocuments' => function ($q): void {
                $q->with(['items.product', 'creator', 'poster']);
            },
            'items' => function ($q): void {
                $q->with(['product', 'countStartedBy', 'countedBy']);
            },
            'histories.user',
        ]);

        $canReviewResults = $this->canReviewResults($stocktake, $user);

        $formattedItems = $stocktake->items->map(function (StocktakeItem $item) use ($canReviewResults): array {
            $isCounted = $item->count_status === StocktakeItemStatus::COUNTED;
            $revealMetrics = $isCounted && $canReviewResults;

            return [
                'id' => $item->id,
                'stocktake_id' => $item->stocktake_id,
                'product_id' => $item->product_id,
                'product' => $item->product ? [
                    'id' => $item->product->id,
                    'name' => $item->product->name,
                    'barcode' => $item->product->barcode,
                    'author' => $item->product->author ?? null,
                ] : null,
                'system_quantity' => $revealMetrics ? (float) $item->system_quantity : null,
                'counted_quantity' => $item->counted_quantity !== null ? (float) $item->counted_quantity : null,
                'variance' => $revealMetrics ? (float) $item->getVariance() : null,
                'variance_direction' => $revealMetrics ? $item->getVarianceDirection()?->value : null,
                'count_status' => $item->count_status->value,
                'count_status_label' => $item->count_status->label(),
                'count_started_by' => $item->countStartedBy?->name,
                'count_started_at' => $item->count_started_at?->toIso8601String(),
                'counted_by' => $item->countedBy?->name,
                'counted_at' => $item->counted_at?->toIso8601String(),
                'notes' => $item->notes,
                'created_at' => $item->created_at?->toIso8601String(),
            ];
        });

        $stocktakeArray = $stocktake->toArray();
        $stocktakeArray['items'] = $formattedItems;
        $stocktakeArray['can_review_results'] = $canReviewResults;

        $stocktakeArray['total_items_count'] = $stocktake->items->count();
        $stocktakeArray['counted_items_count'] = $stocktake->items->where('count_status', StocktakeItemStatus::COUNTED)->count();
        $stocktakeArray['counting_items_count'] = $stocktake->items->where('count_status', StocktakeItemStatus::COUNTING)->count();
        $stocktakeArray['pending_items_count'] = $stocktake->items->where('count_status', StocktakeItemStatus::PENDING)->count();

        if ($canReviewResults) {
            $countedItems = $stocktake->items->where('count_status', StocktakeItemStatus::COUNTED);
            $stocktakeArray['increases_count'] = $countedItems->filter(fn ($i) => $i->getVarianceDirection() === StocktakeVarianceDirection::INCREASE)->count();
            $stocktakeArray['decreases_count'] = $countedItems->filter(fn ($i) => $i->getVarianceDirection() === StocktakeVarianceDirection::DECREASE)->count();
            $stocktakeArray['exact_count'] = $countedItems->filter(fn ($i) => $i->getVarianceDirection() === StocktakeVarianceDirection::EXACT)->count();
        }

        return $stocktakeArray;
    }

    private function canReviewResults(Stocktake $stocktake, ?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $stocktake->created_by === $user->id
            || $user->isSuperAdmin()
            || Gate::forUser($user)->allows('stocktakes.review_results')
            || in_array($stocktake->status, [StocktakeStatus::PENDING_APPROVAL, StocktakeStatus::APPROVED, StocktakeStatus::COMPLETED], true);
    }
}
