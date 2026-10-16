<?php

namespace Tests\Feature\Procurement;

use App\Exceptions\PoGenerationDisabledException;
use App\Models\PrItemAward;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\Shipment;
use App\Services\PrItemAwardService;
use App\Services\PurchaseOrderGenerationService;
use App\Services\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Symfony\Component\Finder\Finder;
use Tests\Support\AttachesShipmentMtc;
use Tests\TestCase;

/**
 * AC-40 / I-20: procurement.po_generation_enabled=false stops PO creation on
 * every live entry point, rolls back the awards of the same request, and leaves
 * the rest of the procurement workflow working.
 */
class PoGenerationKillSwitchTest extends TestCase
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

    public function test_switch_defaults_to_enabled(): void
    {
        $this->assertTrue(config('procurement.po_generation_enabled'));
    }

    public function test_service_refuses_before_any_write_with_an_invalid_argument_subtype(): void
    {
        $pr = $this->createRequisition();
        $quotation = $this->createQuotation($pr);
        $award = app(PrItemAwardService::class)->awardItem($pr->items[0], $quotation->items[0], $this->purchasing);
        config(['procurement.po_generation_enabled' => false]);

        try {
            app(PurchaseOrderGenerationService::class)->generateFromAwards([$award->id], $this->purchasing);
            $this->fail('PO generation must be refused while the kill switch is off.');
        } catch (PoGenerationDisabledException $exception) {
            $this->assertInstanceOf(InvalidArgumentException::class, $exception);
            $this->assertSame(__('procurement.po_generation.disabled'), $exception->getMessage());
        }

        $this->assertNoPurchaseOrderSideEffects();
        $this->assertNull($award->fresh()->purchase_order_id);
    }

    public function test_price_comparison_generate_pos_is_refused_and_rolls_back_the_awards(): void
    {
        $pr = $this->createRequisition();
        $quotation = $this->createQuotation($pr);
        config(['procurement.po_generation_enabled' => false]);

        $this->actingAs($this->purchasing)
            ->post(route('purchasing.comparison.save-awards'), [
                'pr_id' => $pr->getRouteKey(),
                'awards' => [$pr->items[0]->id => $quotation->items[0]->id],
                'action' => 'generate_pos',
            ])
            ->assertRedirect()
            ->assertSessionHas('error', __('procurement.po_generation.disabled'));

        $this->assertNoPurchaseOrderSideEffects();
        $this->assertDatabaseCount('pr_item_awards', 0);
        $this->assertSame(Quotation::STATUS_SUBMITTED, $quotation->fresh()->status);
        $this->assertSame('bidding', $pr->fresh()->status);
    }

    public function test_price_comparison_save_action_still_saves_awards(): void
    {
        $pr = $this->createRequisition();
        $quotation = $this->createQuotation($pr);
        config(['procurement.po_generation_enabled' => false]);

        $this->actingAs($this->purchasing)
            ->post(route('purchasing.comparison.save-awards'), [
                'pr_id' => $pr->getRouteKey(),
                'awards' => [$pr->items[0]->id => $quotation->items[0]->id],
                'action' => 'save',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('pr_item_awards', [
            'pr_item_id' => $pr->items[0]->id,
            'quotation_item_id' => $quotation->items[0]->id,
            'purchase_order_id' => null,
        ]);
        $this->assertNoPurchaseOrderSideEffects();
    }

    public function test_direct_quotation_generate_po_is_refused_and_rolls_back_the_awards(): void
    {
        $pr = $this->createRequisition();
        $quotation = $this->createQuotation($pr);
        config(['procurement.po_generation_enabled' => false]);

        $this->actingAs($this->purchasing)
            ->post(route('purchasing.quotations.generate-po', $quotation))
            ->assertRedirect()
            ->assertSessionHas('error', __('procurement.po_generation.disabled'));

        $this->assertNoPurchaseOrderSideEffects();
        $this->assertDatabaseCount('pr_item_awards', 0);
        $this->assertSame(Quotation::STATUS_SUBMITTED, $quotation->fresh()->status);
        $this->assertSame('bidding', $pr->fresh()->status);
    }

    public function test_award_consolidation_is_refused_and_existing_awards_stay_unassigned(): void
    {
        $pr = $this->createRequisition([[], []]);
        $quotation = $this->createQuotation($pr);
        $awards = app(PrItemAwardService::class)->awardBatch($pr, [
            $pr->items[0]->id => $quotation->items[0]->id,
            $pr->items[1]->id => $quotation->items[1]->id,
        ], $this->purchasing);
        $before = $this->awardSnapshot();
        $quotationStatus = $quotation->fresh()->status;
        config(['procurement.po_generation_enabled' => false]);

        $this->actingAs($this->purchasing)
            ->post(route('purchasing.purchase-orders.consolidate-awards.store'), [
                'award_ids' => $awards->pluck('id')->all(),
                'estimated_arrival' => now()->addDays(14)->toDateString(),
            ])
            ->assertRedirect()
            ->assertSessionHasErrors(['award_ids' => __('procurement.po_generation.disabled')]);

        $this->assertNoPurchaseOrderSideEffects();
        $this->assertSame($before, $this->awardSnapshot());
        $this->assertSame($quotationStatus, $quotation->fresh()->status);
    }

    public function test_existing_po_pdf_and_shipment_submission_keep_working_while_switched_off(): void
    {
        $pr = $this->createRequisition();
        $quotation = $this->createQuotation($pr);
        $award = app(PrItemAwardService::class)->awardItem($pr->items[0], $quotation->items[0], $this->purchasing);
        $po = app(PurchaseOrderGenerationService::class)->generateFromAwards([$award->id], $this->purchasing)->sole();
        config(['procurement.po_generation_enabled' => false]);

        $this->actingAs($this->purchasing)
            ->get(route('shared.pdf.purchase-order', $po))
            ->assertOk();

        $shipments = app(ShipmentService::class);
        $shipment = $this->submitWithMtc($shipments->createDraft($this->supplier), [
            'items' => [[
                'purchase_order_id' => $po->id,
                'quotation_item_id' => $quotation->items[0]->id,
                'shipped_qty' => 4,
                'actual_weight_kg' => 12,
            ]],
        ]);

        $this->assertSame(Shipment::STATUS_SUBMITTED, $shipment->status);
        $this->assertSame(1, PurchaseOrder::count());
    }

    public function test_po_generation_controls_render_disabled_with_a_notice_while_switched_off(): void
    {
        $pr = $this->createRequisition([[], []]);
        $quotation = $this->createQuotation($pr);
        app(PrItemAwardService::class)->awardItem($pr->items[1], $quotation->items[1], $this->purchasing);
        $notice = e(__('procurement.po_generation.disabled_notice'));
        // A bare disabled attribute, not the Tailwind "disabled:" class variants.
        $disabledAttribute = '\sdisabled(?=[\s>])';
        $pages = [
            route('purchasing.comparison.inter-supplier', ['pr_id' => $pr]) => '/<button[^>]*'.$disabledAttribute.'[^>]*id="btnGeneratePos"/',
            route('purchasing.quotations.show', $quotation) => '/<button[^>]*'.$disabledAttribute.'[^>]*data-bs-target="#generatePoModal"/',
            route('purchasing.purchase-orders.consolidate-awards') => '/<button[^>]*'.$disabledAttribute.'[^>]*id="createConsolidatedPo"/',
        ];

        foreach ($pages as $url => $disabledButton) {
            $enabled = $this->actingAs($this->purchasing)->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString($notice, $enabled, $url);
            $this->assertDoesNotMatchRegularExpression($disabledButton, $enabled, $url);
        }

        config(['procurement.po_generation_enabled' => false]);

        foreach ($pages as $url => $disabledButton) {
            $disabled = $this->actingAs($this->purchasing)->get($url)->assertOk()->getContent();
            $this->assertStringContainsString($notice, $disabled, $url);
            $this->assertMatchesRegularExpression($disabledButton, $disabled, $url);
        }
    }

    public function test_purchase_order_generation_service_is_the_only_purchase_order_creator(): void
    {
        // Guards the single-choke-point assumption behind I-20. LocalPurchaseOrder
        // is a different aggregate and must not match.
        $pattern = '/(?<![A-Za-z_\\\\])(?:\\\\?App\\\\Models\\\\)?PurchaseOrder::(?:query\(\)->)?(?:create|forceCreate|firstOrCreate|updateOrCreate|insert|insertGetId)\s*\(|new\s+(?:\\\\?App\\\\Models\\\\)?PurchaseOrder\s*\(|->purchaseOrders\(\)->(?:create|save)\s*\(/';
        $creators = [];

        foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
            if (preg_match($pattern, $file->getContents()) === 1) {
                $creators[] = str_replace('\\', '/', $file->getRelativePathname());
            }
        }

        $this->assertSame(['Services/PurchaseOrderGenerationService.php'], $creators);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function awardSnapshot(): array
    {
        return PrItemAward::query()->orderBy('id')->get()
            ->map(fn (PrItemAward $award) => $award->only(['id', 'quotation_item_id', 'purchase_order_id']) + ['updated_at' => (string) $award->updated_at])
            ->all();
    }

    private function assertNoPurchaseOrderSideEffects(): void
    {
        $this->assertDatabaseCount('purchase_orders', 0);
        $this->assertDatabaseCount('po_quotations', 0);
        $this->assertDatabaseCount('po_documents', 0);
    }
}
