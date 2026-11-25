<?php

declare(strict_types=1);

return [
    'errors' => [
        'invalid_payment_type' => 'يُدعم فقط سداد الموردين (send) في هذه المرحلة.',
        'invalid_related_type' => 'يجب تحديد نوع الطرف كمورد (supplier) لسداد الموردين.',
        'account_id_must_be_null' => 'لا يجوز تحديد حساب مباشر (account_id) في مدفوعات الموردين.',
        'supplier_invalid' => 'المورد المحدد غير موجود أو غير نشط.',
        'payment_method_invalid' => 'طريقة الدفع المحددة غير صالحة أو غير نشطة.',
        'payment_method_account_invalid' => 'الحساب المرتبط بطريقة الدفع يجب أن يكون حساب نقدية أو بنك نشط.',
        'journal_invalid' => 'دفتر اليومية المحدد يجب أن يكون دفتر نقدية أو بنك نشط.',
        'amount_must_be_positive' => 'يجب أن يكون مبلغ الدفع أكبر من الصفر.',
        'allocations_required' => 'يجب تخصيص مبلغ الدفع على فاتورة شراء واحدة على الأقل.',
        'duplicate_invoice_allocation' => 'لا يمكن تكرار نفس فاتورة الشراء في نفس الدفعة.',
        'allocated_amount_must_be_positive' => 'يجب أن يكون المبلغ المخصص أكبر من الصفر.',
        'invoice_not_found' => 'فاتورة الشراء المحددة غير موجودة.',
        'invoice_not_posted' => 'يُسمح بالتخصيص على فواتير الشراء المرحلة فقط.',
        'invoice_supplier_mismatch' => 'يجب أن تنتمي جميع الفواتير المخصصة لنفس المورد المحدد.',
        'allocation_exceeds_outstanding' => 'المبلغ المخصص يتجاوز الرصيد المتبقي المستحق للفاتورة.',
        'allocations_total_mismatch' => 'يجب أن يتطابق إجمالي المبالغ المخصصة مع مبلغ الدفعة بالكامل.',
        'only_draft_can_be_updated' => 'يمكن تعديل المدفوعات المسودة فقط.',
        'only_draft_can_be_posted' => 'يمكن ترحيل المدفوعات المسودة فقط.',
        'only_draft_can_be_cancelled' => 'يمكن إلغاء المدفوعات المسودة فقط.',
        'only_draft_can_be_deleted' => 'يمكن حذف المدفوعات المسودة فقط.',
        'supplier_payable_account_missing' => 'لا يوجد حساب ذمم دائنة للمورد أو حساب افتراضي في إعدادات الحسابات.',
        'supplier_payable_account_invalid' => 'حساب الذمم الدائنة المحدد غير نشط أو غير متوافق.',
    ],
    'labels' => [
        'supplier_payment_entry_description' => 'سداد مورد :number',
    ],
    'messages' => [
        'created' => 'تم إنشاء مسودة دفعة المورد بنجاح.',
        'updated' => 'تم تحديث مسودة دفعة المورد بنجاح.',
        'posted' => 'تم ترحيل دفعة المورد بنجاح.',
        'cancelled' => 'تم إلغاء دفعة المورد بنجاح.',
        'deleted' => 'تم حذف دفعة المورد بنجاح.',
    ],
];
