<?php

namespace Tests\Feature\Procurement;

use App\Exceptions\BusinessRuleArgumentException;
use App\Models\ExchangeRate;
use App\Models\Period;
use App\Models\PoItemProgressUpdate;
use App\Models\PrItem;
use App\Models\PrItemAward;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Services\MaterialProgressService;
use App\Services\PrItemAwardService;
use App\Services\PurchaseOrderGenerationService;
use App\Services\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AttachesShipmentMtc;
use Tests\TestCase;

/**
 * Ready to Ship gate (3 Nov 2026): a supplier can only draft or submit shipment lines whose
 * awarded item is at the Ready to Ship progress stage. This reverses the earlier rule that progress
 * never gates shipments (PoItemMaterialProgressTest keeps the quantity projections).
 *
 * phpunit.xml turns the gate off for the other shipment suites, which build awards without progress
 * rows; setUp() turns it on here.
 */
class ShipmentReadyToShipGuardTest extends TestCase
{
    use AttachesShipmentMtc;
    use RefreshDatabase;

    private User $purchasing;

    private User $supplierA;

    private User $supplierB;

    private ExchangeRate $exchangeRate;

    private Period $period;

    private ShipmentService $shipments;

    private MaterialProgressService $progress;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        config(['procurement.require_ready_to_ship_for_shipment' => true]);

        $this->purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $this->supplierA = User::factory()->create(['role' => 'supplier', 'name' => 'Supplier Alpha', 'is_active' => true]);
        $this->supplierB = User::factory()->create(['role' => 'supplier', 'name' => 'Supplier Beta', 'is_active' => true]);

        $this->period = Period::create([
            'name' => 'Ready To Ship Period',
            'month' => 11,
            'year' => 2026,
            'status' => 'open',
            'created_by' => $this->purchasing->id,
        ]);

        $this->exchangeRate = ExchangeRate::create([
            'currency' => 'USD',
            'rate_to_idr' => 16000,
            'valid_from' => now()->subDay(),
            'created_by' => $this->purchasing->id,
        ]);

        $this->shipments = app(ShipmentService::class);
        $this->progress = app(MaterialProgressService::class);
    }

    /**
     * @param  array<int, array{0: float, 1: int}>  $itemsConfig  List of [weight, quantity]
     */
    private function createPo(User $supplier, array $itemsConfig = [[20.0, 20]]): PurchaseOrder
    {
        $pr = PurchaseRequisition::create([
            'period_id' => $this->period->id,
            'created_by' => $this->purchasing->id,
            'pr_number' => 'REQ/11/2026/'.str_pad((string) random_int(100, 999), 3, '0', STR_PAD_LEFT),
            'status' => 'bidding',
            'notes' => 'Ready to ship guard requisition',
            'port_of_discharge' => 'TANJUNG_PRIOK',
            'incoterm' => 'CIF',
        ]);

        $quotation = Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $supplier->id,
            'currency' => 'USD',
            'exchange_rate_id' => $this->exchangeRate->id,
            'status' => Quotation::STATUS_SUBMITTED,
            'estimated_delivery' => now()->addDays(14)->toDateString(),
            'payment_terms' => 'TT 30 Days',
            'validity_period' => now()->addDays(30),
            'submitted_at' => now(),
        ]);

        $awards = collect();
        foreach ($itemsConfig as $index => [$weight, $qty]) {
            $prItem = PrItem::create([
                'pr_id' => $pr->id,
                'hs_code' => '7209.16.00',
                'material_name' => 'Steel Plate '.($index + 1),
                'quantity' => $qty,
                'shape' => PrItem::SHAPE_FLAT,
                'thickness' => 2.0,
                'width' => 100,
                'length' => 200,
                'weight_needed' => $weight,
            ]);

            $qItem = $quotation->items()->create([
                'pr_item_id' => $prItem->id,
                'is_available' => true,
                'price_per_kg' => 2.5,
                'amount' => 2.5 * $weight,
                'available_qty' => $qty,
            ]);

            $awards->push(app(PrItemAwardService::class)->awardItem($prItem, $qItem, $this->purchasing));
        }

        return app(PurchaseOrderGenerationService::class)
            ->generateFromAwards($awards, $this->purchasing, [])
            ->first();
    }

    private function setProgress(PurchaseOrder $po, PrItemAward $award, string $status): void
    {
        // A note keeps backward moves valid; forward moves ignore it.
        $this->progress->updateProgress($po, $award, $this->supplierA, ['status' => $status, 'note' => 'Test update']);
    }

    /**
     * @return array{purchase_order_id: int, quotation_item_id: int, shipped_qty: int, actual_weight_kg: float}
     */
    private function line(PurchaseOrder $po, PrItemAward $award, int $qty): array
    {
        return [
            'purchase_order_id' => $po->id,
            'quotation_item_id' => $award->quotation_item_id,
            'shipped_qty' => $qty,
            'actual_weight_kg' => (float) $qty,
        ];
    }

    private function assertRefused(callable $attempt, string $material = 'Steel Plate 1'): void
    {
        try {
            $attempt();
            $this->fail('The Ready to Ship gate let a not-ready item through.');
        } catch (BusinessRuleArgumentException $e) {
            $this->assertStringContainsString($material, $e->getMessage());
            $this->assertStringContainsString('Ready to Ship', $e->getMessage());
        }
    }

    public function test_submit_is_refused_until_the_item_is_ready_to_ship(): void
    {
        $po = $this->createPo($this->supplierA);
        $award = $po->awards->first();
        $draft = $this->shipments->createDraft($this->supplierA);
        $payload = ['items' => [$this->line($po, $award, 5)]];

        // No progress row means awaiting confirmation, which is not ready.
        $this->assertRefused(fn () => $this->submitWithMtc($draft, $payload));

        foreach ([
            PoItemProgressUpdate::STATUS_ORDER_CONFIRMED,
            PoItemProgressUpdate::STATUS_MATERIAL_PREPARATION,
            PoItemProgressUpdate::STATUS_ON_PRODUCTION,
        ] as $stage) {
            $this->setProgress($po, $award, $stage);
            $this->assertRefused(fn () => $this->submitWithMtc($draft, $payload));
        }

        $this->assertSame(Shipment::STATUS_DRAFT, $draft->fresh()->status);
        $this->assertSame(0, $draft->fresh()->items()->count());

        $this->setProgress($po, $award, PoItemProgressUpdate::STATUS_READY_TO_SHIP);
        $submitted = $this->submitWithMtc($draft, $payload);

        $this->assertSame(Shipment::STATUS_SUBMITTED, $submitted->status);
        $this->assertSame(5, (int) $submitted->items()->sum('shipped_qty'));
    }

    public function test_saving_a_draft_with_a_not_ready_item_is_refused(): void
    {
        $po = $this->createPo($this->supplierA);
        $award = $po->awards->first();
        $payload = ['items' => [$this->line($po, $award, 5)]];

        $this->assertRefused(fn () => $this->shipments->createDraft($this->supplierA, $payload));
        $this->assertSame(0, Shipment::count(), 'The refused draft must roll back with its transaction.');

        $this->setProgress($po, $award, PoItemProgressUpdate::STATUS_READY_TO_SHIP);
        $draft = $this->shipments->createDraft($this->supplierA, $payload);
        $this->assertSame(1, $draft->items->count());

        // Progress goes back (a supplier may move it back with a note): the same lines can no longer be saved.
        $this->setProgress($po, $award, PoItemProgressUpdate::STATUS_ON_PRODUCTION);
        $this->assertRefused(fn () => $this->shipments->updateDraft($draft, $this->supplierA, [
            'shipment_date' => now()->toDateString(),
            'estimated_arrival_date' => now()->addDays(7)->toDateString(),
            'items' => $payload['items'],
        ]));
    }

    public function test_remainder_is_blocked_after_progress_moves_back_following_a_partial_shipment(): void
    {
        $po = $this->createPo($this->supplierA);
        $award = $po->awards->first();
        $this->setProgress($po, $award, PoItemProgressUpdate::STATUS_READY_TO_SHIP);

        $first = $this->submitWithMtc($this->shipments->createDraft($this->supplierA), ['items' => [$this->line($po, $award, 8)]]);
        $this->assertSame(Shipment::STATUS_SUBMITTED, $first->status);

        $this->setProgress($po, $award, PoItemProgressUpdate::STATUS_ON_PRODUCTION);
        $second = $this->shipments->createDraft($this->supplierA);
        $this->assertRefused(fn () => $this->submitWithMtc($second, ['items' => [$this->line($po, $award, 12)]]));

        $this->setProgress($po, $award, PoItemProgressUpdate::STATUS_READY_TO_SHIP);
        $this->assertSame(
            Shipment::STATUS_SUBMITTED,
            $this->submitWithMtc($second, ['items' => [$this->line($po, $award, 12)]])->status,
        );
    }

    public function test_purchase_order_without_awards_is_not_gated(): void
    {
        $po = $this->createPo($this->supplierA);
        $award = $po->awards->first();
        // Legacy shape: the quotation stays linked through po_quotations, but no award points at the PO.
        PrItemAward::where('purchase_order_id', $po->id)->update(['purchase_order_id' => null]);

        $draft = $this->shipments->createDraft($this->supplierA, ['items' => [$this->line($po, $award, 5)]]);

        $this->assertSame(1, $draft->items->count());
    }

    public function test_switching_the_gate_off_restores_the_previous_behaviour(): void
    {
        config(['procurement.require_ready_to_ship_for_shipment' => false]);
        $po = $this->createPo($this->supplierA);
        $award = $po->awards->first();

        $shipment = $this->submitWithMtc($this->shipments->createDraft($this->supplierA), ['items' => [$this->line($po, $award, 5)]]);

        $this->assertSame(Shipment::STATUS_SUBMITTED, $shipment->status);
    }

    public function test_create_page_offers_only_ready_items_and_explains_the_hidden_ones(): void
    {
        $po = $this->createPo($this->supplierA, [[20.0, 20], [10.0, 10]]);
        [$ready, $notReady] = [$po->awards->first(), $po->awards->last()];
        $this->setProgress($po, $ready, PoItemProgressUpdate::STATUS_READY_TO_SHIP);
        $notReadyName = $notReady->prItem->material_name;
        $this->assertNotSame($ready->prItem->material_name, $notReadyName);

        $this->actingAs($this->supplierA)
            ->get(route('supplier.shipments.create'))
            ->assertOk()
            ->assertSee($ready->prItem->material_name)
            ->assertDontSee($notReadyName)
            ->assertSee('1 item is hidden');
    }

    public function test_create_page_explains_an_empty_picker_and_never_shows_another_suppliers_po(): void
    {
        $poA = $this->createPo($this->supplierA);
        $poB = $this->createPo($this->supplierB);

        // Nothing ready for supplier A: the empty state says why, and shows no PO of supplier B.
        $this->actingAs($this->supplierA)
            ->get(route('supplier.shipments.create'))
            ->assertOk()
            ->assertSee('No item can be shipped yet.')
            ->assertDontSee($poB->po_number);

        $this->assertNotSame($poA->po_number, $poB->po_number);
    }

    public function test_edit_page_keeps_a_draft_line_that_is_no_longer_ready_and_flags_it(): void
    {
        $po = $this->createPo($this->supplierA);
        $award = $po->awards->first();
        $this->setProgress($po, $award, PoItemProgressUpdate::STATUS_READY_TO_SHIP);
        $draft = $this->shipments->createDraft($this->supplierA, ['items' => [$this->line($po, $award, 5)]]);
        $this->setProgress($po, $award, PoItemProgressUpdate::STATUS_ON_PRODUCTION);

        $this->actingAs($this->supplierA)
            ->get(route('supplier.shipments.edit', $draft))
            ->assertOk()
            ->assertSee('Not Ready to Ship');

        $this->actingAs($this->supplierA)
            ->put(route('supplier.shipments.update', $draft), [
                'shipment_date' => now()->addDay()->toDateString(),
                'estimated_arrival_date' => now()->addDays(8)->toDateString(),
                'items' => [$this->line($po, $award, 5)],
            ])
            ->assertSessionHas('error');

        $this->assertSame(Shipment::STATUS_DRAFT, $draft->fresh()->status);
    }

    public function test_submitting_through_the_form_cannot_bypass_the_gate(): void
    {
        $po = $this->createPo($this->supplierA);
        $award = $po->awards->first();

        $this->actingAs($this->supplierA)
            ->post(route('supplier.shipments.store'), [
                'action' => 'submit',
                'shipment_date' => now()->toDateString(),
                'estimated_arrival_date' => now()->addDays(7)->toDateString(),
                'items' => [$this->line($po, $award, 5)],
            ])
            ->assertSessionHas('error');

        $this->assertSame(0, Shipment::where('status', Shipment::STATUS_SUBMITTED)->count());
        $this->assertSame(0, ShipmentItem::count());
    }
}
