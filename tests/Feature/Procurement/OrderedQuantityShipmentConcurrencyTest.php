<?php

namespace Tests\Feature\Procurement;

use App\Models\PurchaseOrder;
use App\Models\ShipmentItem;
use App\Services\PrItemAwardService;
use App\Services\PurchaseOrderGenerationService;
use App\Services\ShipmentService;
use App\Support\Procurement\ProcurementFeatures;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\Support\AttachesShipmentMtc;
use Tests\TestCase;

/**
 * AC-03 with real commits: two shipment submissions racing against a PO whose
 * frozen ordered quantity is 12 can never ship more than 12 in total.
 */
class OrderedQuantityShipmentConcurrencyTest extends TestCase
{
    use AttachesShipmentMtc;
    use DatabaseTruncation;
    use ProcurementFixtures;

    // Retain the migration-owned singleton used to serialize PO ZIP admission.
    protected $exceptTables = ['local_po_document_capacity'];

    protected function beforeTruncatingDatabase(): void
    {
        $this->assertSame('adasi_portal_test', DB::connection()->getDatabaseName());
    }

    // Committed rows must not leak into later RefreshDatabase tests.
    protected function tearDown(): void
    {
        $this->truncateTablesForAllConnections();
        parent::tearDown();
    }

    public function test_concurrent_submissions_never_exceed_the_frozen_ordered_quantity(): void
    {
        Storage::fake('private');
        $this->setUpProcurementActors();
        config(['procurement.ordered_quantity_write_enabled' => true, 'procurement.allow_offer_surplus' => true]);
        $pr = $this->createRequisition([['quantity' => 10, 'weight_needed' => 3]]);
        $quotation = $this->createQuotation($pr, [['available_qty' => 15, 'offered_weight_per_unit' => 3]]);
        app(PrItemAwardService::class)->awardBatch($pr, [$pr->items[0]->id => $quotation->items[0]->id], $this->purchasing, [$pr->items[0]->id => ['order_qty' => 12]]);
        $po = app(PurchaseOrderGenerationService::class)->generateFromAwards($pr->fresh()->awards->pluck('id')->all(), $this->purchasing)->sole();
        $this->assertSame(12, $po->awards()->sole()->ordered_qty);

        $line = ['purchase_order_id' => $po->id, 'quotation_item_id' => $quotation->items[0]->id, 'shipped_qty' => 7, 'actual_weight_kg' => 21];
        $drafts = [
            app(ShipmentService::class)->createDraft($this->supplier, ['items' => [$line]]),
            app(ShipmentService::class)->createDraft($this->supplier, ['items' => [$line]]),
        ];
        foreach ($drafts as $draft) {
            $this->attachValidatedMtc($draft);
        }
        $start = (string) (microtime(true) + 2);
        $processes = array_map(fn ($draft) => new Process(
            [PHP_BINARY, base_path('tests/Support/shipment-ordered-qty-worker.php'), (string) $draft->id, $start],
            base_path(),
            ['APP_ENV' => 'testing', 'PROCUREMENT_SHIPMENT_MTC_ENABLED' => ProcurementFeatures::shipmentMtcEnabled() ? 'true' : 'false'],
        ), $drafts);
        foreach ($processes as $process) {
            $process->start();
        }
        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        }

        // The loser is refused by the balance check or, under true concurrency,
        // rolled back by a MySQL deadlock (pre-existing ShipmentService locking);
        // either way nothing beyond the ordered quantity is shipped.
        $outcomes = array_map(fn (Process $process) => trim($process->getOutput()), $processes);
        sort($outcomes);
        $this->assertContains($outcomes, [['refused', 'submitted'], ['deadlock', 'submitted']]);
        $shipped = ShipmentItem::query()
            ->whereHas('shipment', fn ($shipment) => $shipment->where('status', 'submitted'))
            ->where('purchase_order_id', $po->id)
            ->sum('shipped_qty');
        $this->assertSame(7, (int) $shipped);
        $this->assertSame(12, (int) PurchaseOrder::query()->findOrFail($po->id)->total_ordered_quantity);
    }
}
