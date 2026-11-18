<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\InventoryDocumentStatus;
use App\Enums\InventoryDocumentType;
use App\Models\InventoryDocument;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\WarehouseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryDocumentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Warehouse $warehouse;
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);

        $this->seed(WarehouseSeeder::class);
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::factory()->create(['is_active' => true]);
    }

    protected function createDraftDocument(): InventoryDocument
    {
        $response = $this->postJson('/api/inventory-documents', [
            'document_type' => InventoryDocumentType::GOODS_RECEIPT->value,
            'document_date' => '2026-11-13',
            'destination_warehouse_id' => $this->warehouse->id,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 15],
            ],
        ]);

        $response->assertStatus(201);
        return InventoryDocument::query()->findOrFail($response->json('data.id'));
    }

    public function test_full_happy_path_workflow_draft_to_posted(): void
    {
        $doc = $this->createDraftDocument();
        $this->assertEquals(InventoryDocumentStatus::DRAFT, $doc->status);

        // 1. Submit for approval
        $submitRes = $this->postJson("/api/inventory-documents/{$doc->id}/submit");
        $submitRes->assertOk()
            ->assertJsonPath('data.status', InventoryDocumentStatus::PENDING_APPROVAL->value)
            ->assertJsonPath('data.reviewed_by', $this->user->id);

        // 2. Approve
        $approveRes = $this->postJson("/api/inventory-documents/{$doc->id}/approve");
        $approveRes->assertOk()
            ->assertJsonPath('data.status', InventoryDocumentStatus::APPROVED->value)
            ->assertJsonPath('data.approved_by', $this->user->id);

        // 3. Post
        $postRes = $this->postJson("/api/inventory-documents/{$doc->id}/post");
        $postRes->assertOk()
            ->assertJsonPath('data.status', InventoryDocumentStatus::POSTED->value)
            ->assertJsonPath('data.posted_by', $this->user->id);

        $doc->refresh();
        $this->assertEquals(InventoryDocumentStatus::POSTED, $doc->status);
        $this->assertNotNull($doc->posted_at);
    }

    public function test_rejection_flow_and_resubmission(): void
    {
        $doc = $this->createDraftDocument();

        // Submit
        $this->postJson("/api/inventory-documents/{$doc->id}/submit")->assertOk();

        // Reject without reason must fail
        $failReject = $this->postJson("/api/inventory-documents/{$doc->id}/reject", [
            'rejection_reason' => '',
        ]);
        $failReject->assertStatus(422)
            ->assertJsonValidationErrors(['rejection_reason']);

        // Reject with reason
        $rejectRes = $this->postJson("/api/inventory-documents/{$doc->id}/reject", [
            'rejection_reason' => 'الكميات غير مطابقة للشحنة الموردة',
        ]);
        $rejectRes->assertOk()
            ->assertJsonPath('data.status', InventoryDocumentStatus::REJECTED->value)
            ->assertJsonPath('data.rejection_reason', 'الكميات غير مطابقة للشحنة الموردة');

        // Rejected document can be edited and resubmitted
        $updateRes = $this->putJson("/api/inventory-documents/{$doc->id}", [
            'document_type' => InventoryDocumentType::GOODS_RECEIPT->value,
            'document_date' => '2026-11-13',
            'destination_warehouse_id' => $this->warehouse->id,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 10], // corrected
            ],
        ]);
        $updateRes->assertOk();

        // Resubmit
        $resubmitRes = $this->postJson("/api/inventory-documents/{$doc->id}/submit");
        $resubmitRes->assertOk()
            ->assertJsonPath('data.status', InventoryDocumentStatus::PENDING_APPROVAL->value);
    }

    public function test_posted_document_can_be_reversed_with_reason(): void
    {
        $doc = $this->createDraftDocument();
        $this->postJson("/api/inventory-documents/{$doc->id}/submit")->assertOk();
        $this->postJson("/api/inventory-documents/{$doc->id}/approve")->assertOk();
        $this->postJson("/api/inventory-documents/{$doc->id}/post")->assertOk();

        // Reverse without reason must fail
        $this->postJson("/api/inventory-documents/{$doc->id}/reverse", [
            'reversal_reason' => '',
        ])->assertStatus(422)->assertJsonValidationErrors(['reversal_reason']);

        // Reverse with valid reason
        $reverseRes = $this->postJson("/api/inventory-documents/{$doc->id}/reverse", [
            'reversal_reason' => 'تم إلغاء التوريد بناءً على طلب الإدارة',
        ]);

        $reverseRes->assertOk()
            ->assertJsonPath('data.status', InventoryDocumentStatus::REVERSED->value)
            ->assertJsonPath('data.reversal_reason', 'تم إلغاء التوريد بناءً على طلب الإدارة')
            ->assertJsonPath('data.reversed_by', $this->user->id);
    }

    public function test_draft_and_pending_documents_can_be_cancelled(): void
    {
        // Cancel draft
        $draftDoc = $this->createDraftDocument();
        $this->postJson("/api/inventory-documents/{$draftDoc->id}/cancel")->assertOk()
            ->assertJsonPath('data.status', InventoryDocumentStatus::CANCELLED->value);

        // Cancel pending
        $pendingDoc = $this->createDraftDocument();
        $this->postJson("/api/inventory-documents/{$pendingDoc->id}/submit")->assertOk();
        $this->postJson("/api/inventory-documents/{$pendingDoc->id}/cancel")->assertOk()
            ->assertJsonPath('data.status', InventoryDocumentStatus::CANCELLED->value);
    }

    public function test_cannot_edit_or_delete_posted_or_reversed_documents(): void
    {
        $doc = $this->createDraftDocument();
        $this->postJson("/api/inventory-documents/{$doc->id}/submit")->assertOk();
        $this->postJson("/api/inventory-documents/{$doc->id}/approve")->assertOk();
        $this->postJson("/api/inventory-documents/{$doc->id}/post")->assertOk();

        // Attempt to update
        $updateRes = $this->putJson("/api/inventory-documents/{$doc->id}", [
            'document_type' => InventoryDocumentType::GOODS_RECEIPT->value,
            'document_date' => '2026-11-13',
            'destination_warehouse_id' => $this->warehouse->id,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 100],
            ],
        ]);
        $updateRes->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        // Attempt to delete
        $deleteRes = $this->deleteJson("/api/inventory-documents/{$doc->id}");
        $deleteRes->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_illegal_workflow_transitions_are_blocked(): void
    {
        $doc = $this->createDraftDocument();

        // Cannot jump directly from draft to post
        $postRes = $this->postJson("/api/inventory-documents/{$doc->id}/post");
        $postRes->assertStatus(422)->assertJsonValidationErrors(['status']);

        // Cannot jump directly from draft to approve
        $approveRes = $this->postJson("/api/inventory-documents/{$doc->id}/approve");
        $approveRes->assertStatus(422)->assertJsonValidationErrors(['status']);

        // Cannot reverse unposted document
        $revRes = $this->postJson("/api/inventory-documents/{$doc->id}/reverse", [
            'reversal_reason' => 'سبب',
        ]);
        $revRes->assertStatus(422)->assertJsonValidationErrors(['status']);
    }
}
