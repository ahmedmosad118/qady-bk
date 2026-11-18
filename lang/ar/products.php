<?php

return [

    'validation' => [
        'name_required' => 'اسم الكتاب مطلوب.',
        'name_max' => 'اسم الكتاب لا يزيد عن 255 حرفًا.',
        'barcode_unique' => 'هذا الباركود مستخدم بالفعل لمنتج آخر.',
        'purchase_price_required' => 'سعر الشراء مطلوب.',
        'purchase_price_min' => 'سعر الشراء لا يمكن أن يكون سلبيًا.',
        'selling_price_required' => 'سعر البيع مطلوب.',
        'selling_price_min' => 'سعر البيع لا يمكن أن يكون سلبيًا.',
        'selling_price_gt' => 'سعر البيع يجب أن يكون أكبر من سعر الشراء.',
        'category_id_exists' => 'التصنيف المحدد غير موجود أو غير متاح.',
        'publication_year_range' => 'سنة النشر يجب أن تكون بين 1000 و2100.',
    ],

    'barcode_print' => [
        'items_required' => 'اختر منتجًا واحدًا على الأقل للطباعة.',
        'items_max' => 'لا يمكن طباعة أكثر من 200 منتج في المرة الواحدة.',
        'product_missing' => 'أحد المنتجات المحددة غير موجود.',
        'copies_range' => 'عدد النسخ يجب أن يكون بين 1 و500.',
    ],

    'parent_must_be_top_level' => 'التصنيف الأب يجب أن يكون تصنيفًا رئيسيًا فقط.',
    'parent_invalid' => 'لا يمكن أن يكون التصنيف أبًا لنفسه.',
    'delete_has_children' => 'لا يمكن حذف تصنيف لديه تصنيفات فرعية.',

];
