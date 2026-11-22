<?php

declare(strict_types=1);

namespace Tests\Feature\Expense;

use App\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseItem;
use App\Models\ExpenseItemAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExpenseAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private ExpenseCategory $category;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->user = $this->createSuperAdmin();
        $this->category = ExpenseCategory::factory()->create();
    }

    public function test_can_upload_multiple_attachments_to_multiple_items_on_private_disk(): void
    {
        Sanctum::actingAs($this->user);

        $file1 = UploadedFile::fake()->image('bill-1.jpg', 600, 400);
        $file2 = UploadedFile::fake()->create('invoice.pdf', 120, 'application/pdf');
        $file3 = UploadedFile::fake()->image('ticket.png', 300, 300);

        $res = $this->post('/api/expenses', [
            'expense_category_id' => $this->category->id,
            'expense_date' => '2026-11-15',
            'items' => [
                [
                    'name' => 'Electricity',
                    'amount' => 300,
                    'attachments' => [$file1, $file2],
                ],
                [
                    'name' => 'Transport',
                    'amount' => 50,
                    'attachments' => [$file3],
                ],
            ],
        ], ['Accept' => 'application/json']);

        $res->assertCreated()
            ->assertJsonCount(2, 'data.items.0.attachments')
            ->assertJsonCount(1, 'data.items.1.attachments');

        $expenseId = $res->json('data.id');
        $attachments = ExpenseItemAttachment::query()->get();
        $this->assertCount(3, $attachments);

        foreach ($attachments as $attachment) {
            $this->assertSame('local', $attachment->disk);
            Storage::disk('local')->assertExists($attachment->path);
            // Hidden path/disk never leaked in API JSON response
            $this->assertArrayNotHasKey('path', $res->json('data.items.0.attachments.0'));
            $this->assertArrayNotHasKey('disk', $res->json('data.items.0.attachments.0'));
        }

        // View & download attachment
        $itemId = $res->json('data.items.0.id');
        $attId = $res->json('data.items.0.attachments.0.id');

        $this->get("/api/expenses/{$expenseId}/items/{$itemId}/attachments/{$attId}/view")
            ->assertOk();

        $this->get("/api/expenses/{$expenseId}/items/{$itemId}/attachments/{$attId}/download")
            ->assertOk()
            ->assertDownload('bill-1.jpg');
    }

    public function test_validates_attachment_file_type_and_size(): void
    {
        Sanctum::actingAs($this->user);

        $badFile = UploadedFile::fake()->create('script.exe', 100, 'application/x-msdownload');
        $oversizeFile = UploadedFile::fake()->create('huge.pdf', 5000, 'application/pdf');

        $this->post('/api/expenses', [
            'expense_category_id' => $this->category->id,
            'expense_date' => '2026-11-15',
            'items' => [
                [
                    'name' => 'Bad MIME',
                    'amount' => 100,
                    'attachments' => [$badFile],
                ],
            ],
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.attachments.0']);

        $this->post('/api/expenses', [
            'expense_category_id' => $this->category->id,
            'expense_date' => '2026-11-15',
            'items' => [
                [
                    'name' => 'Too Large',
                    'amount' => 100,
                    'attachments' => [$oversizeFile],
                ],
            ],
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.attachments.0']);
    }

    public function test_preserves_existing_attachments_unless_explicitly_removed_on_draft_update(): void
    {
        Sanctum::actingAs($this->user);

        $keepFile = UploadedFile::fake()->image('keep.jpg');
        $removeFile = UploadedFile::fake()->image('remove.png');

        $createRes = $this->post('/api/expenses', [
            'expense_category_id' => $this->category->id,
            'expense_date' => '2026-11-15',
            'items' => [
                [
                    'name' => 'Office Supplies',
                    'amount' => 100,
                    'attachments' => [$keepFile, $removeFile],
                ],
            ],
        ], ['Accept' => 'application/json'])->assertCreated();

        $expenseId = $createRes->json('data.id');
        $itemId = $createRes->json('data.items.0.id');
        $keepId = $createRes->json('data.items.0.attachments.0.id');
        $removeId = $createRes->json('data.items.0.attachments.1.id');

        $removePath = ExpenseItemAttachment::query()->findOrFail($removeId)->path;
        $keepPath = ExpenseItemAttachment::query()->findOrFail($keepId)->path;

        // First update without specifying removal preserves both
        $this->putJson("/api/expenses/{$expenseId}", [
            'expense_category_id' => $this->category->id,
            'expense_date' => '2026-11-15',
            'items' => [
                ['id' => $itemId, 'name' => 'Office Supplies Updated', 'amount' => 110],
            ],
        ])->assertOk()
            ->assertJsonCount(2, 'data.items.0.attachments');

        // Second update explicitly removing one + adding a new attachment
        $newFile = UploadedFile::fake()->create('extra.pdf', 80, 'application/pdf');
        $updateRes = $this->post("/api/expenses/{$expenseId}", [
            '_method' => 'PUT',
            'expense_category_id' => $this->category->id,
            'expense_date' => '2026-11-15',
            'items' => [
                [
                    'id' => $itemId,
                    'name' => 'Office Supplies Updated',
                    'amount' => 120,
                    'remove_attachment_ids' => [$removeId],
                    'attachments' => [$newFile],
                ],
            ],
        ], ['Accept' => 'application/json']);

        $updateRes->assertOk()
            ->assertJsonCount(2, 'data.items.0.attachments');

        $this->assertDatabaseHas('expense_item_attachments', ['id' => $keepId]);
        $this->assertDatabaseMissing('expense_item_attachments', ['id' => $removeId]);
        Storage::disk('local')->assertExists($keepPath);
        Storage::disk('local')->assertMissing($removePath);
    }

    public function test_deleting_draft_expense_deletes_all_physical_attachment_files(): void
    {
        Sanctum::actingAs($this->user);

        $file = UploadedFile::fake()->image('to-delete.png');
        $createRes = $this->post('/api/expenses', [
            'expense_category_id' => $this->category->id,
            'expense_date' => '2026-11-15',
            'items' => [
                ['name' => 'Temp Item', 'amount' => 45, 'attachments' => [$file]],
            ],
        ], ['Accept' => 'application/json'])->assertCreated();

        $expenseId = $createRes->json('data.id');
        $attId = $createRes->json('data.items.0.attachments.0.id');
        $path = ExpenseItemAttachment::query()->findOrFail($attId)->path;

        Storage::disk('local')->assertExists($path);

        $this->deleteJson("/api/expenses/{$expenseId}")->assertOk();

        $this->assertDatabaseMissing('expense_item_attachments', ['id' => $attId]);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_prevents_idor_and_unauthorized_attachment_access_and_posted_mutations(): void
    {
        Sanctum::actingAs($this->user);

        $fileA = UploadedFile::fake()->image('a.png');
        $resA = $this->post('/api/expenses', [
            'expense_category_id' => $this->category->id,
            'expense_date' => '2026-11-15',
            'items' => [['name' => 'Item A', 'amount' => 25, 'attachments' => [$fileA]]],
        ], ['Accept' => 'application/json'])->assertCreated();

        $expenseAId = $resA->json('data.id');
        $itemAId = $resA->json('data.items.0.id');
        $attAId = $resA->json('data.items.0.attachments.0.id');

        $expenseB = Expense::factory()->create([
            'expense_category_id' => $this->category->id,
            'created_by' => $this->user->id,
        ]);
        $itemB = ExpenseItem::factory()->create(['expense_id' => $expenseB->id, 'amount' => 30]);

        // Cross-expense item/attachment access returns 404 (IDOR protection)
        $this->getJson("/api/expenses/{$expenseB->id}/items/{$itemAId}/attachments/{$attAId}/view")
            ->assertNotFound();
        $this->getJson("/api/expenses/{$expenseAId}/items/{$itemB->id}/attachments/{$attAId}/view")
            ->assertNotFound();
        $this->deleteJson("/api/expenses/{$expenseB->id}/items/{$itemAId}/attachments/{$attAId}")
            ->assertNotFound();

        // Submitting foreign attachment ID in remove_attachment_ids fails validation
        $this->putJson("/api/expenses/{$expenseB->id}", [
            'expense_category_id' => $this->category->id,
            'expense_date' => '2026-11-15',
            'removed_attachment_ids' => [$attAId],
            'items' => [['id' => $itemB->id, 'name' => 'Item B', 'amount' => 30]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['removed_attachment_ids']);

        // Posted expense rejects attachment upload & attachment deletion
        Expense::query()->whereKey($expenseAId)->update(['status' => ExpenseStatus::POSTED->value]);

        $this->post("/api/expenses/{$expenseAId}/items/{$itemAId}/attachments", [
            'attachments' => [UploadedFile::fake()->image('extra.png')],
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->deleteJson("/api/expenses/{$expenseAId}/items/{$itemAId}/attachments/{$attAId}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        // Unauthorized user cannot view or download
        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/expenses/{$expenseAId}/items/{$itemAId}/attachments/{$attAId}/view")
            ->assertForbidden();
        $this->getJson("/api/expenses/{$expenseAId}/items/{$itemAId}/attachments/{$attAId}/download")
            ->assertForbidden();
    }
}
