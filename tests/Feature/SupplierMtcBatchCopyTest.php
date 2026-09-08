<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Period;
use App\Models\PrItem;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SupplierMtcBatchCopyTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasing;

    private User $supplier;

    private User $otherSupplier;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');

        $this->purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $this->supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $this->otherSupplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
    }

    public function test_supplier_can_copy_existing_mtc_attachment_to_another_item_with_file_isolation(): void
    {
        $pr = $this->createRequisition([
            ['material_name' => 'SKD11 Round Bar', 'shape' => PrItem::SHAPE_ROUND, 'quantity' => 2, 'd_outer' => 50, 'length' => 1000, 'weight_needed' => 15],
            ['material_name' => 'SKD11 Flat Plate', 'shape' => PrItem::SHAPE_FLAT, 'quantity' => 3, 'thickness' => 20, 'width' => 100, 'length' => 500, 'weight_needed' => 20],
        ]);

        $item1 = $pr->items[0];
        $item2 = $pr->items[1];

        // 1. Initial draft save with MTC file on Item 1
        $file = UploadedFile::fake()->create('mill_test_cert.pdf', 500, 'application/pdf');

        $payload = [
            'action' => 'draft',
            'currency' => 'USD',
            'items' => [
                0 => [
                    'pr_item_id' => $item1->id,
                    'is_available' => 1,
                    'price_per_kg' => 4.5,
                    'available_qty' => 2,
                    'available_d_outer' => 50,
                    'available_length' => 1000,
                    'offered_weight_per_unit' => 15,
                    'mtc_file' => $file,
                ],
                1 => [
                    'pr_item_id' => $item2->id,
                    'is_available' => 1,
                    'price_per_kg' => 5.0,
                    'available_qty' => 3,
                    'available_thickness' => 20,
                    'available_width' => 100,
                    'available_length' => 500,
                    'offered_weight_per_unit' => 20,
                ],
            ],
        ];

        $response = $this->actingAs($this->supplier)
            ->post(route('supplier.quotations.store', $pr), $payload);

        $response->assertSessionHasNoErrors();

        $quotation = Quotation::where('pr_id', $pr->id)->where('supplier_id', $this->supplier->id)->firstOrFail();
        $qItem1 = $quotation->items()->where('pr_item_id', $item1->id)->firstOrFail();
        $this->assertCount(1, $qItem1->attachments);
        $sourceAttachment = $qItem1->attachments->first();
        Storage::disk('private')->assertExists($sourceAttachment->file_path);

        // 2. Save draft again copying Item 1's attachment to Item 2
        $payload2 = [
            'action' => 'draft',
            'currency' => 'USD',
            'items' => [
                0 => [
                    'pr_item_id' => $item1->id,
                    'is_available' => 1,
                    'price_per_kg' => 4.5,
                    'available_qty' => 2,
                    'available_d_outer' => 50,
                    'available_length' => 1000,
                    'offered_weight_per_unit' => 15,
                    'keep_existing_attachment' => 1,
                ],
                1 => [
                    'pr_item_id' => $item2->id,
                    'is_available' => 1,
                    'price_per_kg' => 5.0,
                    'available_qty' => 3,
                    'available_thickness' => 20,
                    'available_width' => 100,
                    'available_length' => 500,
                    'offered_weight_per_unit' => 20,
                    'copy_from_attachment_id' => $sourceAttachment->id,
                ],
            ],
        ];

        $response2 = $this->actingAs($this->supplier)
            ->post(route('supplier.quotations.store', $pr), $payload2);

        $response2->assertSessionHasNoErrors();

        $quotation->refresh();
        $freshQItem1 = $quotation->items()->where('pr_item_id', $item1->id)->firstOrFail();
        $freshQItem2 = $quotation->items()->where('pr_item_id', $item2->id)->firstOrFail();

        $this->assertCount(1, $freshQItem1->attachments);
        $this->assertCount(1, $freshQItem2->attachments);

        $att1 = $freshQItem1->attachments->first();
        $att2 = $freshQItem2->attachments->first();

        // Check attributes
        $this->assertEquals($sourceAttachment->file_name, $att2->file_name);
        $this->assertEquals($sourceAttachment->file_type, $att2->file_type);
        $this->assertEquals($this->supplier->id, $att2->uploaded_by);

        // CRITICAL SECURITY & ISOLATION CHECK:
        // Must NOT share the same file_path!
        $this->assertNotEquals($att1->file_path, $att2->file_path, 'Target cloned attachment must have a distinct, independent physical file path.');

        // Both physical files must exist
        Storage::disk('private')->assertExists($att1->file_path);
        Storage::disk('private')->assertExists($att2->file_path);

        // File contents must match
        $this->assertEquals(
            Storage::disk('private')->get($att1->file_path),
            Storage::disk('private')->get($att2->file_path)
        );
    }

    public function test_replacing_or_deleting_source_mtc_does_not_break_copied_target_file(): void
    {
        $pr = $this->createRequisition([
            ['material_name' => 'SKD11 Round Bar', 'shape' => PrItem::SHAPE_ROUND, 'quantity' => 2, 'd_outer' => 50, 'length' => 1000, 'weight_needed' => 15],
            ['material_name' => 'SKD11 Flat Plate', 'shape' => PrItem::SHAPE_FLAT, 'quantity' => 3, 'thickness' => 20, 'width' => 100, 'length' => 500, 'weight_needed' => 20],
        ]);

        $item1 = $pr->items[0];
        $item2 = $pr->items[1];

        $file = UploadedFile::fake()->create('source_cert.pdf', 300, 'application/pdf');

        // Initial save with file on item 1
        $this->actingAs($this->supplier)
            ->post(route('supplier.quotations.store', $pr), [
                'action' => 'draft',
                'currency' => 'USD',
                'items' => [
                    0 => [
                        'pr_item_id' => $item1->id,
                        'is_available' => 1,
                        'price_per_kg' => 4.5,
                        'available_qty' => 2,
                        'available_d_outer' => 50,
                        'available_length' => 1000,
                        'offered_weight_per_unit' => 15,
                        'mtc_file' => $file,
                    ],
                    1 => [
                        'pr_item_id' => $item2->id,
                        'is_available' => 1,
                        'price_per_kg' => 5.0,
                        'available_qty' => 3,
                        'available_thickness' => 20,
                        'available_width' => 100,
                        'available_length' => 500,
                        'offered_weight_per_unit' => 20,
                    ],
                ],
            ]);

        $quotation = Quotation::where('pr_id', $pr->id)->firstOrFail();
        $sourceAtt = $quotation->items()->where('pr_item_id', $item1->id)->firstOrFail()->attachments->first();

        // Copy item 1 MTC to item 2
        $this->actingAs($this->supplier)
            ->post(route('supplier.quotations.store', $pr), [
                'action' => 'draft',
                'currency' => 'USD',
                'items' => [
                    0 => [
                        'pr_item_id' => $item1->id,
                        'is_available' => 1,
                        'price_per_kg' => 4.5,
                        'available_qty' => 2,
                        'available_d_outer' => 50,
                        'available_length' => 1000,
                        'offered_weight_per_unit' => 15,
                        'keep_existing_attachment' => 1,
                    ],
                    1 => [
                        'pr_item_id' => $item2->id,
                        'is_available' => 1,
                        'price_per_kg' => 5.0,
                        'available_qty' => 3,
                        'available_thickness' => 20,
                        'available_width' => 100,
                        'available_length' => 500,
                        'offered_weight_per_unit' => 20,
                        'copy_from_attachment_id' => $sourceAtt->id,
                    ],
                ],
            ]);

        $quotation->refresh();
        $targetAtt = $quotation->items()->where('pr_item_id', $item2->id)->firstOrFail()->attachments->first();
        $targetFilePath = $targetAtt->file_path;

        // Now replace item 1's file with a NEW file
        $newFile = UploadedFile::fake()->create('replacement_cert.pdf', 400, 'application/pdf');
        $this->actingAs($this->supplier)
            ->post(route('supplier.quotations.store', $pr), [
                'action' => 'draft',
                'currency' => 'USD',
                'items' => [
                    0 => [
                        'pr_item_id' => $item1->id,
                        'is_available' => 1,
                        'price_per_kg' => 4.5,
                        'available_qty' => 2,
                        'available_d_outer' => 50,
                        'available_length' => 1000,
                        'offered_weight_per_unit' => 15,
                        'mtc_file' => $newFile,
                    ],
                    1 => [
                        'pr_item_id' => $item2->id,
                        'is_available' => 1,
                        'price_per_kg' => 5.0,
                        'available_qty' => 3,
                        'available_thickness' => 20,
                        'available_width' => 100,
                        'available_length' => 500,
                        'offered_weight_per_unit' => 20,
                        'keep_existing_attachment' => 1,
                    ],
                ],
            ]);

        // CRITICAL CHECK: Target item's file MUST STILL EXIST on disk!
        Storage::disk('private')->assertExists($targetFilePath);
        $this->assertDatabaseHas('attachments', [
            'file_path' => $targetFilePath,
        ]);
    }

    public function test_idor_protection_supplier_cannot_copy_attachment_belonging_to_another_supplier(): void
    {
        $pr = $this->createRequisition([
            ['material_name' => 'Item 1', 'shape' => PrItem::SHAPE_ROUND, 'quantity' => 2, 'd_outer' => 50, 'length' => 1000, 'weight_needed' => 15],
        ]);

        $prItem = $pr->items[0];

        // Other supplier creates a quotation with an attachment
        $otherQuotation = Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $this->otherSupplier->id,
            'currency' => 'USD',
            'status' => Quotation::STATUS_DRAFT,
        ]);

        $otherQItem = $otherQuotation->items()->create([
            'pr_item_id' => $prItem->id,
            'price_per_kg' => 10.0,
            'amount' => 300.0,
        ]);

        $otherPath = 'attachments/2026/09/secret_other_supplier.pdf';
        Storage::disk('private')->put($otherPath, 'Confidential Price and MTC of Other Supplier');

        $unauthorizedAttachment = $otherQItem->attachments()->create([
            'file_path' => $otherPath,
            'file_name' => 'secret_other_supplier.pdf',
            'file_type' => 'application/pdf',
            'uploaded_by' => $this->otherSupplier->id,
        ]);

        // Current supplier attempts IDOR by providing unauthorized attachment id
        $payload = [
            'action' => 'draft',
            'currency' => 'USD',
            'items' => [
                0 => [
                    'pr_item_id' => $prItem->id,
                    'is_available' => 1,
                    'price_per_kg' => 4.5,
                    'available_qty' => 2,
                    'available_d_outer' => 50,
                    'available_length' => 1000,
                    'offered_weight_per_unit' => 15,
                    'copy_from_attachment_id' => $unauthorizedAttachment->id,
                ],
            ],
        ];

        $response = $this->actingAs($this->supplier)
            ->post(route('supplier.quotations.store', $pr), $payload);

        $response->assertSessionHasNoErrors();

        $myQuotation = Quotation::where('pr_id', $pr->id)->where('supplier_id', $this->supplier->id)->firstOrFail();
        $myQItem = $myQuotation->items()->firstOrFail();

        // Must NOT attach the unauthorized file!
        $this->assertCount(0, $myQItem->attachments);
        $this->assertDatabaseMissing('attachments', [
            'uploaded_by' => $this->supplier->id,
            'file_name' => 'secret_other_supplier.pdf',
        ]);
    }

    public function test_type_protection_supplier_cannot_copy_non_quotation_item_attachment(): void
    {
        $pr = $this->createRequisition([
            ['material_name' => 'Item 1', 'shape' => PrItem::SHAPE_ROUND, 'quantity' => 2, 'd_outer' => 50, 'length' => 1000, 'weight_needed' => 15],
        ]);
        $prItem = $pr->items[0];

        // An attachment on a User or other model uploaded by this supplier
        $dummyPath = 'attachments/2026/09/profile_pic.png';
        Storage::disk('private')->put($dummyPath, 'image content');

        $nonQuotationAttachment = Attachment::create([
            'attachable_type' => User::class,
            'attachable_id' => $this->supplier->id,
            'file_path' => $dummyPath,
            'file_name' => 'profile_pic.png',
            'file_type' => 'image/png',
            'uploaded_by' => $this->supplier->id,
        ]);

        $payload = [
            'action' => 'draft',
            'currency' => 'USD',
            'items' => [
                0 => [
                    'pr_item_id' => $prItem->id,
                    'is_available' => 1,
                    'price_per_kg' => 4.5,
                    'available_qty' => 2,
                    'available_d_outer' => 50,
                    'available_length' => 1000,
                    'offered_weight_per_unit' => 15,
                    'copy_from_attachment_id' => $nonQuotationAttachment->id,
                ],
            ],
        ];

        $response = $this->actingAs($this->supplier)
            ->post(route('supplier.quotations.store', $pr), $payload);

        $response->assertSessionHasNoErrors();

        $myQuotation = Quotation::where('pr_id', $pr->id)->where('supplier_id', $this->supplier->id)->firstOrFail();
        $myQItem = $myQuotation->items()->firstOrFail();

        // Must NOT clone non-QuotationItem attachments
        $this->assertCount(0, $myQItem->attachments);
    }

    public function test_supplier_can_remove_attachment_on_draft_update(): void
    {
        $pr = $this->createRequisition([
            ['material_name' => 'Item 1', 'shape' => PrItem::SHAPE_ROUND, 'quantity' => 2, 'd_outer' => 50, 'length' => 1000, 'weight_needed' => 15],
        ]);
        $prItem = $pr->items[0];

        $file = UploadedFile::fake()->create('to_delete.pdf', 200, 'application/pdf');

        $this->actingAs($this->supplier)
            ->post(route('supplier.quotations.store', $pr), [
                'action' => 'draft',
                'currency' => 'USD',
                'items' => [
                    0 => [
                        'pr_item_id' => $prItem->id,
                        'is_available' => 1,
                        'price_per_kg' => 4.5,
                        'available_qty' => 2,
                        'available_d_outer' => 50,
                        'available_length' => 1000,
                        'offered_weight_per_unit' => 15,
                        'mtc_file' => $file,
                    ],
                ],
            ]);

        $quotation = Quotation::where('pr_id', $pr->id)->firstOrFail();
        $attachment = $quotation->items->first()->attachments->first();
        $filePath = $attachment->file_path;
        Storage::disk('private')->assertExists($filePath);

        // Update with keep_existing_attachment = 0
        $this->actingAs($this->supplier)
            ->post(route('supplier.quotations.store', $pr), [
                'action' => 'draft',
                'currency' => 'USD',
                'items' => [
                    0 => [
                        'pr_item_id' => $prItem->id,
                        'is_available' => 1,
                        'price_per_kg' => 4.5,
                        'available_qty' => 2,
                        'available_d_outer' => 50,
                        'available_length' => 1000,
                        'offered_weight_per_unit' => 15,
                        'keep_existing_attachment' => 0,
                    ],
                ],
            ]);

        $quotation->refresh();
        $this->assertCount(0, $quotation->items->first()->attachments);
        $this->assertDatabaseMissing('attachments', ['id' => $attachment->id]);
        Storage::disk('private')->assertMissing($filePath);
    }

    public function test_view_renders_copy_mtc_dropdown_triggers_and_hidden_inputs(): void
    {
        $pr = $this->createRequisition([
            ['material_name' => 'SKD11 Round', 'shape' => PrItem::SHAPE_ROUND, 'quantity' => 2, 'd_outer' => 50, 'length' => 1000, 'weight_needed' => 15],
            ['material_name' => 'SKD11 Flat', 'shape' => PrItem::SHAPE_FLAT, 'quantity' => 3, 'thickness' => 20, 'width' => 100, 'length' => 500, 'weight_needed' => 20],
        ]);

        $response = $this->actingAs($this->supplier)
            ->get(route('supplier.quotations.create', $pr));

        $response->assertOk();
        $response->assertSee('data-mtc-copy-trigger', false);
        $response->assertSee('Apply to same material', false);
        $response->assertSee('Apply to all available items', false);
        $response->assertSee('name="items[0][copy_from_attachment_id]"', false);
        $response->assertSee('data-material-name="SKD11 Round"', false);
    }

    private function createRequisition(array $items): PurchaseRequisition
    {
        $period = Period::firstOrCreate(
            ['name' => 'MTC Test Period', 'year' => 2026],
            ['status' => 'open', 'created_by' => $this->purchasing->id]
        );

        $pr = PurchaseRequisition::create([
            'period_id' => $period->id,
            'pr_number' => 'REQ/MTC/'.uniqid(),
            'status' => 'submitted',
            'created_by' => $this->purchasing->id,
        ]);

        $pr->invitedSuppliers()->syncWithoutDetaching([$this->supplier->id, $this->otherSupplier->id]);

        foreach ($items as $index => $item) {
            PrItem::create(array_merge([
                'pr_id' => $pr->id,
                'hs_code' => '7209.16.00',
                'material_name' => 'Material '.($index + 1),
                'quantity' => 2,
                'shape' => PrItem::SHAPE_FLAT,
                'thickness' => 1,
                'width' => 100,
                'length' => 200,
                'weight_needed' => 10,
            ], $item));
        }

        return $pr->fresh(['items', 'invitedSuppliers']);
    }
}
