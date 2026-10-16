<?php

namespace Tests\Feature\Procurement;

use App\Exports\PurchaseOrderDetailExport;
use App\Exports\PurchaseOrdersExport;
use App\Models\PrItemAward;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\User;
use App\Services\PrItemAwardService;
use App\Services\PurchaseOrderGenerationService;
use App\Services\ShipmentService;
use App\Support\Procurement\OrderedLine;
use App\Support\PurchaseOrderPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\Support\AttachesShipmentMtc;
use Tests\TestCase;

/**
 * Mission 04-R: every PO consumer reads ordered qty/KG/amount through
 * PurchaseOrder::orderedLines() (I-15, I-17), transactional IDR never uses an
 * invented rate (Q4C), and PO generation fails closed for selections the
 * disabled write path cannot finalize (I-21).
 *
 * Fixture: PR 10 pcs x 3 kg; offer 15 pcs x 3.2 kg at 2.5/kg; USD 16,000.
 */
class OrderedQuantityReadPathTest extends TestCase
{
    use AttachesShipmentMtc;
    use ProcurementFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        $this->setUpProcurementActors();
    }

    public function test_frozen_values_drive_every_consumer(): void
    {
        $po = $this->frozenPurchaseOrder();
        $line = $po->orderedLines()->sole();
        $this->assertSame(OrderedLine::SOURCE_FROZEN, $line->source);

        $this->assertSame(12, $po->total_ordered_quantity);
        $this->assertEqualsWithDelta(38.4, $po->total_ordered_weight, 0.00001);
        $this->assertSame(12.0, $po->itemFulfillmentStatus($line->quotationItem->id)['ordered']);
        $this->assertSame('96.0000', $po->orderedAmountTotal());
        $this->assertSame('1536000.0000', $po->orderedAmountIdr());

        $projected = PurchaseOrder::withResolvedTotalIdr()->findOrFail($po->id);
        $this->assertEqualsWithDelta(96 * 16000, (float) $projected->resolved_total_idr, 0.0001);

        $list = (new PurchaseOrdersExport)->map($po->fresh());
        $this->assertEqualsWithDelta(96.0, $list[5], 0.0001);
        $this->assertEqualsWithDelta(96 * 16000, $list[6], 0.0001);

        $detail = (new PurchaseOrderDetailExport($po->id))->collection();
        $this->assertEqualsWithDelta(96.0, $detail->sum(fn ($row) => $row[10]), 0.0001);

        $pdf = PurchaseOrderPdf::data($po->fresh());
        $this->assertSame('96.00', $pdf['total']);
        $this->assertStringContainsString('12.00 pcs', json_encode($pdf, JSON_UNESCAPED_UNICODE));
    }

    public function test_shipments_are_limited_by_the_frozen_ordered_quantity(): void
    {
        $po = $this->frozenPurchaseOrder();
        $item = $po->orderedLines()->sole()->quotationItem;
        $shipments = app(ShipmentService::class);

        try {
            $this->submitWithMtc($shipments->createDraft($this->supplier), ['items' => [[
                'purchase_order_id' => $po->id, 'quotation_item_id' => $item->id, 'shipped_qty' => 13, 'actual_weight_kg' => 41.6,
            ]]]);
            $this->fail('Shipping more than the ordered quantity must be refused.');
        } catch (ValidationException|InvalidArgumentException) {
            // expected
        }

        $ok = $this->submitWithMtc($shipments->createDraft($this->supplier), ['items' => [[
            'purchase_order_id' => $po->id, 'quotation_item_id' => $item->id, 'shipped_qty' => 12, 'actual_weight_kg' => 38.4,
        ]]]);
        $this->assertSame(12, (int) $ok->items->sum('shipped_qty'));
    }

    public function test_sql_total_matches_the_resolver_for_every_source(): void
    {
        $legacyAward = $this->generate($this->requisitionAndQuotation(10, 10));
        $selected = $this->generate($this->requisitionAndQuotation(10, 10));
        $selected->awards->sole()->forceFill(['ordered_qty' => 8])->save();
        $frozen = $this->frozenPurchaseOrder();
        $legacyPo = $this->legacyPurchaseOrder();

        foreach ([$legacyAward, $selected, $frozen, $legacyPo] as $po) {
            $po = $po->fresh();
            $projected = PurchaseOrder::withResolvedTotalIdr()->findOrFail($po->id);
            $this->assertEqualsWithDelta((float) $po->orderedAmountIdr(), (float) $projected->resolved_total_idr, 0.0001, $po->orderedLines()->first()?->source);
        }
    }

    public function test_missing_foreign_snapshot_rate_is_unavailable_never_one_or_zero(): void
    {
        $po = $this->generate($this->requisitionAndQuotation(10, 10, 'CNY'));
        $this->assertNull($po->exchange_rate_id);

        $this->assertNull($po->orderedAmountIdr());
        $this->assertNull(PurchaseOrder::withResolvedTotalIdr()->findOrFail($po->id)->resolved_total_idr);
        $this->assertNull((new PurchaseOrdersExport)->map($po->fresh())[6]);
        $this->assertNull((new PurchaseOrderDetailExport($po->id))->collection()->first()[12]);

        $row = $this->actingAs($this->purchasing)
            ->getJson(route('purchasing.purchase-orders.index').'?draw=1&start=0&length=10', ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->json('data.0.total_idr');
        $this->assertStringContainsString('—', $row);
        $this->assertStringContainsString(__('purchasing.copy.exchange_rate_unavailable'), $row);
    }

    public function test_idr_documents_use_the_identity_rate(): void
    {
        $po = $this->generate($this->requisitionAndQuotation(10, 10, 'IDR'));

        $this->assertSame($po->orderedAmountTotal(), $po->orderedAmountIdr());
    }

    public function test_guard_a_refuses_unselected_surplus_and_shortage_while_the_write_path_is_off(): void
    {
        foreach ([15, 7] as $offered) {
            [$pr, $quotation] = $this->requisitionAndQuotation(10, $offered);
            $award = app(PrItemAwardService::class)->awardItem($pr->items[0], $quotation->items[0], $this->purchasing);

            $this->assertRefused(fn () => $this->generateAwards([$award]), __('procurement.ordered_quantity.selection_required'));
            $this->assertNull($award->fresh()->purchase_order_id);
        }

        $this->assertDatabaseCount('purchase_orders', 0);
    }

    public function test_guard_a_applies_on_the_http_entry_points(): void
    {
        [$pr, $quotation] = $this->requisitionAndQuotation(10, 15);
        $this->actingAs($this->purchasing)
            ->post(route('purchasing.quotations.generate-po', $quotation))
            ->assertSessionHas('error', fn (string $error) => str_contains($error, __('procurement.ordered_quantity.selection_required')));
        $this->actingAs($this->purchasing)
            ->post(route('purchasing.comparison.save-awards'), [
                'pr_id' => $pr->getRouteKey(),
                'awards' => [$pr->items[0]->id => $quotation->items[0]->id],
                'action' => 'generate_pos',
            ])
            ->assertSessionHas('error', fn (string $error) => str_contains($error, __('procurement.ordered_quantity.selection_required')));

        $this->assertDatabaseCount('purchase_orders', 0);
        $this->assertDatabaseCount('pr_item_awards', 0);
    }

    public function test_guard_b_refuses_any_unassigned_selection_even_when_offer_equals_request(): void
    {
        [$pr, $quotation] = $this->requisitionAndQuotation(10, 10);
        $award = app(PrItemAwardService::class)->awardItem($pr->items[0], $quotation->items[0], $this->purchasing);
        $award->forceFill(['ordered_qty' => 10])->save();
        $before = $award->fresh()->getAttributes();

        $this->assertRefused(fn () => $this->generateAwards([$award]), __('procurement.ordered_quantity.selection_unfinalizable'));
        $this->assertSame($before, $award->fresh()->getAttributes());

        $award->forceFill(['ordered_qty' => null, 'spec_accepted_at' => now()])->save();
        $this->assertRefused(fn () => $this->generateAwards([$award]), __('procurement.ordered_quantity.selection_unfinalizable'));
        $this->assertDatabaseCount('purchase_orders', 0);
    }

    public function test_equal_offer_without_selection_still_generates_on_the_legacy_path(): void
    {
        $po = $this->generate($this->requisitionAndQuotation(10, 10));

        $this->assertSame(OrderedLine::SOURCE_LEGACY_AWARD, $po->orderedLines()->sole()->source);
    }

    public function test_repointing_an_award_clears_its_selection_and_records_an_event(): void
    {
        $pr = $this->createRequisition();
        $first = $this->createQuotation($pr);
        $second = $this->createQuotation($pr, [], Quotation::STATUS_SUBMITTED, $this->otherSupplier());
        $awards = app(PrItemAwardService::class);
        $award = $awards->awardItem($pr->items[0], $first->items[0], $this->purchasing);
        $award->forceFill([
            'ordered_qty' => 9, 'spec_accepted_by' => $this->purchasing->id, 'spec_accepted_at' => now(),
            'spec_acceptance_snapshot' => ['x' => 1], 'spec_acceptance_hash' => str_repeat('a', 64),
        ])->save();

        $repointed = $awards->awardItem($pr->items[0], $second->items[0], $this->purchasing);

        $this->assertSame($award->id, $repointed->id);
        $fresh = $repointed->fresh();
        $this->assertSame($second->items[0]->id, $fresh->quotation_item_id);
        foreach (['ordered_qty', 'spec_accepted_by', 'spec_accepted_at', 'spec_acceptance_snapshot', 'spec_acceptance_hash'] as $column) {
            $this->assertNull($fresh->getAttribute($column), $column);
        }
        $event = $fresh->events()->sole();
        $this->assertSame('selection_cleared', $event->event);
        $this->assertSame($first->items[0]->id, $event->payload['previous_quotation_item_id']);
        $this->assertSame(9, $event->payload['previous_ordered_qty']);
    }

    public function test_repointing_an_award_without_a_selection_writes_no_event(): void
    {
        $pr = $this->createRequisition();
        $first = $this->createQuotation($pr);
        $second = $this->createQuotation($pr, [], Quotation::STATUS_SUBMITTED, $this->otherSupplier());
        $awards = app(PrItemAwardService::class);
        $awards->awardItem($pr->items[0], $first->items[0], $this->purchasing);
        $awards->awardItem($pr->items[0], $second->items[0], $this->purchasing);

        $this->assertDatabaseCount('pr_item_award_events', 0);
    }

    public function test_reconcile_reports_the_read_path_metrics(): void
    {
        $this->frozenPurchaseOrder();
        $this->generate($this->requisitionAndQuotation(10, 10));
        [$pr, $quotation] = $this->requisitionAndQuotation(10, 10);
        app(PrItemAwardService::class)->awardItem($pr->items[0], $quotation->items[0], $this->purchasing)
            ->forceFill(['ordered_qty' => 10])->save();
        config(['procurement.allow_offer_surplus' => true]);

        Artisan::call('procurement:reconcile', ['--json' => true]);
        $metrics = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['metrics'];

        $this->assertSame(1, $metrics['frozen_po_count']);
        $this->assertSame(1, $metrics['divergent_frozen_po_count']);
        $this->assertSame(1, $metrics['unassigned_selected_award_count']);
        $this->assertTrue($metrics['inconsistent_feature_flags']);
    }

    private function assertRefused(callable $generate, string $message): void
    {
        try {
            $generate();
            $this->fail('PO generation must be refused.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    private function frozenPurchaseOrder(): PurchaseOrder
    {
        // Generated while equal, then frozen as M04-W would leave it (12 of 15).
        $po = $this->generate($this->requisitionAndQuotation(15, 15, 'USD', 3.2));
        $po->awards->sole()->forceFill([
            'ordered_qty' => 12,
            'ordered_weight_per_unit' => '3.2000',
            'ordered_total_weight_kg' => '38.4000',
            'ordered_price_per_kg' => '2.5000',
            'ordered_amount' => '96.0000',
        ])->save();

        return $po->fresh();
    }

    /**
     * @return array{0: PurchaseRequisition, 1: Quotation}
     */
    private function requisitionAndQuotation(int $requested, int $offered, string $currency = 'USD', float $weightPerUnit = 3.0): array
    {
        $pr = $this->createRequisition([['quantity' => $requested, 'weight_needed' => 3]]);
        $quotation = $this->createQuotation($pr, [['available_qty' => $offered, 'offered_weight_per_unit' => $weightPerUnit]]);
        if ($currency !== 'USD') {
            $quotation->forceFill(['currency' => $currency, 'exchange_rate_id' => null])->save();
        }

        return [$pr, $quotation->fresh(['items'])];
    }

    private function generate(array $prAndQuotation): PurchaseOrder
    {
        [$pr, $quotation] = $prAndQuotation;
        $award = app(PrItemAwardService::class)->awardItem($pr->items[0], $quotation->items[0], $this->purchasing);

        return $this->generateAwards([$award]);
    }

    private function generateAwards(array $awards): PurchaseOrder
    {
        return app(PurchaseOrderGenerationService::class)
            ->generateFromAwards(collect($awards)->map(fn (PrItemAward $award) => $award->id)->all(), $this->purchasing)
            ->sole()
            ->fresh();
    }

    private function legacyPurchaseOrder(): PurchaseOrder
    {
        [, $quotation] = $this->requisitionAndQuotation(10, 10);
        $po = PurchaseOrder::create([
            'supplier_id' => $this->supplier->id, 'currency' => 'USD', 'exchange_rate_id' => $this->usdRate->id,
            'po_number' => 'PO/10/2026/'.uniqid(), 'status' => 'active', 'created_by' => $this->purchasing->id,
        ]);
        $po->quotations()->attach($quotation->id);

        return $po;
    }

    private function otherSupplier(): User
    {
        return User::factory()->create(['role' => 'supplier', 'is_active' => true]);
    }
}
