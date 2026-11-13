<?php

declare(strict_types=1);

return [
    'warehouse' => 'Warehouse',
    'warehouses' => 'Warehouses',
    'warehouse_updated_successfully' => 'Warehouse details updated successfully.',
    'document_created_successfully' => 'Inventory document created as draft successfully.',
    'document_updated_successfully' => 'Inventory document updated successfully.',
    'document_deleted_successfully' => 'Draft document deleted successfully.',

    'types' => [
        'goods_receipt' => 'Goods Receipt',
        'goods_delivery' => 'Goods Delivery',
        'stock_issue' => 'Stock Issue',
        'warehouse_transfer' => 'Warehouse Transfer',
        'stock_adjustment' => 'Stock Adjustment',
        'inventory_return' => 'Inventory Return',
        'stock_write_off' => 'Stock Write-Off',
        'opening_balance' => 'Opening Balance',
    ],

    'statuses' => [
        'draft' => 'Draft',
        'pending_approval' => 'Pending Approval',
        'approved' => 'Approved',
        'posted' => 'Posted',
        'rejected' => 'Rejected',
        'cancelled' => 'Cancelled',
        'reversed' => 'Reversed',
    ],

    'adjustment_directions' => [
        'increase' => 'Adjustment Increase',
        'decrease' => 'Adjustment Decrease',
    ],

    'return_directions' => [
        'inbound' => 'Inbound Return',
        'outbound' => 'Outbound Return',
    ],

    'workflow' => [
        'submitted_successfully' => 'Document submitted for approval successfully.',
        'approved_successfully' => 'Document approved successfully.',
        'rejected_successfully' => 'Document rejected successfully.',
        'cancelled_successfully' => 'Document cancelled successfully.',
        'posted_successfully' => 'Document posted successfully.',
        'reversed_successfully' => 'Posted document reversed successfully.',
    ],

    'errors' => [
        'warehouse_not_found' => 'Warehouse not found.',
        'cannot_deactivate_default_warehouse' => 'Cannot deactivate the system default warehouse.',
        'source_warehouse_required' => 'Source warehouse is required for this document type.',
        'destination_warehouse_required' => 'Destination warehouse is required for this document type.',
        'source_warehouse_forbidden' => 'Source warehouse is not allowed for this document type or direction.',
        'destination_warehouse_forbidden' => 'Destination warehouse is not allowed for this document type or direction.',
        'transfer_warehouses_must_differ' => 'Destination warehouse must differ from source warehouse.',
        'source_warehouse_inactive' => 'Selected source warehouse is currently inactive.',
        'destination_warehouse_inactive' => 'Selected destination warehouse is currently inactive.',
        'adjustment_direction_required' => 'Adjustment direction (increase or decrease) is required.',
        'adjustment_direction_forbidden' => 'Adjustment direction is not allowed for this document type.',
        'return_direction_required' => 'Return direction (inbound or outbound) is required.',
        'return_direction_forbidden' => 'Return direction is not allowed for this document type.',
        'write_off_reason_required' => 'Write-off reason is required for stock write-off.',
        'contains_inactive_products' => 'The items list contains inactive or unavailable products.',
        'cannot_edit_non_draft_document' => 'Cannot edit document in its current status (:status). Only drafts and rejected documents can be edited.',
        'cannot_delete_non_draft' => 'Only draft documents can be deleted.',
        'document_has_no_items' => 'Cannot submit a document with no line items.',
        'cannot_submit_status' => 'Cannot submit document in its current status (:status).',
        'cannot_approve_status' => 'Cannot approve document in its current status (:status).',
        'cannot_reject_status' => 'Cannot reject document in its current status (:status).',
        'rejection_reason_required' => 'Rejection reason is required.',
        'cannot_cancel_status' => 'Cannot cancel document in its current status (:status).',
        'cannot_post_status' => 'Cannot post document until it is approved first (current status: :status).',
        'cannot_reverse_status' => 'Only posted documents can be reversed (current status: :status).',
        'reversal_reason_required' => 'Reversal reason is required.',
    ],
];
