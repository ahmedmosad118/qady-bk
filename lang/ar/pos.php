<?php

declare(strict_types=1);

return [
    'unit_piece' => 'قطعة',

    'messages' => [
        'sale_created' => 'تم إنشاء وترحيل فاتورة البيع وخصم المخزون بنجاح.',
        'cash_sale_transaction' => 'مبيعات نقدية - فاتورة :invoice_number',
        'auto_goods_delivery_notes' => 'إذن تسليم مخزني تلقائي لفاتورة بيع نقطة البيع رقم: :invoice_number',
    ],

    'errors' => [
        'shift_not_found' => 'الوردية المحددة غير موجودة.',
        'shift_closed' => 'الوردية المحددة مغلقة. لا يمكن إتمام عملية البيع على وردية مغلقة.',
        'shift_belongs_to_another_user' => 'لا يمكنك استخدام وردية خاصة بمستخدم آخر.',
        'user_already_has_open_shift' => 'لديك وردية مفتوحة بالفعل (:number). يجب إغلاقها أولاً قبل فتح وردية جديدة.',
        'payment_method_not_found' => 'طريقة الدفع المحددة غير صالحة أو غير موجودة.',
        'default_warehouse_missing' => 'المخزن الافتراضي غير محدد أو غير موجود في النظام.',
        'customer_not_found' => 'العميل المحدد غير موجود.',
        'items_required' => 'يجب إضافة صنف واحد على الأقل في الفاتورة.',
        'invalid_product_id' => 'معرف الصنف غير صالح.',
        'product_not_found' => 'المنتج المطلوب (# :id) غير موجود.',
        'invalid_item_quantity' => 'الكمية لكل صنف يجب أن تكون رقمًا عشريًا صحيحًا أكبر من الصفر.',
        'invalid_unit_price' => 'سعر البيع للصنف يجب أن يكون رقمًا عشريًا غير سالب.',
        'negative_unit_price' => "سعر البيع للصنف ':product' لا يمكن أن يكون سالبًا.",
        'invalid_item_discount_type' => 'نوع الخصم على الصنف غير صالح.',
        'invalid_item_discount_value' => 'قيمة الخصم على الصنف غير صالحة.',
        'negative_item_discount' => 'قيمة الخصم على الصنف لا يمكن أن تكون سالبة.',
        'item_discount_percentage_range' => 'نسبة الخصم على الصنف يجب أن تكون بين 0 و 100.',
        'invalid_invoice_discount_type' => 'نوع خصم الفاتورة غير صالح.',
        'invalid_invoice_discount_value' => 'قيمة خصم الفاتورة غير صالحة.',
        'negative_invoice_discount' => 'قيمة خصم الفاتورة لا يمكن أن تكون سالبة.',
        'invoice_discount_percentage_range' => 'نسبة خصم الفاتورة يجب أن تكون بين 0 و 100.',
        'barcode_required' => 'يرجى إدخال الباركود للبحث.',
        'barcode_not_found' => 'لم يتم العثور على أي منتج مرتبط بهذا الباركود.',
    ],
];
