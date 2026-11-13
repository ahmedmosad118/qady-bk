<?php

declare(strict_types=1);

return [
    'warehouse' => 'المخزن',
    'warehouses' => 'المخازن',
    'warehouse_updated_successfully' => 'تم تحديث بيانات المخزن بنجاح.',
    'document_created_successfully' => 'تم إنشاء المستند المخزني بنجاح كمسودة.',
    'document_updated_successfully' => 'تم تحديث المستند المخزني بنجاح.',
    'document_deleted_successfully' => 'تم حذف المسودة بنجاح.',

    'types' => [
        'goods_receipt' => 'إذن استلام مخزني',
        'goods_delivery' => 'إذن تسليم مخزني',
        'stock_issue' => 'إذن صرف داخلي',
        'warehouse_transfer' => 'إذن تحويل مخزني',
        'stock_adjustment' => 'إذن تسوية جردية',
        'inventory_return' => 'إذن مرتجع مخزني',
        'stock_write_off' => 'إذن إتلاف / هالك',
        'opening_balance' => 'رصيد افتتاحي',
    ],

    'statuses' => [
        'draft' => 'مسودة',
        'pending_approval' => 'قيد الاعتماد',
        'approved' => 'معتمد',
        'posted' => 'مرحّل',
        'rejected' => 'مرفوض',
        'cancelled' => 'ملغي',
        'reversed' => 'معكوس',
    ],

    'adjustment_directions' => [
        'increase' => 'تسوية بالزيادة (إضافة)',
        'decrease' => 'تسوية بالعجز (خصم)',
    ],

    'return_directions' => [
        'inbound' => 'مرتجع وارد إلى المخزن',
        'outbound' => 'مرتجع صادر من المخزن',
    ],

    'workflow' => [
        'submitted_successfully' => 'تم إرسال المستند للاعتماد بنجاح.',
        'approved_successfully' => 'تم اعتماد المستند بنجاح.',
        'rejected_successfully' => 'تم رفض المستند بنجاح.',
        'cancelled_successfully' => 'تم إلغاء المستند بنجاح.',
        'posted_successfully' => 'تم ترحيل المستند بنجاح.',
        'reversed_successfully' => 'تم عكس المستند المرحّل بنجاح.',
    ],

    'errors' => [
        'warehouse_not_found' => 'المخزن غير موجود.',
        'cannot_deactivate_default_warehouse' => 'لا يمكن تعطيل المخزن الافتراضي الوحيد للنظام.',
        'source_warehouse_required' => 'المخزن المصدر مطلوب لهذا النوع من المستندات.',
        'destination_warehouse_required' => 'المخزن المستهدف (الوجهة) مطلوب لهذا النوع من المستندات.',
        'source_warehouse_forbidden' => 'لا يسمح بتحديد مخزن مصدر لهذا النوع أو الاتجاه.',
        'destination_warehouse_forbidden' => 'لا يسمح بتحديد مخزن وجهة لهذا النوع أو الاتجاه.',
        'transfer_warehouses_must_differ' => 'يجب أن يكون المخزن المستهدف مختلفاً عن المخزن المصدر.',
        'source_warehouse_inactive' => 'المخزن المصدر المحدد غير نشط حالياً.',
        'destination_warehouse_inactive' => 'المخزن المستهدف المحدد غير نشط حالياً.',
        'adjustment_direction_required' => 'اتجاه التسوية (زيادة أو نقص) مطلوب لإذن التسوية.',
        'adjustment_direction_forbidden' => 'لا يمكن تحديد اتجاه تسوية مع هذا النوع من المستندات.',
        'return_direction_required' => 'اتجاه المرتجع (وارد أو صادر) مطلوب لإذن المرتجع.',
        'return_direction_forbidden' => 'لا يمكن تحديد اتجاه مرتجع مع هذا النوع من المستندات.',
        'write_off_reason_required' => 'سبب الإتلاف مطلوب لإذن الإتلاف / الهالك.',
        'contains_inactive_products' => 'تحتوي التفاصيل على منتجات غير نشطة أو غير متاحة.',
        'cannot_edit_non_draft_document' => 'لا يمكن تعديل المستند في حالته الحالية (:status). التعديل متاح فقط للمسودات والمستندات المرفوضة.',
        'cannot_delete_non_draft' => 'لا يمكن حذف إلا المسودات فقط.',
        'document_has_no_items' => 'لا يمكن إرسال مستند خالٍ من عناصر المنتجات.',
        'cannot_submit_status' => 'لا يمكن إرسال المستند للاعتماد وهو بالحالة (:status).',
        'cannot_approve_status' => 'لا يمكن اعتماد المستند وهو بالحالة (:status).',
        'cannot_reject_status' => 'لا يمكن رفض المستند وهو بالحالة (:status).',
        'rejection_reason_required' => 'سبب الرفض إجباري ومطلوب.',
        'cannot_cancel_status' => 'لا يمكن إلغاء المستند في حالته الحالية (:status).',
        'cannot_post_status' => 'لا يمكن ترحيل المستند إلا بعد اعتماده أولاً (الحالة الحالية: :status).',
        'cannot_reverse_status' => 'لا يمكن عكس إلا المستندات المرحّلة فقط (الحالة الحالية: :status).',
        'reversal_reason_required' => 'سبب العكس إجباري ومطلوب.',
    ],
];
