<?php

declare(strict_types=1);

return [
    'errors' => [
        'warehouse_inactive_create' => "Warehouse ':name' is inactive; a stocktake cannot be created for it.",
        'products_required' => "At least one product must be selected for a partial stocktake.",
        'duplicate_product' => "A product cannot be repeated within the same stocktake.",
        'products_not_found' => "Some of the selected products do not exist.",
        'start_count_status' => "Cannot start counting the item while the stocktake is in status ':status'.",
        'already_counting' => "This product is already being counted.",
        'another_active_count' => "Another active stocktake is currently counting this product in the same warehouse.",
        'record_count_status' => "Cannot record a quantity for an item in status ':status'. Counting must be started first.",
        'negative_count' => "The counted quantity cannot be negative.",
        'cancel_count_reason_required' => "A reason for cancelling the count is required.",
        'cancel_count_status' => "Cannot cancel counting for an item in status ':status'. Only items currently being counted can be cancelled.",
        'recount_reason_required' => "A reason for requesting a recount is required.",
        'recount_after_complete' => "Cannot recount after the stocktake has been completed.",
        'recount_status' => "Cannot recount while the stocktake is in status ':status'.",
        'warehouse_inactive_start' => "Warehouse ':name' is inactive; a stocktake cannot be started for it.",
        'start_status' => "Cannot start the stocktake in status ':status'. It must be a draft.",
        'active_stocktake_exists' => "An active stocktake already exists for this warehouse and has not been closed yet. Only one active stocktake per warehouse is allowed at a time.",
        'scope_empty' => "There are no active products or available balances in the system to start a full stocktake.",
        'no_items_in_scope' => "Cannot start a stocktake without any products in its scope.",
        'submit_status' => "Cannot submit the stocktake for approval in status ':status'.",
        'no_items' => "The stocktake has no items.",
        'uncounted_remaining' => "Cannot submit for approval before the actual quantity is recorded for all products. :count product(s) remain uncounted.",
        'unsaved_counts' => "Some products do not have a saved actual quantity.",
        'approve_status' => "Cannot approve the stocktake in status ':status'. It must be pending approval.",
        'no_variance' => "Product ':name' has no calculated variance.",
        'reject_reason_required' => "A reason for rejecting the stocktake is required.",
        'reject_status' => "Cannot reject the stocktake in status ':status'.",
        'cancel_status' => "Cannot cancel the stocktake in status ':status'.",
    ],

    'history' => [
        'created' => "Stocktake draft created",
        'start_counting' => "Counting started: system balance captured and the product temporarily locked",
        'recorded_count' => "Actual quantity recorded; product count completed and lock released",
        'started' => "Stocktake started and product scope fixed",
        'submitted' => "Stocktake submitted for approval after all items were counted",
        'approved_completed' => "Stocktake approved and completed immediately since there are no stock variances",
        'approved' => "Stocktake results approved and stock adjustment drafts generated pending posting",
        'cancelled' => "Stocktake cancelled and any active locks released",
        'completed' => "All generated stock adjustment documents were posted successfully",
    ],

    'notes' => [
        'surplus_item' => "Stocktake surplus for product :name",
        'shortage_item' => "Stocktake shortage for product :name",
        'surplus_document' => "Surplus adjustment resulting from stocktake :number",
        'shortage_document' => "Shortage adjustment resulting from stocktake :number",
    ],
];
