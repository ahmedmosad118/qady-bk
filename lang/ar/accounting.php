<?php

declare(strict_types=1);

return [
    'types' => [
        'asset' => 'الأصول',
        'liability' => 'الالتزامات',
        'equity' => 'حقوق الملكية',
        'revenue' => 'الإيرادات',
        'expense' => 'المصروفات',
    ],

    'purposes' => [
        'cash' => 'نقدية (صندوق)',
        'bank' => 'بنكي',
        'general' => 'عام',
    ],

    'attributes' => [
        'code' => 'كود الحساب',
        'name' => 'اسم الحساب',
        'account_type' => 'نوع الحساب',
        'account_type_id' => 'نوع الحساب',
        'normal_balance_override' => 'الرصيد الطبيعي (تخصيص)',
        'reconcile' => 'قابل للمطابقة',
        'is_active' => 'نشط',
        'account_purpose' => 'تصنيف الحساب',
        'description' => 'الوصف',
        'settings' => 'الإعدادات المحاسبية',
        'account_id' => 'الحساب المحاسبي',
        'chart_of_account_id' => 'الحساب المحاسبي',
        'type' => 'نوع طريقة الدفع',
    ],

    'messages' => [
        'account_created' => 'تم إنشاء الحساب المحاسبي بنجاح.',
        'account_updated' => 'تم تحديث الحساب المحاسبي بنجاح.',
        'account_deleted' => 'تم حذف الحساب المحاسبي بنجاح.',
        'settings_updated' => 'تم تحديث الإعدادات المحاسبية بنجاح.',
        'payment_method_created' => 'تم إنشاء طريقة الدفع بنجاح.',
        'payment_method_updated' => 'تم تحديث طريقة الدفع بنجاح.',
        'journal_created' => 'تم إنشاء دفتر اليومية بنجاح.',
        'journal_updated' => 'تم تحديث دفتر اليومية بنجاح.',
        'journal_deleted' => 'تم حذف دفتر اليومية بنجاح.',
        'fiscal_year_created' => 'تم إنشاء السنة المالية بنجاح.',
        'fiscal_year_updated' => 'تم تحديث السنة المالية بنجاح.',
        'fiscal_year_deleted' => 'تم حذف السنة المالية بنجاح.',
        'accounting_period_created' => 'تم إنشاء الفترة المحاسبية بنجاح.',
        'accounting_period_updated' => 'تم تحديث الفترة المحاسبية بنجاح.',
        'accounting_period_deleted' => 'تم حذف الفترة المحاسبية بنجاح.',
    ],

    'errors' => [
        'code_duplicate' => 'كود الحساب مستخدم بالفعل.',
        'purpose_requires_asset_type' => 'التصنيف النقدي أو البنكي متاح فقط لحسابات الأصول.',
        'delete_linked_to_settings' => 'لا يمكن حذف حساب مرتبط بإعدادات النظام المحاسبية.',
        'delete_linked_to_payment_method' => 'لا يمكن حذف حساب مرتبط بطريقة دفع.',
        'delete_linked_to_contact' => 'لا يمكن حذف حساب مرتبط بعميل أو مورد.',
        'account_type_inactive' => 'نوع الحساب المحدد غير نشط.',
        'system_account_type_locked' => 'لا يمكن تغيير نوع حساب نظامي.',
        'system_account_code_locked' => 'لا يمكن تغيير كود حساب نظامي.',
        'system_account_cannot_deactivate' => 'لا يمكن تعطيل حساب نظامي.',
        'system_account_cannot_delete' => 'لا يمكن حذف حساب نظامي.',
        'payment_method_account_type_locked' => 'لا يمكن تغيير نوع حساب مرتبط بطريقة دفع؛ يجب أن يظل من نوع الأصول.',
        'payment_method_account_purpose_locked' => 'لا يمكن تغيير تصنيف الحساب لأنه مرتبط بطريقة دفع غير متوافقة معه.',
        'setting_key_invalid' => 'مفتاح الإعداد المحاسبي (:key) غير معرف.',
        'setting_required_missing' => 'الحقل المحاسبي الإلزامي (:label) مطلوب ولا يمكن حذفه.',
        'setting_account_not_found' => 'الحساب المحدد للمفتاح (:label) غير موجود.',
        'setting_account_type_mismatch' => 'الحساب المحدد للمفتاح (:label) يجب أن يكون من نوع (:type).',
        'setting_account_cannot_be_cash_or_bank' => 'لا يمكن ربط الإعداد (:label) بحساب مخصص للصندوق أو البنك.',
        'setting_account_purpose_locked' => 'لا يمكن تغيير تصنيف حساب مرتبط بإعدادات النظام المحاسبية.',
        'setting_account_duplicate' => 'لا يمكن تعيين نفس الحساب لأكثر من وظيفة محاسبية (:label).',
        'payment_method_account_required' => 'يجب ربط طريقة الدفع بحساب محاسبي متوافق.',
        'payment_method_account_not_found' => 'الحساب المحاسبي المحدد لطريقة الدفع غير موجود.',
        'payment_method_account_inactive' => 'يجب أن يكون الحساب المرتبط بطريقة الدفع نشطًا.',
        'payment_method_account_must_be_bank_cash' => 'يجب أن يكون الحساب المرتبط بطريقة الدفع من نوع النقدية والبنوك (bank_cash).',
        'payment_method_account_must_be_asset' => 'يجب أن يكون الحساب المرتبط بطريقة الدفع من نوع الأصول.',
        'payment_method_account_purpose_mismatch' => 'يجب أن يكون تصنيف الحساب المرتبط بطريقة الدفع (:purpose).',
        'journal_code_duplicate' => 'كود دفتر اليومية مستخدم بالفعل.',
        'fiscal_year_code_duplicate' => 'كود السنة المالية مستخدم بالفعل.',
        'fiscal_year_invalid_date_range' => 'تاريخ نهاية السنة المالية يجب أن يكون مساويًا أو لاحقًا لتاريخ البداية.',
        'fiscal_year_overlap' => 'تتداخل تواريخ السنة المالية مع سنة مالية أخرى مسجلة.',
        'fiscal_year_closed_immutable' => 'لا يمكن تعديل سنة مالية مغلقة.',
        'fiscal_year_shrink_excludes_periods' => 'لا يمكن تقليص نطاق السنة المالية بحيث تخرج فترات محاسبية مسجلة خارج حدودها.',
        'fiscal_year_close_requires_closed_periods' => 'لا يمكن إغلاق السنة المالية قبل إغلاق جميع فتراتها المحاسبية.',
        'fiscal_year_has_periods' => 'لا يمكن حذف سنة مالية تحتوي على فترات محاسبية.',
        'fiscal_year_closed_for_periods' => 'لا يمكن إضافة أو تعديل فترات داخل سنة مالية مغلقة.',
        'period_invalid_date_range' => 'تاريخ نهاية الفترة يجب أن يكون مساويًا أو لاحقًا لتاريخ البداية.',
        'period_outside_fiscal_year' => 'يجب أن تقع الفترة المحاسبية بالكامل داخل نطاق السنة المالية التابعة لها.',
        'period_number_duplicate' => 'رقم الفترة مستخدم بالفعل داخل هذه السنة المالية.',
        'period_overlap' => 'تتداخل تواريخ الفترة المحاسبية مع فترة أخرى داخل نفس السنة المالية.',
        'period_closed_cannot_delete' => 'لا يمكن حذف فترة محاسبية مغلقة.',
    ],

    'settings' => [
        'keys' => [
            'inventory' => [
                'label' => 'حساب المخزون',
                'description' => 'الحساب المحاسبي الخاص بقيمة مخزون الكتب والمنتجات.',
            ],
            'capital' => [
                'label' => 'حساب رأس المال',
                'description' => 'الحساب الخاص برأس مال المكتبة والأرصدة الافتتاحية.',
            ],
            'owner_current' => [
                'label' => 'حساب جاري المالك / الشريك',
                'description' => 'الحساب المستخدم لتسجيل مسحوبات وإيداعات المالك في الورديات.',
            ],
            'sales_revenue' => [
                'label' => 'حساب مبيعات الكتب',
                'description' => 'الحساب المخصص لإيرادات مبيعات الكتب والمنتجات.',
            ],
            'sales_returns' => [
                'label' => 'حساب مردودات المبيعات',
                'description' => 'الحساب المخصص لتسجيل مرتجعات المبيعات في نقاط البيع.',
            ],
            'cost_of_goods_sold' => [
                'label' => 'حساب تكلفة البضاعة المباعة',
                'description' => 'الحساب المحمل بتكلفة الكتب والمنتجات المباعة والمرتجعة مخزنيًا.',
            ],
            'operating_expenses' => [
                'label' => 'حساب المصروفات التشغيلية',
                'description' => 'الحساب المخصص للمصروفات التشغيلية ومصروفات الشراء المستقلة والورديات.',
            ],
        ],
    ],
];
