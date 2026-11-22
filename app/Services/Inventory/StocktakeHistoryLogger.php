<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\StocktakeHistoryAction;
use App\Models\StocktakeItemHistory;

class StocktakeHistoryLogger
{
    public function log(
        int $stocktakeId,
        ?int $stocktakeItemId,
        StocktakeHistoryAction $action,
        ?string $previousStatus,
        ?string $newStatus,
        string $reason,
        int $userId,
        ?string $previousQuantity = null,
        ?string $newQuantity = null,
    ): StocktakeItemHistory {
        return StocktakeItemHistory::query()->create([
            'stocktake_id' => $stocktakeId,
            'stocktake_item_id' => $stocktakeItemId,
            'action' => $action->value,
            'previous_quantity' => $previousQuantity,
            'new_quantity' => $newQuantity,
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
            'reason' => $reason,
            'user_id' => $userId,
        ]);
    }
}
