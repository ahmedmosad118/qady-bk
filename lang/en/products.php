<?php

return [

    'validation' => [
        'name_required' => 'The book name is required.',
        'name_max' => 'The book name may not exceed 255 characters.',
        'barcode_unique' => 'This barcode has already been taken.',
        'purchase_price_required' => 'The purchase price is required.',
        'purchase_price_min' => 'The purchase price cannot be negative.',
        'selling_price_required' => 'The selling price is required.',
        'selling_price_min' => 'The selling price cannot be negative.',
        'selling_price_gt' => 'The selling price must be greater than the purchase price.',
        'category_id_exists' => 'The selected category does not exist or is unavailable.',
        'publication_year_range' => 'The publication year must be between 1000 and 2100.',
    ],

    'barcode_print' => [
        'items_required' => 'Select at least one product to print.',
        'items_max' => 'You cannot print more than 200 products at once.',
        'product_missing' => 'One of the selected products does not exist.',
        'copies_range' => 'Copies must be between 1 and 500.',
    ],

    'parent_must_be_top_level' => 'The parent category must be a top-level category.',
    'parent_invalid' => 'A category cannot be its own parent.',
    'delete_has_children' => 'Cannot delete a category that still has subcategories.',

];
