<?php

namespace Tests\Feature\Procurement;

use App\Models\Attachment;
use App\Models\FileInspection;
use App\Models\Period;
use App\Models\PoDocument;
use App\Models\PrItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Shipment;
use App\Models\ShipmentDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\Support\NativeFileFixtures;
use Tests\TestCase;

class PoShipmentDocumentConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    private const ROUNDS = 4;

    protected $exceptTables = ['local_po_document_capacity'];

    private User $purchasing;

    private User $supplier;

    private string $privateRoot;

    protected function beforeTruncatingDatabase(): void
    {
        $this->assertSame('adasi_portal_test', DB::connection()->getDatabaseName());
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->privateRoot = storage_path('framework/testing/po-shipment-doc-conc-'.uniqid());
        File::ensureDirectoryExists($this->privateRoot);
        config(['filesystems.disks.private.root' => $this->privateRoot]);

        $this->purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $this->supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->privateRoot);
        $this->truncateTablesForAllConnections();
        parent::tearDown();
    }

    public function test_concurrent_supplier_reupload_and_purchasing_verification_never_verifies_unseen_file(): void
    {
        for ($round = 1; $round <= self::ROUNDS; $round++) {
            [$po, $shipment] = $this->createPoWithShipment("SHP/10/2026/C1-{$round}");
            $doc = $shipment->documents()->where('doc_type', 'invoice')->firstOrFail();
            $initialAttachment = $this->storeValidatedAttachment($doc, "initial-{$round}.pdf");
            $doc->update(['status' => ShipmentDocument::STATUS_RECEIVED]);

            $outcomes = $this->race([
                [
                    'upload',
                    [
                        'document_id' => $doc->id,
                        'user_id' => $this->supplier->id,
                        'file_name' => "replacement-{$round}.pdf",
                    ],
                ],
                [
                    'po-batch',
                    [
                        'po_id' => $po->id,
                        'actor_id' => $this->purchasing->id,
                        'doc_type' => 'invoice',
                        'target' => 'verified',
                        'rows' => [[
                            'shipment' => $shipment->hash,
                            'expected_status' => 'received',
                            'expected_attachment' => $initialAttachment->hash,
                        ]],
                    ],
                ],
            ], sort: false);

            $this->assertNotContains('deadlock', $outcomes, 'No deadlock allowed');

            $doc->refresh();
            $latestAttachment = $doc->attachments()->orderByDesc('created_at')->orderByDesc('id')->firstOrFail();

            if ($outcomes[1] === 'updated:1:skipped:0') {
                // Verification ran first; supplier re-upload under lock saw 'verified' and was refused, preserving the verified attachment
                $this->assertSame('refused:InvalidArgumentException', $outcomes[0]);
                $this->assertSame(ShipmentDocument::STATUS_VERIFIED, $doc->status);
                $this->assertSame($initialAttachment->id, $latestAttachment->id);
            } else {
                // Re-upload ran first; Purchasing verification was refused with stale_attachment
                $this->assertStringStartsWith('uploaded:', $outcomes[0]);
                $this->assertSame('refused:stale_state:stale_attachment', $outcomes[1]);
                $this->assertSame(ShipmentDocument::STATUS_RECEIVED, $doc->status);
                $this->assertNotSame($initialAttachment->id, $latestAttachment->id);
            }
        }
    }

    public function test_concurrent_first_upload_and_purchasing_received_never_deadlocks(): void
    {
        for ($round = 1; $round <= self::ROUNDS; $round++) {
            [$po, $shipment] = $this->createPoWithShipment("SHP/10/2026/C2-{$round}");
            $doc = $shipment->documents()->where('doc_type', 'invoice')->firstOrFail();

            $outcomes = $this->race([
                [
                    'upload',
                    [
                        'document_id' => $doc->id,
                        'user_id' => $this->supplier->id,
                        'file_name' => "first-{$round}.pdf",
                    ],
                ],
                [
                    'po-batch',
                    [
                        'po_id' => $po->id,
                        'actor_id' => $this->purchasing->id,
                        'doc_type' => 'invoice',
                        'target' => 'received',
                        'rows' => [[
                            'shipment' => $shipment->hash,
                            'expected_status' => 'pending',
                        ]],
                    ],
                ],
            ], sort: false);

            $this->assertNotContains('deadlock', $outcomes, 'No deadlock allowed');
            $this->assertStringStartsWith('uploaded:', $outcomes[0]);
            $this->assertContains($outcomes[1], ['updated:1:skipped:0', 'refused:stale_state:stale_status']);
            $this->assertSame(ShipmentDocument::STATUS_RECEIVED, $doc->fresh()->status);
            $this->assertSame('received', $po->documents()->where('doc_type', 'invoice')->value('status'));
        }
    }

    public function test_two_concurrent_po_updates_on_same_document_produce_one_updated_and_one_stale(): void
    {
        for ($round = 1; $round <= self::ROUNDS; $round++) {
            [$po, $shipment] = $this->createPoWithShipment("SHP/10/2026/C3-{$round}");
            $doc = $shipment->documents()->where('doc_type', 'invoice')->firstOrFail();
            $att = $this->storeValidatedAttachment($doc, "same-doc-{$round}.pdf");
            $doc->update(['status' => ShipmentDocument::STATUS_RECEIVED]);

            $jobPayload = [
                'po_id' => $po->id,
                'actor_id' => $this->purchasing->id,
                'doc_type' => 'invoice',
                'target' => 'verified',
                'rows' => [[
                    'shipment' => $shipment->hash,
                    'expected_status' => 'received',
                    'expected_attachment' => $att->hash,
                ]],
            ];

            $outcomes = $this->race([
                ['po-batch', $jobPayload],
                ['po-batch', $jobPayload],
            ], sort: true);

            $this->assertSame(['refused:stale_state:stale_status', 'updated:1:skipped:0'], $outcomes);
            $this->assertSame(ShipmentDocument::STATUS_VERIFIED, $doc->fresh()->status);
        }
    }

    public function test_reversed_shipment_order_batches_and_shared_multi_po_shipment_never_deadlock(): void
    {
        for ($round = 1; $round <= self::ROUNDS; $round++) {
            [$po1, $s1, $qItem] = $this->createPoWithShipment("SHP/10/2026/C4A-{$round}");
            $po2 = PurchaseOrder::create([
                'po_number' => "PO/CONC2/{$round}-".uniqid(),
                'supplier_id' => $this->supplier->id,
                'currency' => 'USD',
                'status' => 'active',
                'created_by' => $this->purchasing->id,
                'estimated_arrival' => now()->addDays(14),
            ]);
            foreach (ShipmentDocument::DOC_TYPES as $docType) {
                PoDocument::create([
                    'po_id' => $po2->id,
                    'doc_type' => $docType,
                    'status' => 'pending',
                ]);
            }

            // Link s1 to po2 as well (multi-PO shipment)
            $s1->items()->create([
                'purchase_order_id' => $po2->id,
                'quotation_item_id' => $qItem->id,
                'shipped_qty' => 1,
                'actual_weight_kg' => 20.0,
            ]);

            $s2 = $this->createAdditionalShipment($po1, $qItem, "SHP/10/2026/C4B-{$round}");
            $s2->items()->create([
                'purchase_order_id' => $po2->id,
                'quotation_item_id' => $qItem->id,
                'shipped_qty' => 1,
                'actual_weight_kg' => 20.0,
            ]);

            // Worker 1 updates {S1, S2} on PO1 ('invoice' -> 'received'); Worker 2 updates {S2, S1} on PO2 ('invoice' -> 'received')
            $outcomes = $this->race([
                [
                    'po-batch',
                    [
                        'po_id' => $po1->id,
                        'actor_id' => $this->purchasing->id,
                        'doc_type' => 'invoice',
                        'target' => 'received',
                        'rows' => [
                            ['shipment' => $s1->hash, 'expected_status' => 'pending'],
                            ['shipment' => $s2->hash, 'expected_status' => 'pending'],
                        ],
                    ],
                ],
                [
                    'po-batch',
                    [
                        'po_id' => $po2->id,
                        'actor_id' => $this->purchasing->id,
                        'doc_type' => 'invoice',
                        'target' => 'received',
                        'rows' => [
                            ['shipment' => $s2->hash, 'expected_status' => 'pending'],
                            ['shipment' => $s1->hash, 'expected_status' => 'pending'],
                        ],
                    ],
                ],
            ], sort: true);

            $this->assertNotContains('deadlock', $outcomes, 'Reversed batch order must never deadlock');
            $this->assertSame(['refused:stale_state:stale_status', 'updated:2:skipped:0'], $outcomes);
            $this->assertSame('received', $po1->documents()->where('doc_type', 'invoice')->value('status'));
            $this->assertSame('received', $po2->documents()->where('doc_type', 'invoice')->value('status'));
        }
    }

    /**
     * @param  list<array{0: string, 1: array<string, mixed>}>  $jobs
     * @return list<string>
     */
    private function race(array $jobs, bool $sort = true): array
    {
        $start = (string) (microtime(true) + 1.4);
        $processes = array_map(fn (array $job) => new Process(
            [
                PHP_BINARY,
                base_path('tests/Support/po-shipment-document-worker.php'),
                $job[0],
                $start,
                $this->privateRoot,
                base64_encode(json_encode($job[1], JSON_THROW_ON_ERROR)),
            ],
            base_path(),
            ['APP_ENV' => 'testing'],
        ), $jobs);

        foreach ($processes as $process) {
            $process->start();
        }

        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().' '.$process->getOutput());
        }

        $outcomes = array_map(fn (Process $process) => trim($process->getOutput()), $processes);
        if ($sort) {
            sort($outcomes);
        }

        return $outcomes;
    }

    /**
     * @return array{0: PurchaseOrder, 1: Shipment, 2: QuotationItem}
     */
    private function createPoWithShipment(string $shipmentNumber): array
    {
        $period = Period::firstOrCreate(
            ['name' => 'Gate 2 Concurrency Period', 'year' => 2026],
            ['status' => 'open', 'created_by' => $this->purchasing->id],
        );

        $pr = PurchaseRequisition::create([
            'period_id' => $period->id,
            'pr_number' => 'REQ/G2CONC/'.uniqid(),
            'status' => 'bidding',
            'created_by' => $this->purchasing->id,
        ]);

        $prItem = PrItem::create([
            'pr_id' => $pr->id,
            'hs_code' => '7209.16.00',
            'material_name' => 'SKD11',
            'quantity' => 8,
            'shape' => PrItem::SHAPE_FLAT,
            'thickness' => 10,
            'width' => 100,
            'length' => 500,
            'weight_needed' => 20,
        ]);

        $quotation = Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $this->supplier->id,
            'currency' => 'USD',
            'status' => Quotation::STATUS_SUBMITTED,
        ]);

        $qItem = $quotation->items()->create([
            'pr_item_id' => $prItem->id,
            'price_per_kg' => 5.0,
            'amount' => 800.0,
            'is_available' => true,
            'available_qty' => 8,
            'available_thickness' => 10,
            'available_width' => 100,
            'available_length' => 500,
            'offered_weight_per_unit' => 20,
        ]);

        $po = PurchaseOrder::create([
            'po_number' => 'PO/CONC/'.uniqid(),
            'supplier_id' => $this->supplier->id,
            'currency' => 'USD',
            'status' => 'active',
            'created_by' => $this->purchasing->id,
            'estimated_arrival' => now()->addDays(14),
        ]);

        foreach (ShipmentDocument::DOC_TYPES as $docType) {
            PoDocument::create([
                'po_id' => $po->id,
                'doc_type' => $docType,
                'status' => 'pending',
            ]);
        }

        $shipment = $this->createAdditionalShipment($po, $qItem, $shipmentNumber);

        return [$po->fresh(['documents']), $shipment, $qItem];
    }

    private function createAdditionalShipment(PurchaseOrder $po, QuotationItem $qItem, string $shipmentNumber): Shipment
    {
        $shipment = Shipment::create([
            'shipment_number' => $shipmentNumber,
            'supplier_id' => $this->supplier->id,
            'status' => Shipment::STATUS_SUBMITTED,
            'shipment_date' => now(),
            'estimated_arrival_date' => now()->addDays(14),
            'created_by' => $this->supplier->id,
        ]);

        $shipment->items()->create([
            'purchase_order_id' => $po->id,
            'quotation_item_id' => $qItem->id,
            'shipped_qty' => 1,
            'actual_weight_kg' => 20.0,
        ]);

        foreach (ShipmentDocument::DOC_TYPES as $docType) {
            $shipment->documents()->create([
                'doc_type' => $docType,
                'status' => ShipmentDocument::STATUS_PENDING,
            ]);
        }

        return $shipment->fresh(['documents', 'items']);
    }

    private function storeValidatedAttachment(ShipmentDocument $doc, string $fileName): Attachment
    {
        $relPath = 'attachments/conc/'.$fileName.'-'.uniqid().'.pdf';
        $fullPath = $this->privateRoot.'/'.$relPath;
        File::ensureDirectoryExists(dirname($fullPath));
        $bytes = NativeFileFixtures::pdf();
        File::put($fullPath, $bytes);

        $inspection = FileInspection::create([
            'profile' => 'shipment',
            'status' => FileInspection::STATUS_VALIDATED,
            'policy_version' => 'v1',
            'file_path' => $relPath,
            'file_size' => strlen($bytes),
            'file_type' => 'application/pdf',
            'sha256' => hash('sha256', $bytes),
            'inspected_at' => now(),
        ]);

        return Attachment::create([
            'attachable_type' => ShipmentDocument::class,
            'attachable_id' => $doc->id,
            'file_path' => $relPath,
            'file_name' => $fileName,
            'file_type' => 'application/pdf',
            'file_inspection_id' => $inspection->id,
            'uploaded_by' => $this->supplier->id,
        ]);
    }
}
