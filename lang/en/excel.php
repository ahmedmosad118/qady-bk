<?php

declare(strict_types=1);

return [
    'options' => [
        'select_from_list' => 'Select From List',
        'yes' => 'Yes',
        'no' => 'No',
        'active' => 'Active',
        'inactive' => 'Inactive',
        'uncategorized' => 'Uncategorized',
        'main_category' => 'Main category',
    ],

    'validation_prompt' => [
        'list_title' => 'Pick from list',
        'list_message' => 'Please pick a value from the drop-down list.',
        'list_error_title' => 'Invalid value',
        'list_error_message' => 'The entered value is not in the list.',
    ],

    'messages' => [
        'import_success' => 'Successfully imported :count record(s).',
        'empty_file' => 'The uploaded file is empty and contains no rows to import.',
        'pattern_mismatch' => 'The uploaded file does not match the required template columns.',
        'row_error' => 'Error in row number (:row) - :message',
        'required' => 'The ":field" field is required.',
        'max_length' => 'The ":field" field may not exceed :max characters.',
        'invalid_number' => 'The ":field" field must be a valid number.',
        'invalid_integer' => 'The ":field" field must be a whole number.',
        'min_value' => 'The ":field" field must be at least :min.',
        'max_value' => 'The ":field" field may not exceed :max.',
        'range_between' => 'The ":field" field must be between :min and :max.',
        'invalid_boolean' => 'The ":field" field has an invalid option.',
        'selling_price_gt_purchase' => 'The selling price must be greater than the purchase price.',
        'duplicate_barcode_db' => 'The barcode ":barcode" is already assigned to another product.',
        'duplicate_barcode_file' => 'The barcode ":barcode" is duplicated within the uploaded file.',
        'category_not_found' => 'The category ":name" does not exist or is inactive.',
        'duplicate_category_db' => 'The category ":name" already exists under the same parent.',
        'duplicate_category_file' => 'The category ":name" is duplicated within the uploaded file.',
        'parent_not_found' => 'The parent category ":name" was not found.',
        'parent_must_be_top_level' => 'The parent category ":name" must be a top-level category.',
        'parent_invalid' => 'A category cannot be its own parent.',
    ],

    'products' => [
        'sheet_title' => 'Products & Publications',
        'columns' => [
            'name' => 'Product Name',
            'barcode' => 'Barcode',
            'category' => 'Category',
            'author' => 'Author',
            'publisher' => 'Publisher',
            'edition' => 'Edition',
            'publication_year' => 'Publication Year',
            'purchase_price' => 'Purchase Price',
            'selling_price' => 'Selling Price',
            'stock_quantity' => 'Warehouse Stock',
            'show_in_shop' => 'Show in Shop',
            'is_active' => 'Status',
        ],
    ],

    'product_categories' => [
        'sheet_title' => 'Product Categories',
        'columns' => [
            'name' => 'Category Name',
            'parent' => 'Parent Category',
            'is_active' => 'Status',
            'products_count' => 'Products Count',
        ],
    ],
];
