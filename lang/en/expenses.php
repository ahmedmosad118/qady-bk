<?php

return [
    'created_successfully' => 'Expense created successfully.',
    'updated_successfully' => 'Expense updated successfully.',
    'deleted_successfully' => 'Expense deleted successfully.',
    'category_created_successfully' => 'Expense category created successfully.',
    'category_updated_successfully' => 'Expense category updated successfully.',
    'category_deleted_successfully' => 'Expense category deleted successfully.',
    'attachment_uploaded_successfully' => 'Attachments uploaded successfully.',
    'attachment_deleted_successfully' => 'Attachment deleted successfully.',
    'errors' => [
        'cannot_delete_used_category' => 'Cannot delete this expense category because it is associated with existing expenses.',
        'cannot_modify_posted_expense' => 'Posted expenses cannot be edited or modified.',
        'cannot_delete_posted_expense' => 'Posted expenses cannot be deleted.',
        'at_least_one_item' => 'An expense must contain at least one item.',
        'item_amount_positive' => 'Each expense item amount must be greater than zero.',
        'total_positive' => 'The expense total must be greater than zero.',
        'invalid_item_id' => 'The selected item does not belong to this expense.',
        'duplicate_item_id' => 'Duplicate expense item ID submitted.',
        'invalid_attachment_id' => 'The selected attachment does not belong to this expense item.',
        'attachment_not_found' => 'Attachment file could not be found.',
    ],
];
