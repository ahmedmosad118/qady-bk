<?php

declare(strict_types=1);

return [
    'errors' => [
        'invalid_payment_type' => 'Only supplier send payments are supported in this phase.',
        'invalid_related_type' => 'Supplier payment requires related_type to be supplier.',
        'account_id_must_be_null' => 'Supplier payments cannot specify a direct account_id.',
        'supplier_invalid' => 'The selected supplier is invalid or inactive.',
        'payment_method_invalid' => 'The selected payment method is invalid or inactive.',
        'payment_method_account_invalid' => 'The payment method linked account must be an active bank/cash account.',
        'journal_invalid' => 'The selected journal must be an active Cash or Bank journal.',
        'amount_must_be_positive' => 'Payment amount must be greater than zero.',
        'allocations_required' => 'At least one purchase invoice allocation is required.',
        'duplicate_invoice_allocation' => 'Duplicate purchase invoice allocations are not allowed within one payment.',
        'allocated_amount_must_be_positive' => 'Allocated amount must be greater than zero.',
        'invoice_not_found' => 'The target purchase invoice does not exist.',
        'invoice_not_posted' => 'Allocations are only allowed for posted purchase invoices.',
        'invoice_supplier_mismatch' => 'All allocated purchase invoices must belong to the selected supplier.',
        'allocation_exceeds_outstanding' => 'Allocated amount exceeds the invoice remaining payable balance.',
        'allocations_total_mismatch' => 'Total allocated amount must equal the payment amount exactly.',
        'only_draft_can_be_updated' => 'Only draft payments can be updated.',
        'only_draft_can_be_posted' => 'Only draft payments can be posted.',
        'only_draft_can_be_cancelled' => 'Only draft payments can be cancelled.',
        'only_draft_can_be_deleted' => 'Only draft payments can be deleted.',
        'supplier_payable_account_missing' => 'No supplier payable account or default accounts_payable setting is configured.',
        'supplier_payable_account_invalid' => 'The resolved supplier payable account is inactive or not a payable account.',
    ],
    'labels' => [
        'supplier_payment_entry_description' => 'Supplier payment :number',
    ],
    'messages' => [
        'created' => 'Supplier payment draft created successfully.',
        'updated' => 'Supplier payment draft updated successfully.',
        'posted' => 'Supplier payment posted successfully.',
        'cancelled' => 'Supplier payment cancelled successfully.',
        'deleted' => 'Supplier payment deleted successfully.',
    ],
];
