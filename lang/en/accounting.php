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

    'purposes' => [
        'cash' => 'Cash',
        'bank' => 'Bank',
        'general' => 'General',
    ],

    'attributes' => [
        'code' => 'account code',
        'name' => 'account name',
        'account_type' => 'account type',
        'account_type_id' => 'account type',
        'normal_balance_override' => 'normal balance override',
        'reconcile' => 'reconcile',
        'is_active' => 'active status',
        'account_purpose' => 'account purpose',
        'description' => 'description',
        'settings' => 'accounting settings',
        'account_id' => 'account',
        'chart_of_account_id' => 'chart of account',
        'type' => 'payment method type',
    ],

    'messages' => [
        'account_created' => 'Account created successfully.',
        'account_updated' => 'Account updated successfully.',
        'account_deleted' => 'Account deleted successfully.',
        'settings_updated' => 'Accounting settings updated successfully.',
        'payment_method_created' => 'Payment method created successfully.',
        'payment_method_updated' => 'Payment method updated successfully.',
    ],

    'errors' => [
        'code_duplicate' => 'The account code has already been taken.',
        'purpose_requires_asset_type' => 'Cash and bank purposes are only valid for Asset accounts.',
        'delete_linked_to_settings' => 'Cannot delete an account linked to accounting settings.',
        'delete_linked_to_payment_method' => 'Cannot delete an account linked to a payment method.',
        'delete_linked_to_contact' => 'Cannot delete an account linked to a contact.',
        'account_type_inactive' => 'The selected account type is inactive.',
        'system_account_type_locked' => 'Cannot change the account type of a system account.',
        'system_account_code_locked' => 'Cannot change the code of a system account.',
        'system_account_cannot_deactivate' => 'Cannot deactivate a system account.',
        'system_account_cannot_delete' => 'Cannot delete a system account.',
        'payment_method_account_type_locked' => 'Cannot change the account type while linked to a payment method; it must remain an Asset account.',
        'payment_method_account_purpose_locked' => 'Cannot change the account purpose because it is incompatible with a linked payment method.',
        'setting_key_invalid' => 'Accounting setting key (:key) is not defined.',
        'setting_required_missing' => 'Required accounting setting (:label) cannot be removed or left empty.',
        'setting_account_not_found' => 'Selected account for (:label) does not exist.',
        'setting_account_type_mismatch' => 'Selected account for (:label) must be of type (:type).',
        'setting_account_cannot_be_cash_or_bank' => 'Accounting setting (:label) cannot point to a cash or bank account.',
        'setting_account_purpose_locked' => 'Cannot change the purpose of an account linked to accounting settings.',
        'setting_account_duplicate' => 'The same account cannot be assigned to multiple accounting settings (:label).',
        'payment_method_account_required' => 'A compatible account is required for the payment method.',
        'payment_method_account_not_found' => 'Selected account for the payment method does not exist.',
        'payment_method_account_inactive' => 'Selected account for the payment method must be active.',
        'payment_method_account_must_be_bank_cash' => 'Payment method must be linked to an account of type bank_cash.',
        'payment_method_account_must_be_asset' => 'Payment method must be linked to an Asset account.',
        'payment_method_account_purpose_mismatch' => 'Linked account purpose must be (:purpose).',
    ],

    'settings' => [
        'keys' => [
            'inventory' => [
                'label' => 'Inventory Account',
                'description' => 'Asset account tracking book and product inventory value.',
            ],
            'capital' => [
                'label' => 'Capital Account',
                'description' => 'Equity account for owner capital and opening balances.',
            ],
            'owner_current' => [
                'label' => 'Owner Current Account',
                'description' => 'Account used for owner withdrawals and deposits in POS shifts.',
            ],
            'sales_revenue' => [
                'label' => 'Book Sales Account',
                'description' => 'Revenue account recording book and product sales.',
            ],
            'sales_returns' => [
                'label' => 'Sales Returns Account',
                'description' => 'Revenue account recording POS sales returns.',
            ],
            'cost_of_goods_sold' => [
                'label' => 'Cost of Goods Sold Account',
                'description' => 'Expense account recording the cost of sold and returned inventory.',
            ],
            'operating_expenses' => [
                'label' => 'Operating Expenses Account',
                'description' => 'Expense account for operating expenses, shift expenses, and separate purchase expenses.',
            ],
        ],
    ],
];
