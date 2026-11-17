<?php

declare(strict_types=1);

return [
    'unit_piece' => 'piece',

    'messages' => [
        'sale_created' => 'POS sale invoice created, posted, and inventory deducted successfully.',
        'cash_sale_transaction' => 'Cash sale - Invoice :invoice_number',
        'auto_goods_delivery_notes' => 'Automatic goods delivery for POS sale invoice: :invoice_number',
    ],

    'errors' => [
        'shift_not_found' => 'The selected shift was not found.',
        'shift_closed' => 'The selected shift is closed. Cannot complete a sale on a closed shift.',
        'payment_method_not_found' => 'The selected payment method is invalid or does not exist.',
        'default_warehouse_missing' => 'The default warehouse is missing or not configured in the system.',
        'customer_not_found' => 'The selected customer was not found.',
        'items_required' => 'At least one item must be added to the invoice.',
        'invalid_product_id' => 'Invalid product identifier.',
        'product_not_found' => 'The requested product (#:id) was not found.',
        'invalid_item_quantity' => 'Each item quantity must be a valid decimal greater than zero.',
        'invalid_unit_price' => 'Item unit price must be a valid non-negative decimal.',
        'negative_unit_price' => "Selling price for product ':product' cannot be negative.",
        'invalid_item_discount_type' => 'Invalid item discount type.',
        'invalid_item_discount_value' => 'Invalid item discount value.',
        'negative_item_discount' => 'Item discount value cannot be negative.',
        'item_discount_percentage_range' => 'Item discount percentage must be between 0 and 100.',
        'invalid_invoice_discount_type' => 'Invalid invoice discount type.',
        'invalid_invoice_discount_value' => 'Invalid invoice discount value.',
        'negative_invoice_discount' => 'Invoice discount value cannot be negative.',
        'invoice_discount_percentage_range' => 'Invoice discount percentage must be between 0 and 100.',
        'barcode_required' => 'Please enter a barcode to search.',
        'barcode_not_found' => 'No product was found matching this barcode.',
    ],
];
