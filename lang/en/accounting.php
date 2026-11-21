<?php

declare(strict_types=1);

return [
    'types' => [
        'asset' => 'Asset',
        'liability' => 'Liability',
        'equity' => 'Equity',
        'revenue' => 'Revenue',
        'expense' => 'Expense',
    ],

    'attributes' => [
        'parent_id' => 'parent account',
        'code' => 'account code',
        'name' => 'account name',
        'account_type' => 'account type',
        'description' => 'description',
        'settings' => 'accounting settings',
    ],

    'messages' => [
        'account_created' => 'Account created successfully.',
        'account_updated' => 'Account updated successfully.',
        'account_deleted' => 'Account deleted successfully.',
        'settings_updated' => 'Accounting settings updated successfully.',
    ],

    'errors' => [
        'parent_not_found' => 'Selected parent account does not exist.',
        'parent_type_mismatch' => 'Child account type must match parent account type (:type).',
        'children_type_mismatch' => 'Cannot change account type while the account has child accounts.',
        'cannot_attach_child_to_used_detail_account' => 'Cannot add child accounts under this account because it is used where a detail account is required.',
        'self_parent' => 'An account cannot be its own parent.',
        'circular_reference' => 'Circular reference detected: an account cannot have one of its descendants as its parent.',
        'code_duplicate' => 'The account code has already been taken.',
        'descendant_code_conflict' => 'Updating this code causes a duplicate code conflict on descendant account (:code).',
        'delete_has_children' => 'Cannot delete an account that has child accounts.',
        'delete_linked_to_settings' => 'Cannot delete an account linked to accounting settings.',
        'delete_linked_to_contact' => 'Cannot delete an account linked to a contact.',
        'cannot_post_to_group_account' => 'Cannot post entries to a group account that has child accounts.',
        'setting_key_invalid' => 'Accounting setting key (:key) is not defined.',
        'setting_required_missing' => 'Required accounting setting (:label) cannot be removed or left empty.',
        'setting_account_not_found' => 'Selected account for (:label) does not exist.',
        'setting_account_type_mismatch' => 'Selected account for (:label) must be of type (:type).',
        'setting_account_must_be_detail' => 'Selected account for (:label) must be a detail (postable) account with no children.',
        'setting_account_cannot_be_contact_account' => 'Accounting setting (:label) cannot point to a contact sub-account.',
        'setting_control_account_invalid' => 'Selected account for (:label) cannot act as a control account because it contains non-contact child accounts.',
        'contact_control_accounts_missing' => 'Receivable and payable control accounts are not configured in accounting settings.',
        'contact_control_account_invalid' => 'Configured contact control account is invalid.',
    ],

    'settings' => [
        'keys' => [
            'cash' => [
                'label' => 'Cash Account',
                'description' => 'Default account for cash receipts and payments in POS and treasury.',
            ],
            'bank' => [
                'label' => 'Bank Account',
                'description' => 'Default account for Visa card and bank transfer payments.',
            ],
            'accounts_receivable' => [
                'label' => 'Accounts Receivable Control Account',
                'description' => 'Parent control account under which customer receivable sub-accounts are created automatically.',
            ],
            'inventory' => [
                'label' => 'Inventory Account',
                'description' => 'Default asset account for book and product inventory.',
            ],
            'accounts_payable' => [
                'label' => 'Accounts Payable Control Account',
                'description' => 'Parent control account under which supplier payable sub-accounts are created automatically.',
            ],
            'capital' => [
                'label' => 'Capital Account',
                'description' => 'Default equity account for owner capital and opening balances.',
            ],
            'owner_current' => [
                'label' => 'Owner Current Account',
                'description' => 'Account used for owner withdrawals recorded in POS shifts.',
            ],
            'sales_revenue' => [
                'label' => 'Sales Revenue Account',
                'description' => 'Default revenue account for book and product sales.',
            ],
            'sales_returns' => [
                'label' => 'Sales Returns & Allowances Account',
                'description' => 'Default account for POS sales returns and allowances.',
            ],
            'cost_of_goods_sold' => [
                'label' => 'Cost of Goods Sold Account',
                'description' => 'Default expense account for cost of goods sold and inventory returns.',
            ],
            'operating_expenses' => [
                'label' => 'Operating Expenses Account',
                'description' => 'Default expense account for general expenses, shift expenses, and separate purchase expenses.',
            ],
        ],
    ],
];
