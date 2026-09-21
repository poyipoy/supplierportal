<?php

namespace Tests\Feature\Qc;

use App\Models\Attachment;
use App\Models\ExchangeRate;
use App\Models\Period;
use App\Models\PrItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\QcInspection;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class QcInspectionAttachmentCompensationTest extends TestCase
{
    use RefreshDatabase;

    private User $qcUser;
    private PurchaseOrder $po;
    private PrItem $prItem;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');

        $this->qcUser = User::factory()->create([
            'role' => 'qc',
            'is_active' => true,
        ]);

        $purchasing = User::factory()->create([
            'role' => 'purchasing',
            'is_active' => true,
        ]);

        $supplier = User::factory()->create([
            'role' => 'supplier',
            'is_active' => true,
        ]);

        $period = Period::create([
            'name' => '2026-09',
            'year' => 2026,
            'status' => 'open',
            'created_by' => $purchasing->id,
        ]);

        $pr = PurchaseRequisition::create([
            'period_id' => $period->id,
            'pr_number' => 'REQ/09/2026/001',
            'status' => 'submitted',
            'created_by' => $purchasing->id,
        ]);

        $this->prItem = PrItem::create([
            'pr_id' => $pr->id,
            'material_name' => 'SKD11 Round Bar',
            'shape' => 'round',
            'd_outer' => 50,
            'length' => 100,
            'weight_needed' => 10.5,
            'quantity' => 1,
        ]);

        $rate = ExchangeRate::create([
            'currency' => 'USD',
            'rate_to_idr' => 16000,
            'valid_from' => now()->subDay(),
            'created_by' => $purchasing->id,
        ]);

        $quotation = Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $supplier->id,
            'exchange_rate_id' => $rate->id,
            'currency' => 'USD',
            'status' => 'accepted',
        ]);

        $quotationItem = QuotationItem::create([
            'quotation_id' => $quotation->id,
            'pr_item_id' => $this->prItem->id,
            'price_per_kg' => 10.0,
            'amount' => 105.0,
            'available_qty' => 1,
        ]);

        $this->po = PurchaseOrder::create([
            'supplier_id' => $supplier->id,
            'currency' => 'USD',
            'exchange_rate_id' => $rate->id,
            'po_number' => 'PO/09/2026/001',
            'status' => 'waiting_qc',
            'created_by' => $purchasing->id,
        ]);

        $this->po->quotations()->attach($quotation->id);
    }

    public function test_staged_qc_attachments_are_deleted_from_storage_if_store_transaction_fails(): void
    {
        $file = UploadedFile::fake()->image('defect.png');

        // Trigger a failure after attachment creation by hooking DB query or simulating an exception in reconcileOperationalStatus
        // We can simulate failure by having DB::commit fail or by throwing inside the transaction
        DB::shouldReceive('beginTransaction')->once()->andReturnNull();
        DB::shouldReceive('rollBack')->once()->andReturnNull();
        // Allow select/insert/update queries as normal, but simulate exception before commit
        DB::shouldReceive('commit')->andThrow(new \RuntimeException('Simulated database commit failure'));

        $response = $this->actingAs($this->qcUser)->post(
            route('qc.inspections.store', $this->po),
            [
                'items' => [
                    0 => [
                        'pr_item_id' => $this->prItem->id,
                        'status' => 'ng',
                        'actual_d_outer' => 45,
                        'actual_length' => 95,
                        'actual_weight' => 9.0,
                        'notes' => 'Dimensional defects',
                    ],
                ],
                'attachments' => [
                    0 => [$file],
                ],
            ]
        );

        // All files in private disk under attachments/ must have been cleaned up
        $allFiles = Storage::disk('private')->allFiles();
        $this->assertEmpty($allFiles, 'Staged files on private storage must be deleted when the transaction aborts.');
    }

    public function test_successful_qc_store_persists_attachments_to_storage(): void
    {
        $file = UploadedFile::fake()->image('defect_valid.png');

        $response = $this->actingAs($this->qcUser)->post(
            route('qc.inspections.store', $this->po),
            [
                'items' => [
                    0 => [
                        'pr_item_id' => $this->prItem->id,
                        'status' => 'ng',
                        'actual_d_outer' => 45,
                        'actual_length' => 95,
                        'actual_weight' => 9.0,
                        'notes' => 'Dimensional defects',
                    ],
                ],
                'attachments' => [
                    0 => [$file],
                ],
            ]
        );

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $allFiles = Storage::disk('private')->allFiles();
        $this->assertCount(1, $allFiles, 'Attachment file must persist on success.');

        $inspection = QcInspection::where('po_id', $this->po->id)->first();
        $this->assertNotNull($inspection);
        $this->assertTrue($inspection->attachments()->exists());
    }
}
