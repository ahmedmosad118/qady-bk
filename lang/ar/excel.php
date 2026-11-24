<?php

declare(strict_types=1);

return [
    'options' => [
        'select_from_list' => 'اختر من القائمة',
        'yes' => 'نعم',
        'no' => 'لا',
        'active' => 'نشط',
        'inactive' => 'معطل',
        'uncategorized' => 'بدون تصنيف',
        'main_category' => 'قسم رئيسي',
    ],

    'validation_prompt' => [
        'list_title' => 'اختر من القائمة',
        'list_message' => 'يرجى اختيار قيمة من القائمة المنسدلة.',
        'list_error_title' => 'قيمة غير صالحة',
        'list_error_message' => 'القيمة المدخلة غير موجودة في القائمة.',
    ],

    'messages' => [
        'import_success' => 'تم استيراد :count سجل بنجاح.',
        'empty_file' => 'الملف المرفوع فارغ ولا يحتوي على بيانات للاستيراد.',
        'pattern_mismatch' => 'الملف المرفوع لا يطابق هيكل القالب المطلوب.',
        'row_error' => 'خطأ في الصف رقم (:row) - :message',
        'required' => 'حقل ":field" مطلوب.',
        'max_length' => 'حقل ":field" يجب ألا يتجاوز :max حرف.',
        'invalid_number' => 'حقل ":field" يجب أن يكون رقمًا صحيحًا أو عشريًا صالحًا.',
        'invalid_integer' => 'حقل ":field" يجب أن يكون رقمًا صحيحًا.',
        'min_value' => 'حقل ":field" يجب أن يكون أكبر من أو يساوي :min.',
        'max_value' => 'حقل ":field" يجب ألا يتجاوز :max.',
        'range_between' => 'حقل ":field" يجب أن يكون بين :min و :max.',
        'invalid_boolean' => 'حقل ":field" يحتوي على قيمة غير صالحة.',
        'selling_price_gt_purchase' => 'سعر البيع يجب أن يكون أكبر من سعر الشراء.',
        'duplicate_barcode_db' => 'الباركود ":barcode" مستخدم بالفعل لمنتج آخر.',
        'duplicate_barcode_file' => 'الباركود ":barcode" مكرر داخل نفس الملف.',
        'category_not_found' => 'التصنيف ":name" غير موجود أو غير نشط.',
        'duplicate_category_db' => 'القسم ":name" موجود بالفعل تحت نفس القسم الأب.',
        'duplicate_category_file' => 'القسم ":name" مكرر داخل نفس الملف.',
        'parent_not_found' => 'القسم الأب ":name" غير موجود.',
        'parent_must_be_top_level' => 'القسم الأب ":name" يجب أن يكون قسمًا رئيسيًا فقط.',
        'parent_invalid' => 'لا يمكن أن يكون القسم أبًا لنفسه.',
    ],

    'products' => [
        'sheet_title' => 'المنتجات والإصدارات',
        'columns' => [
            'name' => 'اسم المنتج',
            'barcode' => 'الباركود',
            'category' => 'التصنيف',
            'author' => 'المؤلف',
            'publisher' => 'الناشر',
            'edition' => 'الطبعة',
            'publication_year' => 'سنة النشر',
            'purchase_price' => 'سعر الشراء',
            'selling_price' => 'سعر البيع',
            'stock_quantity' => 'الكمية بالمخازن',
            'show_in_shop' => 'يظهر في المتجر',
            'is_active' => 'الحالة',
        ],
    ],

    'product_categories' => [
        'sheet_title' => 'أقسام المنتجات',
        'columns' => [
            'name' => 'اسم القسم',
            'parent' => 'القسم الأب',
            'is_active' => 'الحالة',
            'products_count' => 'عدد المنتجات',
        ],
    ],
];
