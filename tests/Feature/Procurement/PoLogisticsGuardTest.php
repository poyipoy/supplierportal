<?php

namespace Tests\Feature\Procurement;

use App\Models\PrItemAward;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Services\PrItemAwardService;
use App\Services\PurchaseOrderGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Q1 / I-11 / I-18 / AC-30 / AC-42: every PO generation validates that all
 * participating PRs share the same Port of Discharge and Incoterms (NULL is a
 * distinct value), never picks one, never splits, and snapshots the value.
 */
class PoLogisticsGuardTest extends TestCase
{
    use ProcurementFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurementActors();
    }

    public function test_uniform_logistics_are_snapshotted_on_the_purchase_order(): void
    {
        [$a1] = $this->award('TANJUNG_PRIOK', 'CIF');
        [$a2] = $this->award('TANJUNG_PRIOK', 'CIF');

        $po = $this->generate([$a1, $a2]);

        $this->assertSame(['TANJUNG_PRIOK', 'CIF'], [$po->port_of_discharge, $po->incoterm]);
    }

    public function test_two_legacy_requisitions_without_logistics_still_consolidate_with_a_null_snapshot(): void
    {
        [$a1] = $this->award(null, null);
        [$a2] = $this->award(null, null);

        $po = $this->generate([$a1, $a2]);

        $this->assertNull($po->port_of_discharge);
        $this->assertNull($po->incoterm);
    }

    public function test_mixed_logistics_are_rejected_listing_every_requisition_and_creating_nothing(): void
    {
        [$a1, $pr1] = $this->award('TANJUNG_PRIOK', 'CIF');
        [$a2, $pr2] = $this->award('TANJUNG_PERAK', 'FOB');

        try {
            $this->generate([$a1, $a2]);
            $this->fail('Mixed logistics must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString($pr1->pr_number.' (Tanjung Priok, CIF)', $exception->getMessage());
            $this->assertStringContainsString($pr2->pr_number.' (Tanjung Perak, FOB)', $exception->getMessage());
        }

        $this->assertDatabaseCount('purchase_orders', 0);
        $this->assertDatabaseCount('po_quotations', 0);
        $this->assertNull($a1->fresh()->purchase_order_id);
    }

    public function test_null_logistics_never_mix_with_valued_logistics(): void
    {
        [$a1] = $this->award(null, null);
        [$a2] = $this->award('TANJUNG_PRIOK', 'CIF');

        $this->expectException(InvalidArgumentException::class);
        $this->generate([$a1, $a2]);
    }

    public function test_one_differing_term_is_enough_to_reject(): void
    {
        [$a1] = $this->award('TANJUNG_PRIOK', 'CIF');
        [$a2] = $this->award('TANJUNG_PRIOK', 'FOB');

        $this->expectException(InvalidArgumentException::class);
        $this->generate([$a1, $a2]);
    }

    public function test_consolidation_http_path_rejects_mixed_logistics_without_writes(): void
    {
        [$a1] = $this->award('TANJUNG_PRIOK', 'CIF');
        [$a2] = $this->award('TANJUNG_PERAK', 'FOB');

        $this->actingAs($this->purchasing)
            ->post(route('purchasing.purchase-orders.consolidate-awards.store'), [
                'award_ids' => [$a1->id, $a2->id],
                'estimated_arrival' => now()->addDays(14)->toDateString(),
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('award_ids');

        $this->assertDatabaseCount('purchase_orders', 0);
    }

    public function test_price_comparison_and_direct_paths_snapshot_the_requisition_logistics(): void
    {
        $pr = $this->requisitionWith('TANJUNG_PERAK', 'FOB');
        $quotation = $this->createQuotation($pr);
        $this->actingAs($this->purchasing)
            ->post(route('purchasing.comparison.save-awards'), [
                'pr_id' => $pr->getRouteKey(),
                'awards' => [$pr->items[0]->id => $quotation->items[0]->id],
                'action' => 'generate_pos',
            ])
            ->assertRedirect();
        $this->assertSame(['TANJUNG_PERAK', 'FOB'], [PurchaseOrder::sole()->port_of_discharge, PurchaseOrder::sole()->incoterm]);

        $pr2 = $this->requisitionWith('TANJUNG_PRIOK', 'CFR');
        $quotation2 = $this->createQuotation($pr2);
        $this->actingAs($this->purchasing)
            ->post(route('purchasing.quotations.generate-po', $quotation2))
            ->assertRedirect();
        $po2 = PurchaseOrder::query()->latest('id')->first();
        $this->assertSame(['TANJUNG_PRIOK', 'CFR'], [$po2->port_of_discharge, $po2->incoterm]);
    }

    public function test_purchasing_and_supplier_po_pages_show_the_snapshot(): void
    {
        [$a1] = $this->award('TANJUNG_PERAK', 'FOB');
        $po = $this->generate([$a1]);

        $this->actingAs($this->purchasing)->get(route('purchasing.purchase-orders.show', $po))
            ->assertOk()
            ->assertSeeText('Port of Discharge')
            ->assertSeeText('Tanjung Perak')
            ->assertSeeText('FOB — Free On Board');
        $this->actingAs($this->supplier)->get(route('supplier.purchase-orders.show', $po))
            ->assertOk()
            ->assertSeeText('Tanjung Perak')
            ->assertSeeText('FOB — Free On Board');

        [$legacy] = $this->award(null, null);
        $legacyPo = $this->generate([$legacy]);
        $this->actingAs($this->purchasing)->get(route('purchasing.purchase-orders.show', $legacyPo))
            ->assertOk()
            ->assertSeeText('Port of Discharge');
    }

    public function test_reconcile_reports_logistics_metrics(): void
    {
        [$a1] = $this->award('TANJUNG_PRIOK', 'CIF');
        $this->generate([$a1]);
        $this->requisitionWith('TANJUNG_PERAK', 'FOB');

        Artisan::call('procurement:reconcile', ['--json' => true]);
        $metrics = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['metrics'];

        $this->assertSame(2, $metrics['submitted_pr_with_logistics_count']);
        $this->assertSame(0, $metrics['po_missing_logistics_snapshot_count']);
        $this->assertSame(0, $metrics['po_mixed_logistics_count']);
        $this->assertTrue($metrics['po_generation_enabled']);
    }

    /**
     * @return array{0: PrItemAward, 1: PurchaseRequisition, 2: Quotation}
     */
    private function award(?string $port, ?string $incoterm): array
    {
        $pr = $this->requisitionWith($port, $incoterm);
        $quotation = $this->createQuotation($pr);
        $award = app(PrItemAwardService::class)->awardItem($pr->items[0], $quotation->items[0], $this->purchasing);

        return [$award, $pr, $quotation];
    }

    private function requisitionWith(?string $port, ?string $incoterm): PurchaseRequisition
    {
        $pr = $this->createRequisition();
        $pr->forceFill(['port_of_discharge' => $port, 'incoterm' => $incoterm])->save();

        return $pr->fresh(['items']);
    }

    private function generate(array $awards): PurchaseOrder
    {
        return app(PurchaseOrderGenerationService::class)
            ->generateFromAwards(collect($awards)->pluck('id')->all(), $this->purchasing)
            ->sole()
            ->fresh();
    }
}
