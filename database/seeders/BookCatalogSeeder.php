<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BookCatalogSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            $catalogData = [
                [
                    'name' => 'الكتب القانونية والتشريعات',
                    'children' => [
                        [
                            'name' => 'القانون المدني والتجاري',
                            'books' => [
                                [
                                    'name' => 'الوسيط في شرح القانون المدني الجديد - نظرية الالتزام',
                                    'sku' => 'LBK-LAW-001',
                                    'barcode' => '9789770110011',
                                    'author' => 'د. عبد الرزاق أحمد السنهوري',
                                    'publisher' => 'مكتبة القاضي',
                                    'edition' => 'الطبعة الحديثة المنقحة',
                                    'publication_year' => 2024,
                                    'purchase_price' => 320.00,
                                    'selling_price' => 450.00,
                                ],
                                [
                                    'name' => 'شرح القانون التجاري والشركات التجارية في القانون المصري',
                                    'sku' => 'LBK-LAW-002',
                                    'barcode' => '9789770110028',
                                    'author' => 'د. مصطفى كمال طه',
                                    'publisher' => 'مكتبة القاضي',
                                    'edition' => 'الطبعة الثالثة',
                                    'publication_year' => 2023,
                                    'purchase_price' => 190.00,
                                    'selling_price' => 270.00,
                                ],
                                [
                                    'name' => 'صياغة العقود التجارية والمدنية وتطبيقاتها القضائية',
                                    'sku' => 'LBK-LAW-003',
                                    'barcode' => '9789770110035',
                                    'author' => 'المستشار أنور طلبة',
                                    'publisher' => 'مكتبة القاضي',
                                    'edition' => 'الطبعة الأولى',
                                    'publication_year' => 2025,
                                    'purchase_price' => 220.00,
                                    'selling_price' => 310.00,
                                ],
                            ],
                        ],
                        [
                            'name' => 'القانون الجنائي والإجراءات الجزائية',
                            'books' => [
                                [
                                    'name' => 'شرح قانون العقوبات - القسم العام',
                                    'sku' => 'LBK-LAW-004',
                                    'barcode' => '9789770110042',
                                    'author' => 'د. محمود نجيب حسني',
                                    'publisher' => 'دار الفكر العربي والقانون',
                                    'edition' => 'الطبعة الرابعة',
                                    'publication_year' => 2023,
                                    'purchase_price' => 240.00,
                                    'selling_price' => 340.00,
                                ],
                                [
                                    'name' => 'أصول الإجراءات الجنائية والتحقيق القضائي',
                                    'sku' => 'LBK-LAW-005',
                                    'barcode' => '9789770110059',
                                    'author' => 'د. أحمد فتحي سرور',
                                    'publisher' => 'دار النهضة العربية',
                                    'edition' => 'الطبعة الثانية',
                                    'publication_year' => 2024,
                                    'purchase_price' => 280.00,
                                    'selling_price' => 390.00,
                                ],
                            ],
                        ],
                        [
                            'name' => 'القضاء الإداري والدستوري',
                            'books' => [
                                [
                                    'name' => 'مبادئ القضاء الإداري ودعوى الإلغاء في أحكام مجلس الدولة',
                                    'sku' => 'LBK-LAW-006',
                                    'barcode' => '9789770110066',
                                    'author' => 'د. سليمان الطماوي',
                                    'publisher' => 'مكتبة القاضي',
                                    'edition' => 'طبعة مراجعة ومحدثة',
                                    'publication_year' => 2024,
                                    'purchase_price' => 210.00,
                                    'selling_price' => 295.00,
                                ],
                            ],
                        ],
                    ],
                ],
                [
                    'name' => 'الدراسات الإسلامية وأصول الفقه',
                    'children' => [
                        [
                            'name' => 'الفقه المقارن وأصول الفقه',
                            'books' => [
                                [
                                    'name' => 'بداية المجتهد ونهاية المقتصد (مجلدان فخمان)',
                                    'sku' => 'LBK-ISL-001',
                                    'barcode' => '9789770220010',
                                    'author' => 'ابن رشد الحفيد الأندلسي',
                                    'publisher' => 'دار الحديث للطباعة',
                                    'edition' => 'طبعة محققة بالأدلة',
                                    'publication_year' => 2023,
                                    'purchase_price' => 450.00,
                                    'selling_price' => 620.00,
                                ],
                                [
                                    'name' => 'الرسالة في أصول الفقه',
                                    'sku' => 'LBK-ISL-002',
                                    'barcode' => '9789770220027',
                                    'author' => 'الإمام محمد بن إدريس الشافعي',
                                    'publisher' => 'مكتبة الحلبي',
                                    'edition' => 'تحقيق الشيخ أحمد شاكر',
                                    'publication_year' => 2022,
                                    'purchase_price' => 130.00,
                                    'selling_price' => 190.00,
                                ],
                            ],
                        ],
                        [
                            'name' => 'التفسير وعلوم القرآن',
                            'books' => [
                                [
                                    'name' => 'تفسير ابن كثير (تفسير القرآن العظيم - 4 أجزاء)',
                                    'sku' => 'LBK-ISL-003',
                                    'barcode' => '9789770220034',
                                    'author' => 'الحافظ إسماعيل بن كثير',
                                    'publisher' => 'دار المعارف للطباعة',
                                    'edition' => 'الطبعة المحققة الفاخرة',
                                    'publication_year' => 2024,
                                    'purchase_price' => 700.00,
                                    'selling_price' => 950.00,
                                ],
                                [
                                    'name' => 'الإتقان في علوم القرآن',
                                    'sku' => 'LBK-ISL-004',
                                    'barcode' => '9789770220041',
                                    'author' => 'الإمام جلال الدين السيوطي',
                                    'publisher' => 'دار الكتب العلمية',
                                    'edition' => 'طبعة مجلد واحد',
                                    'publication_year' => 2023,
                                    'purchase_price' => 180.00,
                                    'selling_price' => 250.00,
                                ],
                            ],
                        ],
                    ],
                ],
                [
                    'name' => 'الأدب والروايات والمعاجم',
                    'children' => [
                        [
                            'name' => 'الروايات والأدب العربي',
                            'books' => [
                                [
                                    'name' => 'ثلاثية القاهرة (بين القصرين - قصر الشوق - السكرية)',
                                    'sku' => 'LBK-LIT-001',
                                    'barcode' => '9789770330019',
                                    'author' => 'نجيب محفوظ',
                                    'publisher' => 'دار الشروق',
                                    'edition' => 'طبعة الأعمال الكاملة',
                                    'publication_year' => 2024,
                                    'purchase_price' => 260.00,
                                    'selling_price' => 360.00,
                                ],
                                [
                                    'name' => 'ثلاثية غرناطة',
                                    'sku' => 'LBK-LIT-002',
                                    'barcode' => '9789770330026',
                                    'author' => 'رضوى عاشور',
                                    'publisher' => 'دار الشروق',
                                    'edition' => 'الطبعة العاشرة',
                                    'publication_year' => 2023,
                                    'purchase_price' => 110.00,
                                    'selling_price' => 160.00,
                                ],
                                [
                                    'name' => 'الأيام (الأجزاء الثلاثة كاملة)',
                                    'sku' => 'LBK-LIT-003',
                                    'barcode' => '9789770330033',
                                    'author' => 'د. طه حسين',
                                    'publisher' => 'دار المعارف المصرية',
                                    'edition' => 'طبعة تذكارية',
                                    'publication_year' => 2023,
                                    'purchase_price' => 95.00,
                                    'selling_price' => 140.00,
                                ],
                            ],
                        ],
                        [
                            'name' => 'المعاجم والموسوعات اللغوية',
                            'books' => [
                                [
                                    'name' => 'المعجم الوسيط (مجمع اللغة العربية)',
                                    'sku' => 'LBK-LIT-004',
                                    'barcode' => '9789770330040',
                                    'author' => 'لجنة من كبار اللغويين بمجمع القاهرة',
                                    'publisher' => 'مكتبة الشروق الدولية',
                                    'edition' => 'الطبعة الخامسة الموسعة',
                                    'publication_year' => 2022,
                                    'purchase_price' => 380.00,
                                    'selling_price' => 520.00,
                                ],
                                [
                                    'name' => 'مقدمة ابن خلدون (كتاب العبر وديوان المبتدأ والخبر)',
                                    'sku' => 'LBK-LIT-005',
                                    'barcode' => '9789770330057',
                                    'author' => 'عبد الرحمن بن خلدون',
                                    'publisher' => 'دار الفكر العربي',
                                    'edition' => 'طبعة محققة مع حواشٍ تاريخية',
                                    'publication_year' => 2024,
                                    'purchase_price' => 170.00,
                                    'selling_price' => 240.00,
                                ],
                            ],
                        ],
                    ],
                ],
                [
                    'name' => 'الإدارة والاقتصاد والمهارات',
                    'children' => [
                        [
                            'name' => 'إدارة الأعمال والمحاسبة',
                            'books' => [
                                [
                                    'name' => 'مبادئ الإدارة الاستراتيجية المعاصرة في الشركات والمؤسسات',
                                    'sku' => 'LBK-MGT-001',
                                    'barcode' => '9789770440018',
                                    'author' => 'د. أحمد سيد مصطفى',
                                    'publisher' => 'مكتبة القاضي',
                                    'edition' => 'الطبعة الثانية',
                                    'publication_year' => 2024,
                                    'purchase_price' => 145.00,
                                    'selling_price' => 210.00,
                                ],
                                [
                                    'name' => 'المحاسبة المالية المتقدمة وفق المعايير الدولية IFRS',
                                    'sku' => 'LBK-MGT-002',
                                    'barcode' => '9789770440025',
                                    'author' => 'د. محمد مطر',
                                    'publisher' => 'مكتبة الأنجلو المصرية',
                                    'edition' => 'الطبعة الرابعة',
                                    'publication_year' => 2025,
                                    'purchase_price' => 210.00,
                                    'selling_price' => 300.00,
                                ],
                            ],
                        ],
                    ],
                ],
            ];

            foreach ($catalogData as $parentCatData) {
                /** @var ProductCategory $parentCat */
                $parentCat = ProductCategory::query()->firstOrCreate(
                    ['name' => $parentCatData['name'], 'parent_id' => null],
                    ['is_active' => true]
                );

                foreach ($parentCatData['children'] as $childCatData) {
                    /** @var ProductCategory $subCat */
                    $subCat = ProductCategory::query()->firstOrCreate(
                        ['name' => $childCatData['name'], 'parent_id' => $parentCat->id],
                        ['is_active' => true]
                    );

                    foreach ($childCatData['books'] as $book) {
                        Product::query()->updateOrCreate(
                            ['sku' => $book['sku']],
                            [
                                'category_id' => $subCat->id,
                                'name' => $book['name'],
                                'barcode' => $book['barcode'],
                                'author' => $book['author'],
                                'publisher' => $book['publisher'],
                                'edition' => $book['edition'],
                                'publication_year' => $book['publication_year'],
                                'purchase_price' => $book['purchase_price'],
                                'selling_price' => $book['selling_price'],
                                'is_active' => true,
                            ]
                        );
                    }
                }
            }
        });
    }
}
