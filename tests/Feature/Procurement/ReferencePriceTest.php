<?php

namespace Tests\Feature\Procurement;

use App\Models\AwardReferencePriceSnapshot;
use App\Models\MaterialMaster;
use App\Models\MaterialReferencePrice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Services\Pricing\MaterialReferencePriceService;
use App\Services\Pricing\ReferencePriceResolver;
use App\Services\PrItemAwardService;
use App\Services\PurchaseOrderGenerationService;
use App\Support\Pricing\EvaluationContext;
use App\Support\Pricing\ReferencePriceResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Mission 06B (§12.2, Q6A, I-19, I-22): Master Reference Price writes,
 * resolver reason codes, PO finalization snapshots and the pre-activation
 * "not captured" rule (AC-15..18, AC-28, AC-37, AC-41, AC-44).
 */
class ReferencePriceTest extends TestCase
{
    use ProcurementFixtures;
    use RefreshDatabase;

    private MaterialMaster $material;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurementActors();
        $this->material = MaterialMaster::create([
            'material_code' => 'M06B-SKD11', 'normalized_code' => 'M06B-SKD11', 'raw_category' => 'Fixture',
            'hs_category' => 'alloy_steel', 'density_profile' => 'steel', 'manufacturer_scope' => 'unknown', 'is_active' => true,
        ]);
    }

    #[DataProvider('overlapMatrix')]
    public function test_overlap_matrix(array $first, array $second, bool $overlaps): void
    {
        $this->price($first);

        try {
            $this->price($second);
            $this->assertFalse($overlaps, 'An overlapping range must be refused.');
        } catch (ValidationException $exception) {
            $this->assertTrue($overlaps, 'A non-overlapping range must be accepted.');
            $this->assertArrayHasKey('dimension_min', $exception->errors());
        }
    }

    public static function overlapMatrix(): array
    {
        return [
            'touching dimension bound overlaps' => [['dimension_min' => 100, 'dimension_max' => 150], ['dimension_min' => 150, 'dimension_max' => 200], true],
            'adjacent dimension does not overlap' => [['dimension_min' => 100, 'dimension_max' => 149], ['dimension_min' => 150, 'dimension_max' => 200], false],
            'open-ended date overlaps a later start' => [['valid_from' => '2026-01-01'], ['valid_from' => '2027-06-01'], true],
            'closed period does not overlap the next' => [['valid_from' => '2026-01-01', 'valid_until' => '2026-06-30'], ['valid_from' => '2026-07-01'], false],
            'other currency never overlaps' => [[], ['currency' => 'IDR'], false],
            'other shape never overlaps' => [[], ['shape' => 'Round'], false],
        ];
    }

    public function test_supersede_keeps_history_and_never_edits_a_price(): void
    {
        $service = app(MaterialReferencePriceService::class);
        $v1 = $this->price(['valid_from' => '2026-01-01']);

        $v2 = $service->supersede($v1, ['price_per_kg' => 3.1, 'valid_from' => '2026-07-01'], $this->purchasing);
        $this->assertSame('2026-06-30', $v1->fresh()->valid_until->toDateString());
        $this->assertSame(2, $v2->version_no);
        $this->assertSame($v1->id, $v2->supersedes_id);
        $this->assertSame('3.1000', (string) $v2->price_per_kg);
        $this->assertSame('2.0000', (string) $v1->fresh()->price_per_kg, 'A superseded price is never edited.');

        $this->expectException(ValidationException::class);
        $service->supersede($v2, ['price_per_kg' => 4, 'valid_from' => '2026-07-01'], $this->purchasing);
    }

    public function test_voiding_frees_the_range_and_records_who_and_why(): void
    {
        $service = app(MaterialReferencePriceService::class);
        $price = $this->price();
        $service->void($price, 'Typo in price', $this->purchasing);

        $voided = $price->fresh();
        $this->assertSame('void', $voided->status);
        $this->assertSame('Typo in price', $voided->void_reason);
        $this->assertSame($this->purchasing->id, $voided->voided_by);
        $this->assertNotNull($this->price());
        $this->assertSame(2, MaterialReferencePrice::count(), 'Void never deletes.');
    }

    public function test_resolver_matches_on_the_offered_dimension_and_reports_the_difference(): void
    {
        $this->price(['price_per_kg' => 2.0]);
        [, $quotation] = $this->offer(thickness: 2.5, price: 2.5);

        $result = $this->resolve($quotation);

        $this->assertTrue($result->isMatched());
        $this->assertSame('2.0000', $result->convertedReferencePricePerKg);
        $this->assertSame('0.5000', $result->diffAmountPerKg);
        $this->assertSame('25.0000', $result->diffPercent);
        $this->assertSame('thickness', $result->dimensionField);
    }

    public function test_reference_in_another_currency_is_converted_at_the_evaluation_date(): void
    {
        $this->price(['currency' => 'IDR', 'price_per_kg' => 32000]);
        [, $quotation] = $this->offer(thickness: 2.5, price: 2.5);

        $result = $this->resolve($quotation);

        $this->assertSame('2.0000', $result->convertedReferencePricePerKg);
        $this->assertSame($this->usdRate->id, $result->targetRateId);
    }

    #[DataProvider('reasonCodes')]
    public function test_every_reason_code(string $expected): void
    {
        $thickness = 2.5;
        match ($expected) {
            ReferencePriceResult::OFFERED_DIMENSION_MISSING => $thickness = null,
            ReferencePriceResult::DIMENSION_NOT_COVERED => $this->price(['dimension_min' => 10, 'dimension_max' => 20]),
            ReferencePriceResult::PERIOD_NOT_EFFECTIVE => $this->price(['valid_from' => now()->addMonth()->toDateString()]),
            ReferencePriceResult::EXCHANGE_RATE_UNAVAILABLE => $this->price(['currency' => 'JPY']),
            ReferencePriceResult::AMBIGUOUS_CONFIGURATION => [$this->price(['currency' => 'JPY']), $this->price(['currency' => 'IDR'])],
            default => null,
        };
        [, $quotation] = $this->offer(thickness: $thickness, mapped: $expected !== ReferencePriceResult::MATERIAL_UNMAPPED);

        $result = $this->resolve($quotation);

        $this->assertFalse($result->isMatched());
        $this->assertSame($expected, $result->reasonCode);
        $this->assertNull($result->convertedReferencePricePerKg);
        $this->assertNull($result->diffAmountPerKg);
        $this->assertSame('2.5000', $result->offerPricePerKg, 'Offer data stays visible.');
    }

    public static function reasonCodes(): array
    {
        return array_combine(ReferencePriceResult::REASONS, array_map(fn ($code) => [$code], ReferencePriceResult::REASONS));
    }

    public function test_the_pr_dimension_is_never_used_as_a_fallback(): void
    {
        $this->price(['dimension_min' => 1, 'dimension_max' => 3]);
        [$pr, $quotation] = $this->offer(thickness: null);
        $this->assertSame(2.0, (float) $pr->items[0]->thickness);

        $this->assertSame(ReferencePriceResult::OFFERED_DIMENSION_MISSING, $this->resolve($quotation)->reasonCode);
    }

    public function test_po_generation_writes_one_snapshot_per_award_and_succeeds_when_not_available(): void
    {
        $this->price(['price_per_kg' => 2.0]);
        [$pr, $quotation] = $this->offer(thickness: 2.5, price: 2.5, items: 2);
        $quotation->items[1]->forceFill(['available_thickness' => null])->save();

        $po = $this->generate($pr, $quotation);

        $snapshots = AwardReferencePriceSnapshot::where('purchase_order_id', $po->id)->orderBy('id')->get();
        $this->assertCount(2, $snapshots);
        $this->assertSame(['matched', 'not_available'], $snapshots->pluck('status')->all());
        $this->assertSame('offered_dimension_missing', $snapshots[1]->reason_code);
        $this->assertSame('0.5000', (string) $snapshots[0]->diff_amount_per_kg);
        $this->assertSame(EvaluationContext::current()->dateString(), $snapshots[0]->evaluation_date->toDateString());

        $this->expectException(LogicException::class);
        $snapshots[0]->update(['diff_percent' => 0]);
    }

    public function test_reference_changes_never_touch_existing_snapshots_and_reconcile_is_clean(): void
    {
        $price = $this->price(['price_per_kg' => 2.0]);
        [$pr, $quotation] = $this->offer(thickness: 2.5);
        $po = $this->generate($pr, $quotation);
        $before = AwardReferencePriceSnapshot::where('purchase_order_id', $po->id)->get()->toArray();

        $service = app(MaterialReferencePriceService::class);
        $next = $service->supersede($price, ['price_per_kg' => 9, 'valid_from' => now()->addDay()->toDateString()], $this->purchasing);
        $service->void($next, 'Withdrawn', $this->purchasing);

        $this->assertSame($before, AwardReferencePriceSnapshot::where('purchase_order_id', $po->id)->get()->toArray());
        $this->artisan('procurement:reconcile', ['--json' => true])
            ->expectsOutputToContain('"po_missing_reference_snapshot_after_activation": 0')
            ->assertExitCode(0);
    }

    public function test_a_po_finalized_before_activation_stays_not_captured(): void
    {
        $this->travelTo(now()->subDays(3));
        [$pr, $quotation] = $this->offer(thickness: 2.5);
        $legacy = $this->generate($pr, $quotation);
        // Simulates a PO finalized before the M06B deploy (no snapshot rows).
        DB::table('award_reference_price_snapshots')->where('purchase_order_id', $legacy->id)->delete();
        $this->travelBack();

        $this->price(['price_per_kg' => 2.0]);
        [$pr2, $quotation2] = $this->offer(thickness: 2.5);
        $this->generate($pr2, $quotation2);

        $this->assertSame(0, AwardReferencePriceSnapshot::where('purchase_order_id', $legacy->id)->count());
        $this->actingAs($this->purchasing)->get(route('purchasing.purchase-orders.show', $legacy))
            ->assertOk()
            ->assertSee(__('materials.reference_prices.snapshot.not_captured'));
        $this->artisan('procurement:reconcile', ['--json' => true])
            ->expectsOutputToContain('"po_missing_reference_snapshot_after_activation": 0')
            ->assertExitCode(0);
    }

    public function test_legacy_generation_with_the_write_path_off_still_snapshots_and_guards_first(): void
    {
        config(['procurement.ordered_quantity_write_enabled' => false]);
        $this->price(['price_per_kg' => 2.0]);

        [$pr, $quotation] = $this->offer(thickness: 2.5);
        $po = $this->generate($pr, $quotation);
        $this->assertSame(1, AwardReferencePriceSnapshot::where('purchase_order_id', $po->id)->count());

        [$surplusPr, $surplusQuotation] = $this->offer(thickness: 2.5, availableQty: 15);
        try {
            $this->generate($surplusPr, $surplusQuotation);
            $this->fail('Guard (a) must refuse an unselected surplus while the write path is off.');
        } catch (InvalidArgumentException) {
            // expected
        }
        $this->assertSame(1, AwardReferencePriceSnapshot::count());
    }

    public function test_purchasing_pages_show_reference_and_supplier_is_denied(): void
    {
        $this->price(['price_per_kg' => 2.0]);
        [, $quotation] = $this->offer(thickness: 2.5, price: 2.5);

        $this->actingAs($this->purchasing)->get(route('purchasing.quotations.show', $quotation))
            ->assertOk()->assertSee('data-reference-price', false)->assertSee('(+25', false);
        $this->actingAs($this->supplier)->get(route('supplier.quotations.show', $quotation))
            ->assertOk()->assertDontSee('data-reference-price', false);

        $this->actingAs($this->purchasing)->get(route('purchasing.master-data.reference-prices.index'))->assertOk()->assertSee('M06B-SKD11');
        $this->actingAs($this->supplier)->get(route('purchasing.master-data.reference-prices.index'))->assertForbidden();
        $this->actingAs($this->supplier)->post(route('purchasing.master-data.reference-prices.store'), [])->assertForbidden();
    }

    public function test_http_create_supersede_void_and_notes(): void
    {
        $this->actingAs($this->purchasing)
            ->post(route('purchasing.master-data.reference-prices.store'), [
                'material_code' => 'm06b-skd11', 'shape' => 'Flat', 'dimension_min' => 1, 'dimension_max' => 5,
                'currency' => 'USD', 'price_per_kg' => 2.25, 'valid_from' => '2026-01-01',
            ])
            ->assertSessionHasNoErrors();
        $price = MaterialReferencePrice::sole();

        $this->post(route('purchasing.master-data.reference-prices.supersede', $price), ['price_per_kg' => 2.5, 'valid_from' => '2026-08-01'])->assertSessionHasNoErrors();
        $this->patch(route('purchasing.master-data.reference-prices.notes', $price), ['notes' => 'Quarterly review'])->assertSessionHasNoErrors();
        // A superseded (closed) version is still active and may be voided once.
        $this->post(route('purchasing.master-data.reference-prices.void', $price), ['void_reason' => 'Wrong range'])->assertSessionHasNoErrors();
        $this->post(route('purchasing.master-data.reference-prices.void', $price), ['void_reason' => 'Again'])->assertSessionHasErrors('reference_price');

        $this->assertSame(2, MaterialReferencePrice::count());
        $this->assertSame('Quarterly review', $price->fresh()->notes);
        $this->assertSame('void', $price->fresh()->status);
        $this->assertSame('active', MaterialReferencePrice::where('version_no', 2)->sole()->status);

        $this->post(route('purchasing.master-data.reference-prices.store'), [
            'material_code' => 'UNKNOWN', 'shape' => 'Flat', 'dimension_min' => 1, 'dimension_max' => 5,
            'currency' => 'USD', 'price_per_kg' => 2, 'valid_from' => '2026-01-01',
        ])->assertSessionHasErrors('material_code');
    }

    public function test_fractional_offers_match_the_band_of_their_ceiling_and_keep_the_raw_value(): void
    {
        $lower = $this->price(['dimension_min' => 1, 'dimension_max' => 10, 'price_per_kg' => 2.0]);
        $upper = $this->price(['dimension_min' => 11, 'dimension_max' => 20, 'price_per_kg' => 3.0]);

        foreach (['10.5' => $upper, '10' => $lower, '0.5' => $lower, '20' => $upper] as $thickness => $band) {
            [, $quotation] = $this->offer(thickness: (float) $thickness);
            $result = $this->resolve($quotation);

            $this->assertTrue($result->isMatched(), "{$thickness} must be covered");
            $this->assertSame($band->id, $result->reference->id, "{$thickness} must use the band covering its ceiling");
            $this->assertSame(number_format((float) $thickness, 4, '.', ''), $result->dimensionValue, 'The offered value is never rounded.');
        }

        [, $quotation] = $this->offer(thickness: 20.1);
        $this->assertSame(ReferencePriceResult::DIMENSION_NOT_COVERED, $this->resolve($quotation)->reasonCode);
    }

    public function test_new_bands_require_whole_number_dimensions(): void
    {
        foreach ([['dimension_min' => 1.5], ['dimension_max' => 5.25]] as $overrides) {
            try {
                $this->price($overrides);
                $this->fail('A fractional bound must be refused.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey(array_key_first($overrides), $exception->errors());
            }
        }
        $this->assertSame(5.0, (float) $this->price(['dimension_max' => '5.0000'])->dimension_max, 'A whole value written as a decimal string is accepted.');

        $payload = ['material_code' => 'M06B-SKD11', 'shape' => 'Flat', 'currency' => 'USD', 'price_per_kg' => 2, 'valid_from' => '2026-01-01'];
        $this->actingAs($this->purchasing)
            ->post(route('purchasing.master-data.reference-prices.store'), [...$payload, 'dimension_min' => '1.5', 'dimension_max' => '5.5'])
            ->assertSessionHasErrors(['dimension_min', 'dimension_max']);
        $this->post(route('purchasing.master-data.reference-prices.store'), [...$payload, 'dimension_min' => '0', 'dimension_max' => '5'])
            ->assertSessionHasErrors('dimension_min');
        $this->assertSame(1, MaterialReferencePrice::count());
    }

    public function test_material_code_options_list_active_masters_by_normalized_code(): void
    {
        MaterialMaster::create([
            'material_code' => 'M06B-RETIRED', 'normalized_code' => 'M06B-RETIRED', 'raw_category' => 'Fixture',
            'hs_category' => 'alloy_steel', 'density_profile' => 'steel', 'manufacturer_scope' => 'unknown', 'is_active' => false,
        ]);

        $response = $this->actingAs($this->purchasing)->get(route('purchasing.master-data.reference-prices.index'))->assertOk();

        $options = collect($response->viewData('materialOptions'));
        $this->assertSame([['value' => 'M06B-SKD11', 'label' => 'M06B-SKD11', 'sublabel' => 'Fixture']], $options->all());
        $response->assertSee('id="referencePriceMaterialCode"', false)
            ->assertSee('<option value="M06B-SKD11"', false)
            ->assertDontSee('M06B-RETIRED')
            ->assertSee('step="1"', false);
    }

    private function price(array $overrides = []): MaterialReferencePrice
    {
        return app(MaterialReferencePriceService::class)->create([
            'material_master_id' => $this->material->id, 'shape' => 'Flat', 'dimension_min' => 1, 'dimension_max' => 5,
            'currency' => 'USD', 'price_per_kg' => 2.0, 'valid_from' => now()->subYear()->toDateString(), 'valid_until' => null,
            ...$overrides,
        ], $this->purchasing);
    }

    /**
     * @return array{0: PurchaseRequisition, 1: Quotation}
     */
    private function offer(?float $thickness = 2.5, float $price = 2.5, bool $mapped = true, int $items = 1, ?int $availableQty = null): array
    {
        $pr = $this->createRequisition(array_fill(0, $items, ['quantity' => 10]));
        if ($mapped) {
            $pr->items->each(fn ($item) => $item->forceFill(['material_master_id' => $this->material->id])->save());
        }
        $quotation = $this->createQuotation($pr, array_fill(0, $items, ['price_per_kg' => $price, 'available_qty' => $availableQty ?? 10]));
        $quotation->items->each(fn ($item) => $item->forceFill(['available_thickness' => $thickness, 'available_width' => 100, 'available_length' => 200])->save());

        return [$pr->fresh(['items']), $quotation->fresh(['items.prItem'])];
    }

    private function resolve(Quotation $quotation): ReferencePriceResult
    {
        return app(ReferencePriceResolver::class)->resolve($quotation->items[0], EvaluationContext::current(), 'USD');
    }

    private function generate(PurchaseRequisition $pr, Quotation $quotation): PurchaseOrder
    {
        $selections = $pr->items->values()->mapWithKeys(fn ($item, $i) => [$item->id => $quotation->items[$i]->id])->all();
        $awards = app(PrItemAwardService::class)->awardBatch($pr, $selections, $this->purchasing);

        return app(PurchaseOrderGenerationService::class)->generateFromAwards($awards->pluck('id')->all(), $this->purchasing)->sole();
    }
}
