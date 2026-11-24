<?php

declare(strict_types=1);

namespace Tests\Feature\Excel;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ProductExcelTest extends TestCase
{
    use RefreshDatabase;

    private function createUploadedSpreadsheet(array $rows, string $filename = 'products.xlsx'): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($rows as $rowIndex => $row) {
            foreach (array_values($row) as $colIndex => $value) {
                $sheet->setCellValue([$colIndex + 1, $rowIndex + 1], $value);
            }
        }

        $tempPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('qady_test_', true).'.xlsx';
        (new Xlsx($spreadsheet))->save($tempPath);

        return new UploadedFile(
            $tempPath,
            $filename,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );
    }

    public function test_unauthenticated_and_unauthorized_requests_are_rejected(): void
    {
        $this->getJson('/api/excel/template/products')->assertUnauthorized();
        $this->getJson('/api/excel/export/products')->assertUnauthorized();
        $this->postJson('/api/excel/import/products')->assertUnauthorized();

        $this->actingAsUserWithPermissions(['products.view']);

        $this->getJson('/api/excel/export/products')->assertOk();
        $this->getJson('/api/excel/template/products')->assertForbidden();

        $file = $this->createUploadedSpreadsheet([['اسم المنتج']]);
        $this->postJson('/api/excel/import/products', ['excel_file' => $file])->assertForbidden();
    }

    public function test_unknown_excel_module_type_returns_not_found(): void
    {
        $this->actingAsSuperAdmin();

        $this->getJson('/api/excel/template/unknown-module')->assertNotFound();
    }

    public function test_products_template_downloads_with_rtl_headers_and_dropdowns(): void
    {
        $this->actingAsSuperAdmin();

        ProductCategory::factory()->create(['name' => 'القانون المدني', 'is_active' => true]);
        ProductCategory::factory()->inactive()->create(['name' => 'تصنيف معطل']);

        $response = $this->withHeaders(['X-Locale' => 'ar'])
            ->get('/api/excel/template/products');

        $response->assertOk();

        $spreadsheet = IOFactory::load($response->getFile()->getPathname());
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertTrue($sheet->getRightToLeft());
        $this->assertSame((string) __('excel.products.columns.name', [], 'ar'), $sheet->getCell('A1')->getValue());
        $this->assertSame((string) __('excel.products.columns.is_active', [], 'ar'), $sheet->getCell('K1')->getValue());
        $this->assertSame(DataValidation::TYPE_LIST, $sheet->getCell('C2')->getDataValidation()->getType());
        $this->assertSame(DataValidation::TYPE_LIST, $sheet->getCell('K2')->getDataValidation()->getType());
    }

    public function test_products_export_applies_filters_and_escapes_formula_injection(): void
    {
        $this->actingAsSuperAdmin();

        $fiqh = ProductCategory::factory()->create(['name' => 'الفقه']);
        $history = ProductCategory::factory()->create(['name' => 'التاريخ']);

        Product::factory()->create([
            'name' => '=SUM(A1:A2)',
            'barcode' => '9789770110059',
            'category_id' => $fiqh->id,
            'purchase_price' => '100.00',
            'selling_price' => '150.00',
            'is_active' => true,
        ]);

        Product::factory()->create([
            'name' => 'تاريخ الطبري',
            'barcode' => '9789770110066',
            'category_id' => $history->id,
            'purchase_price' => '80.00',
            'selling_price' => '120.00',
            'is_active' => false,
        ]);

        $response = $this->withHeaders(['X-Locale' => 'ar'])
            ->get('/api/excel/export/products?category_id='.$fiqh->id.'&is_active=1');

        $response->assertOk();

        $spreadsheet = IOFactory::load($response->getFile()->getPathname());
        $rows = $spreadsheet->getActiveSheet()->toArray();

        $this->assertCount(2, $rows);
        $this->assertSame("'=SUM(A1:A2)", $rows[1][0]);
        $this->assertSame('9789770110059', (string) $rows[1][1]);
        $this->assertSame('الفقه', $rows[1][2]);
    }

    public function test_products_import_creates_valid_rows_atomically(): void
    {
        $this->actingAsSuperAdmin();

        $category = ProductCategory::factory()->create(['name' => 'القانون الجنائي', 'is_active' => true]);

        $headers = [
            __('excel.products.columns.name', [], 'ar'),
            __('excel.products.columns.barcode', [], 'ar'),
            __('excel.products.columns.category', [], 'ar'),
            __('excel.products.columns.author', [], 'ar'),
            __('excel.products.columns.publisher', [], 'ar'),
            __('excel.products.columns.edition', [], 'ar'),
            __('excel.products.columns.publication_year', [], 'ar'),
            __('excel.products.columns.purchase_price', [], 'ar'),
            __('excel.products.columns.selling_price', [], 'ar'),
            __('excel.products.columns.show_in_shop', [], 'ar'),
            __('excel.products.columns.is_active', [], 'ar'),
        ];

        $file = $this->createUploadedSpreadsheet([
            $headers,
            ['أصول الإجراءات الجنائية', '9789770110099', 'القانون الجنائي', 'د. أحمد فتحي سرور', 'دار النهضة', 'الطبعة الأولى', 2022, '280', '390', 'نعم', 'نشط'],
            ['شرح القانون المدني', null, null, null, null, null, null, '100', '145.50', 'لا', 'معطل'],
        ]);

        $response = $this->withHeaders(['X-Locale' => 'ar'])
            ->postJson('/api/excel/import/products', ['excel_file' => $file]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.imported', 2);

        $this->assertDatabaseHas('products', [
            'name' => 'أصول الإجراءات الجنائية',
            'barcode' => '9789770110099',
            'category_id' => $category->id,
            'publication_year' => 2022,
            'show_in_shop' => 1,
            'is_active' => 1,
        ]);

        $this->assertDatabaseHas('products', [
            'name' => 'شرح القانون المدني',
            'barcode' => null,
            'category_id' => null,
            'show_in_shop' => 0,
            'is_active' => 0,
        ]);
    }

    public function test_products_import_rejects_mismatched_headers(): void
    {
        $this->actingAsSuperAdmin();

        $file = $this->createUploadedSpreadsheet([
            ['Wrong Header 1', 'Wrong Header 2'],
            ['Book', '123'],
        ]);

        $this->postJson('/api/excel/import/products', ['excel_file' => $file])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['excel_file']);
    }

    public function test_products_import_rolls_back_all_rows_and_reports_row_errors(): void
    {
        $this->actingAsSuperAdmin();

        Product::factory()->withBarcode('6221000000111')->create(['name' => 'منتج حالي']);

        $headers = [
            __('excel.products.columns.name', [], 'en'),
            __('excel.products.columns.barcode', [], 'en'),
            __('excel.products.columns.category', [], 'en'),
            __('excel.products.columns.author', [], 'en'),
            __('excel.products.columns.publisher', [], 'en'),
            __('excel.products.columns.edition', [], 'en'),
            __('excel.products.columns.publication_year', [], 'en'),
            __('excel.products.columns.purchase_price', [], 'en'),
            __('excel.products.columns.selling_price', [], 'en'),
            __('excel.products.columns.show_in_shop', [], 'en'),
            __('excel.products.columns.is_active', [], 'en'),
        ];

        $file = $this->createUploadedSpreadsheet([
            $headers,
            // Row 2: completely valid
            ['كتاب سليم', '6221000000222', null, null, null, null, null, '50', '80', 'Yes', 'Active'],
            // Row 3: duplicate DB barcode + selling <= purchase
            ['كتاب مكرر', '6221000000111', null, null, null, null, null, '100', '90', 'No', 'Active'],
            // Row 4: missing category + duplicate barcode inside file
            ['كتاب ثالث', '6221000000222', 'Missing Cat', null, null, null, null, '20', '40', 'No', 'Active'],
        ]);

        $response = $this->withHeaders(['X-Locale' => 'en'])
            ->postJson('/api/excel/import/products', ['excel_file' => $file]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['excel_file']);

        $errors = $response->json('errors.excel_file');
        $this->assertGreaterThanOrEqual(4, count($errors));

        // Ensure Row 2 was NOT persisted because the batch is atomic.
        $this->assertDatabaseMissing('products', ['barcode' => '6221000000222']);
    }
}
