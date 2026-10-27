<?php

return [

    'validation' => [
        'name_required' => 'The book name is required.',
        'name_max' => 'The book name may not exceed 255 characters.',
        'sku_required' => 'The product code (SKU) is required.',
        'sku_unique' => 'This code has already been taken.',
        'barcode_unique' => 'This barcode has already been taken.',
        'purchase_price_required' => 'The purchase price is required.',
        'purchase_price_min' => 'The purchase price cannot be negative.',
        'selling_price_required' => 'The selling price is required.',
        'selling_price_min' => 'The selling price cannot be negative.',
        'category_id_exists' => 'The selected category does not exist or is unavailable.',
        'publication_year_range' => 'The publication year must be between 1000 and 2100.',
    ],

    'parent_must_be_top_level' => 'The parent category must be a top-level category.',
    'parent_invalid' => 'A category cannot be its own parent.',
    'delete_has_children' => 'Cannot delete a category that still has subcategories.',

];
