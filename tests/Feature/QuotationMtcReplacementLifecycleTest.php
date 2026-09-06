<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\ExchangeRate;
use App\Models\Period;
use App\Models\PrItem;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class QuotationMtcReplacementLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasing;
    private User $supplier;
    private PurchaseRequisition $pr;
    private PrItem $prItem;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');

        $this->purchasing = User::factory()->create(['role' => 'purchasing']);
        $this->supplier = User::factory()->create(['role' => 'supplier']);
        Supplier::create([
            'user_id' => $this->supplier->id,
            'company_name' => 'Supplier Metal',
        ]);

        $period = Period::create([
            'name' => 'Period MTC Test',
            'month' => 9,
            'year' => 2026,
            'status' => 'open',
            'created_by' => $this->purchasing->id,
        ]);

        ExchangeRate::create([
            'currency' => 'USD',
            'rate_to_idr' => 16000,
            'valid_from' => now()->subDay(),
            'created_by' => $this->purchasing->id,
        ]);

        $this->pr = PurchaseRequisition::create([
            'period_id' => $period->id,
            'created_by' => $this->purchasing->id,
            'pr_number' => 'REQ/09/2026/099',
            'status' => 'submitted',
        ]);

        $this->prItem = PrItem::create([
            'pr_id' => $this->pr->id,
            'hs_code' => '7209.16.00',
            'material_name' => 'Steel Plate A',
            'shape' => 'Flat',
            'thickness' => 10,
            'width' => 1000,
            'length' => 2000,
            'quantity' => 2,
            'weight_needed' => 157,
        ]);
    }

    public function test_edit_with_no_replacement_preserves_existing_mtc_attachment_and_disk_file(): void
    {
        // 1. Initial submission with MTC file
        $mtcFile = UploadedFile::fake()->create('initial_mtc.pdf', 100, 'application/pdf');

        $payload = [
            'action' => 'draft',
            'currency' => 'USD',
            'estimated_delivery' => now()->addDays(14)->toDateString(),
            'validity_period' => now()->addDays(30)->toDateString(),
            'payment_terms' => 'Cash',
            'items' => [
                [
                    'pr_item_id' => $this->prItem->id,
                    'is_available' => '1',
                    'available_qty' => '2',
                    'available_thickness' => '10',
                    'available_width' => '1000',
                    'available_length_input' => '2000',
                    'offered_weight_per_unit' => '157',
                    'price_per_kg' => '3.50',
                    'mtc_file' => $mtcFile,
                ],
            ],
        ];

        $response = $this->actingAs($this->supplier)
            ->post(route('supplier.quotations.store', $this->pr), $payload);

        $response->assertRedirect();

        $quotation = Quotation::where('pr_id', $this->pr->id)->where('supplier_id', $this->supplier->id)->firstOrFail();
        $initialItem = $quotation->items()->firstOrFail();
        $initialAttachment = $initialItem->attachments()->firstOrFail();

        $initialPath = $initialAttachment->file_path;
        Storage::disk('private')->assertExists($initialPath);

        // 2. Edit quotation WITHOUT uploading a replacement MTC file
        $payloadNoReplacement = [
            'action' => 'draft',
            'currency' => 'USD',
            'estimated_delivery' => now()->addDays(20)->toDateString(),
            'validity_period' => now()->addDays(30)->toDateString(),
            'payment_terms' => 'Net 30',
            'items' => [
                [
                    'pr_item_id' => $this->prItem->id,
                    'is_available' => '1',
                    'available_qty' => '2',
                    'available_thickness' => '10',
                    'available_width' => '1000',
                    'available_length_input' => '2000',
                    'offered_weight_per_unit' => '157',
                    'price_per_kg' => '3.75',
                ],
            ],
        ];

        $editResponse = $this->actingAs($this->supplier)
            ->post(route('supplier.quotations.store', $this->pr), $payloadNoReplacement);

        $editResponse->assertRedirect();

        $updatedQuotation = $quotation->fresh();
        $newItem = $updatedQuotation->items()->firstOrFail();

        // Attachment DB row was preserved and re-linked to the new item
        $this->assertDatabaseHas('attachments', [
            'id' => $initialAttachment->id,
            'attachable_id' => $newItem->id,
            'file_path' => $initialPath,
        ]);

        // File on disk was preserved
        Storage::disk('private')->assertExists($initialPath);
    }

    public function test_edit_with_replacement_uploads_new_file_and_deletes_old_attachment_and_disk_file(): void
    {
        // 1. Initial submission with initial MTC file
        $oldFile = UploadedFile::fake()->create('old_mtc.pdf', 100, 'application/pdf');

        $initialPayload = [
            'action' => 'draft',
            'currency' => 'USD',
            'estimated_delivery' => now()->addDays(14)->toDateString(),
            'validity_period' => now()->addDays(30)->toDateString(),
            'payment_terms' => 'Cash',
            'items' => [
                [
                    'pr_item_id' => $this->prItem->id,
                    'is_available' => '1',
                    'available_qty' => '2',
                    'available_thickness' => '10',
                    'available_width' => '1000',
                    'available_length_input' => '2000',
                    'offered_weight_per_unit' => '157',
                    'price_per_kg' => '3.50',
                    'mtc_file' => $oldFile,
                ],
            ],
        ];

        $this->actingAs($this->supplier)
            ->post(route('supplier.quotations.store', $this->pr), $initialPayload)
            ->assertRedirect();

        $quotation = Quotation::where('pr_id', $this->pr->id)->where('supplier_id', $this->supplier->id)->firstOrFail();
        $oldAttachment = $quotation->items()->firstOrFail()->attachments()->firstOrFail();
        $oldFilePath = $oldAttachment->file_path;
        $oldAttachmentId = $oldAttachment->id;

        Storage::disk('private')->assertExists($oldFilePath);

        // 2. Edit quotation WITH replacement MTC file
        $newFile = UploadedFile::fake()->create('replacement_mtc.pdf', 150, 'application/pdf');

        $replacementPayload = [
            'action' => 'draft',
            'currency' => 'USD',
            'estimated_delivery' => now()->addDays(14)->toDateString(),
            'validity_period' => now()->addDays(30)->toDateString(),
            'payment_terms' => 'Cash',
            'items' => [
                [
                    'pr_item_id' => $this->prItem->id,
                    'is_available' => '1',
                    'available_qty' => '2',
                    'available_thickness' => '10',
                    'available_width' => '1000',
                    'available_length_input' => '2000',
                    'offered_weight_per_unit' => '157',
                    'price_per_kg' => '3.50',
                    'mtc_file' => $newFile,
                ],
            ],
        ];

        $this->actingAs($this->supplier)
            ->post(route('supplier.quotations.store', $this->pr), $replacementPayload)
            ->assertRedirect();

        $updatedQuotation = $quotation->fresh();
        $newItem = $updatedQuotation->items()->firstOrFail();
        $newAttachment = $newItem->attachments()->firstOrFail();

        // New attachment row exists and points to the new file
        $this->assertNotSame($oldAttachmentId, $newAttachment->id);
        $this->assertSame('replacement_mtc.pdf', $newAttachment->file_name);
        $this->assertSame($newItem->id, $newAttachment->attachable_id);
        Storage::disk('private')->assertExists($newAttachment->file_path);

        // Old attachment row is DELETED from database
        $this->assertDatabaseMissing('attachments', [
            'id' => $oldAttachmentId,
        ]);

        // Old file is DELETED from disk
        Storage::disk('private')->assertMissing($oldFilePath);
    }

    public function test_failed_validation_preserves_old_attachment_and_disk_file(): void
    {
        // 1. Initial submission with initial MTC file
        $oldFile = UploadedFile::fake()->create('saved_mtc.pdf', 100, 'application/pdf');

        $initialPayload = [
            'action' => 'draft',
            'currency' => 'USD',
            'estimated_delivery' => now()->addDays(14)->toDateString(),
            'validity_period' => now()->addDays(30)->toDateString(),
            'payment_terms' => 'Cash',
            'items' => [
                [
                    'pr_item_id' => $this->prItem->id,
                    'is_available' => '1',
                    'available_qty' => '2',
                    'available_thickness' => '10',
                    'available_width' => '1000',
                    'available_length_input' => '2000',
                    'offered_weight_per_unit' => '157',
                    'price_per_kg' => '3.50',
                    'mtc_file' => $oldFile,
                ],
            ],
        ];

        $this->actingAs($this->supplier)
            ->post(route('supplier.quotations.store', $this->pr), $initialPayload)
            ->assertRedirect();

        $quotation = Quotation::where('pr_id', $this->pr->id)->where('supplier_id', $this->supplier->id)->firstOrFail();
        $oldAttachment = $quotation->items()->firstOrFail()->attachments()->firstOrFail();
        $oldFilePath = $oldAttachment->file_path;
        $oldAttachmentId = $oldAttachment->id;

        // 2. Attempt replacement with an invalid file type (e.g. .exe instead of pdf/jpg/png)
        $invalidFile = UploadedFile::fake()->create('malicious.exe', 100, 'application/x-msdownload');

        $invalidPayload = [
            'action' => 'draft',
            'currency' => 'USD',
            'estimated_delivery' => now()->addDays(14)->toDateString(),
            'validity_period' => now()->addDays(30)->toDateString(),
            'payment_terms' => 'Cash',
            'items' => [
                [
                    'pr_item_id' => $this->prItem->id,
                    'is_available' => '1',
                    'available_qty' => '2',
                    'available_thickness' => '10',
                    'available_width' => '1000',
                    'available_length_input' => '2000',
                    'offered_weight_per_unit' => '157',
                    'price_per_kg' => '3.50',
                    'mtc_file' => $invalidFile,
                ],
            ],
        ];

        $this->actingAs($this->supplier)
            ->post(route('supplier.quotations.store', $this->pr), $invalidPayload)
            ->assertSessionHasErrors();

        // Old attachment row remains in database
        $this->assertDatabaseHas('attachments', [
            'id' => $oldAttachmentId,
        ]);

        // Old file remains on disk
        Storage::disk('private')->assertExists($oldFilePath);
    }
}
