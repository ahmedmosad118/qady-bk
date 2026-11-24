<?php

declare(strict_types=1);

namespace Tests\Feature\Excel;

use App\Models\ProductCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ProductCategoryExcelTest extends TestCase
{
    use RefreshDatabase;

    private function createUploadedSpreadsheet(array $rows, string $filename = 'categories.xlsx'): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($rows as $rowIndex => $row) {
            foreach (array_values($row) as $colIndex => $value) {
                $sheet->setCellValue([$colIndex + 1, $rowIndex + 1], $value);
            }
        }

        $tempPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('qady_cat_test_', true).'.xlsx';
        (new Xlsx($spreadsheet))->save($tempPath);

        return new UploadedFile(
            $tempPath,
            $filename,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );
    }

    public function test_categories_template_and_export_work_with_permissions(): void
    {
        $this->actingAsUserWithPermissions(['product_categories.view']);

        $parent = ProductCategory::factory()->create(['name' => 'القانون العام', 'is_active' => true]);
        ProductCategory::factory()->childOf($parent)->create(['name' => 'القانون الدستوري', 'is_active' => true]);

        $this->get('/api/excel/template/product-categories')->assertForbidden();

        $response = $this->withHeaders(['X-Locale' => 'ar'])
            ->get('/api/excel/export/product-categories?scope=sub');

        $response->assertOk();

        $spreadsheet = IOFactory::load($response->getFile()->getPathname());
        $rows = $spreadsheet->getActiveSheet()->toArray();

        $this->assertCount(2, $rows);
        $this->assertSame('القانون الدستوري', $rows[1][0]);
        $this->assertSame('القانون العام', $rows[1][1]);
    }

    public function test_categories_import_creates_top_level_and_in_file_subcategories(): void
    {
        $this->actingAsSuperAdmin();

        $existingParent = ProductCategory::factory()->create(['name' => 'التفسير', 'is_active' => true]);

        $headers = [
            __('excel.product_categories.columns.name', [], 'ar'),
            __('excel.product_categories.columns.parent', [], 'ar'),
            __('excel.product_categories.columns.is_active', [], 'ar'),
        ];

        $file = $this->createUploadedSpreadsheet([
            $headers,
            ['علوم القرآن', 'التفسير', 'نشط'],
            ['الأدب العربي', null, 'نشط'],
            ['الشعر الجاهلي', 'الأدب العربي', 'معطل'],
        ]);

        $response = $this->withHeaders(['X-Locale' => 'ar'])
            ->postJson('/api/excel/import/product-categories', ['excel_file' => $file]);

        $response->assertOk()
            ->assertJsonPath('data.imported', 3);

        $this->assertDatabaseHas('product_categories', [
            'name' => 'علوم القرآن',
            'parent_id' => $existingParent->id,
            'is_active' => 1,
        ]);

        $newParent = ProductCategory::where('name', 'الأدب العربي')->firstOrFail();
        $this->assertNull($newParent->parent_id);

        $this->assertDatabaseHas('product_categories', [
            'name' => 'الشعر الجاهلي',
            'parent_id' => $newParent->id,
            'is_active' => 0,
        ]);
    }

    public function test_categories_import_rejects_third_level_parent_and_duplicates(): void
    {
        $this->actingAsSuperAdmin();

        $top = ProductCategory::factory()->create(['name' => 'فقه']);
        ProductCategory::factory()->childOf($top)->create(['name' => 'فقه مالكي']);

        $headers = [
            __('excel.product_categories.columns.name', [], 'en'),
            __('excel.product_categories.columns.parent', [], 'en'),
            __('excel.product_categories.columns.is_active', [], 'en'),
        ];

        $file = $this->createUploadedSpreadsheet([
            $headers,
            ['قسم صحيح', null, 'Active'],
            ['قسم فرعي غير مسموح', 'فقه مالكي', 'Active'],
            ['فقه', null, 'Active'],
        ]);

        $this->withHeaders(['X-Locale' => 'en'])
            ->postJson('/api/excel/import/product-categories', ['excel_file' => $file])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['excel_file']);

        $this->assertDatabaseMissing('product_categories', ['name' => 'قسم صحيح']);
    }
}
