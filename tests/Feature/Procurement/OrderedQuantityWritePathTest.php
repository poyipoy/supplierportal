<?php

namespace Tests\Feature\Procurement;

use App\Models\PrItemAward;
use App\Models\PrItemAwardEvent;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Services\PrItemAwardService;
use App\Services\PurchaseOrderGenerationService;
use App\Services\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AttachesShipmentMtc;
use Tests\TestCase;

/**
 * Mission 04-W: Purchasing-selected order quantity and specification
 * acceptance, persisted on the award and frozen at PO generation, behind
 * procurement.ordered_quantity_write_enabled (AC-02/03/04/32/43).
 */
class OrderedQuantityWritePathTest extends TestCase
{
    use AttachesShipmentMtc;
    use ProcurementFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        $this->setUpProcurementActors();
        config(['procurement.ordered_quantity_write_enabled' => true, 'procurement.allow_offer_surplus' => true]);
    }

    public function test_award_save_persists_the_selection_with_an_event_and_keeps_pr_and_offer(): void
    {
        [$pr, $quotation] = $this->surplus();

        $this->actingAs($this->purchasing)
            ->post(route('purchasing.comparison.save-awards'), $this->comparisonPayload($pr, $quotation, 'save', 12))
            ->assertRedirect()
            ->assertSessionHas('success');

        $award = PrItemAward::sole();
        $this->assertSame(12, $award->ordered_qty);
        $this->assertNull($award->purchase_order_id);
        $this->assertFalse($award->hasFrozenOrderedValues());
        $this->assertSame(10, $pr->items[0]->fresh()->quantity_value);
        $this->assertSame(15, $quotation->items[0]->fresh()->available_qty);
        $event = PrItemAwardEvent::where('event', PrItemAwardEvent::ORDERED_QTY_SELECTED)->sole();
        // MySQL JSON normalizes key order; compare values, not order.
        $this->assertEquals(
            ['previous' => null, 'ordered_qty' => 12, 'requested_qty' => 10, 'offered_qty' => 15],
            array_intersect_key($event->payload, array_flip(['previous', 'ordered_qty', 'requested_qty', 'offered_qty']))
        );
        $this->assertSame($this->purchasing->id, $event->actor_id);
    }

    public function test_generation_freezes_ordered_quantity_kg_and_amount_and_never_orders_the_remainder(): void
    {
        [$pr, $quotation] = $this->surplus();

        $this->actingAs($this->purchasing)
            ->post(route('purchasing.comparison.save-awards'), $this->comparisonPayload($pr, $quotation, 'generate_pos', 12))
            ->assertRedirect();

        $po = PurchaseOrder::sole();
        $award = PrItemAward::sole();
        $this->assertSame($po->id, $award->purchase_order_id);
        $this->assertSame(12, $award->ordered_qty);
        $this->assertSame('3.2000', $award->ordered_weight_per_unit);
        $this->assertSame('38.4000', $award->ordered_total_weight_kg);
        $this->assertSame('2.5000', $award->ordered_price_per_kg);
        $this->assertSame('96.0000', $award->ordered_amount);

        $line = $po->fresh()->orderedLines()->sole();
        $this->assertSame('frozen', $line->source);
        $this->assertSame(12, $line->qty);
        $this->assertSame(1, PrItemAwardEvent::where('event', PrItemAwardEvent::ORDERED_VALUES_FROZEN)->where('purchase_order_id', $po->id)->count());
        $this->assertSame(15, $quotation->items[0]->fresh()->available_qty);
    }

    public function test_shipments_7_and_5_fit_the_ordered_12_and_one_more_is_refused(): void
    {
        [$pr, $quotation] = $this->surplus();
        $this->actingAs($this->purchasing)
            ->post(route('purchasing.comparison.save-awards'), $this->comparisonPayload($pr, $quotation, 'generate_pos', 12));
        $po = PurchaseOrder::sole();
        $itemId = $quotation->items[0]->id;
        $shipments = app(ShipmentService::class);
        $ship = fn (int $qty) => $this->submitWithMtc($shipments->createDraft($this->supplier), ['items' => [[
            'purchase_order_id' => $po->id, 'quotation_item_id' => $itemId, 'shipped_qty' => $qty, 'actual_weight_kg' => $qty * 3.2,
        ]]]);

        $ship(7);
        $ship(5);
        try {
            $ship(1);
            $this->fail('A shipment beyond the ordered quantity must be refused.');
        } catch (ValidationException|InvalidArgumentException) {
            // expected
        }
        $this->assertSame(12, (int) $po->fresh()->total_ordered_quantity);
    }

    #[DataProvider('invalidQuantities')]
    public function test_every_entry_point_rejects_an_invalid_or_missing_order_quantity(string $path, mixed $qty): void
    {
        [$pr, $quotation] = $this->surplus();
        $prItemId = $pr->items[0]->id;

        $response = match ($path) {
            'comparison' => $this->actingAs($this->purchasing)->post(route('purchasing.comparison.save-awards'), $this->comparisonPayload($pr, $quotation, 'generate_pos', $qty)),
            'direct' => $this->actingAs($this->purchasing)->post(route('purchasing.quotations.generate-po', $quotation), array_filter([
                'estimated_arrival' => now()->addDays(14)->toDateString(),
                'order_qty' => $qty === 'missing' ? null : [$prItemId => $qty],
            ], fn ($value) => $value !== null)),
            'consolidation' => $this->consolidate($pr, $quotation, $qty),
        };

        $response->assertSessionHasErrors("order_qty.{$prItemId}");
        $this->assertDatabaseCount('purchase_orders', 0);
        $this->assertSame(Quotation::STATUS_SUBMITTED, $quotation->fresh()->status);
    }

    public static function invalidQuantities(): array
    {
        $cases = [];
        foreach (['comparison', 'direct', 'consolidation'] as $path) {
            foreach (['zero' => 0, 'above offer' => 16, 'missing' => 'missing', 'not integer' => '2.5'] as $label => $qty) {
                $cases["{$path} {$label}"] = [$path, $qty];
            }
        }

        return $cases;
    }

    public function test_every_entry_point_generates_with_a_valid_order_quantity(): void
    {
        foreach (['comparison', 'direct', 'consolidation'] as $path) {
            [$pr, $quotation] = $this->surplus();
            $prItemId = $pr->items[0]->id;
            $response = match ($path) {
                'comparison' => $this->actingAs($this->purchasing)->post(route('purchasing.comparison.save-awards'), $this->comparisonPayload($pr, $quotation, 'generate_pos', 15)),
                'direct' => $this->actingAs($this->purchasing)->post(route('purchasing.quotations.generate-po', $quotation), [
                    'estimated_arrival' => now()->addDays(14)->toDateString(),
                    'order_qty' => [$prItemId => 15],
                ]),
                'consolidation' => $this->consolidate($pr, $quotation, 15),
            };

            $response->assertSessionHasNoErrors();
            $award = PrItemAward::where('pr_item_id', $prItemId)->sole();
            $this->assertNotNull($award->purchase_order_id, $path);
            $this->assertSame(15, $award->ordered_qty, $path);
            $this->assertSame('48.0000', $award->ordered_total_weight_kg, $path);
        }
    }

    public function test_a_different_specification_needs_a_current_acceptance(): void
    {
        [$pr, $quotation] = $this->surplus(thickness: 2.5);
        $prItemId = $pr->items[0]->id;
        $payload = $this->comparisonPayload($pr, $quotation, 'generate_pos', 10);

        $this->actingAs($this->purchasing)
            ->post(route('purchasing.comparison.save-awards'), $payload)
            ->assertSessionHasErrors("accept_spec.{$prItemId}");
        $this->assertDatabaseCount('purchase_orders', 0);

        // Accept and save without generating: snapshot + hash + event.
        $this->actingAs($this->purchasing)
            ->post(route('purchasing.comparison.save-awards'), [...$payload, 'action' => 'save', 'accept_spec' => [$prItemId => 1]])
            ->assertSessionHas('success');
        $award = PrItemAward::sole();
        $this->assertNotNull($award->spec_accepted_at);
        $this->assertSame($this->purchasing->id, $award->spec_accepted_by);
        $this->assertSame(64, strlen($award->spec_acceptance_hash));
        $this->assertSame(2.5, (float) $award->spec_acceptance_snapshot['offered']['thickness']);
        $this->assertSame(1, PrItemAwardEvent::where('event', PrItemAwardEvent::SPEC_ACCEPTED)->count());

        // The offer changes afterwards: the stored acceptance is stale and blocks.
        $quotation->items[0]->forceFill(['available_thickness' => 2.6])->save();
        try {
            app(PurchaseOrderGenerationService::class)->generateFromAwards([$award->id], $this->purchasing);
            $this->fail('A stale acceptance must block generation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey("accept_spec.{$prItemId}", $exception->errors());
        }
        $this->assertDatabaseCount('purchase_orders', 0);

        // A fresh acceptance unblocks it.
        $this->actingAs($this->purchasing)
            ->post(route('purchasing.comparison.save-awards'), [...$payload, 'accept_spec' => [$prItemId => 1]])
            ->assertSessionHasNoErrors();
        $this->assertNotNull($award->fresh()->purchase_order_id);
    }

    public function test_unchecking_an_acceptance_revokes_it_with_an_event(): void
    {
        [$pr, $quotation] = $this->surplus(thickness: 2.5);
        $prItemId = $pr->items[0]->id;
        $payload = [...$this->comparisonPayload($pr, $quotation, 'save', 10), 'accept_spec' => [$prItemId => 1]];
        $this->actingAs($this->purchasing)->post(route('purchasing.comparison.save-awards'), $payload);
        $this->actingAs($this->purchasing)->post(route('purchasing.comparison.save-awards'), [...$payload, 'accept_spec' => [$prItemId => 0]]);

        $award = PrItemAward::sole();
        $this->assertNull($award->spec_accepted_at);
        $this->assertNull($award->spec_acceptance_hash);
        $this->assertSame(1, PrItemAwardEvent::where('event', PrItemAwardEvent::SPEC_ACCEPTANCE_REVOKED)->count());
    }

    public function test_selection_inputs_are_ignored_while_the_write_path_is_off(): void
    {
        config(['procurement.ordered_quantity_write_enabled' => false, 'procurement.allow_offer_surplus' => false]);
        [$pr, $quotation] = $this->requisitionWithOffer(10, 10);

        $this->actingAs($this->purchasing)
            ->post(route('purchasing.comparison.save-awards'), [...$this->comparisonPayload($pr, $quotation, 'save', 7), 'accept_spec' => [$pr->items[0]->id => 1]])
            ->assertSessionHas('success');

        $award = PrItemAward::sole();
        $this->assertNull($award->ordered_qty);
        $this->assertNull($award->spec_accepted_at);
        $this->assertSame(0, PrItemAwardEvent::count());
    }

    public function test_selected_but_unassigned_award_survives_a_configuration_rollback_intact(): void
    {
        // AC-43 (I-21) with the flag toggled in-process.
        [$pr, $quotation] = $this->surplus(thickness: 2.5);
        $prItemId = $pr->items[0]->id;
        $this->actingAs($this->purchasing)->post(route('purchasing.comparison.save-awards'), [
            ...$this->comparisonPayload($pr, $quotation, 'save', 12), 'accept_spec' => [$prItemId => 1],
        ])->assertSessionHas('success');
        $award = PrItemAward::sole();
        $before = $award->fresh()->getAttributes();

        config(['procurement.ordered_quantity_write_enabled' => false, 'procurement.allow_offer_surplus' => false]);
        foreach ([
            fn () => $this->actingAs($this->purchasing)->post(route('purchasing.comparison.save-awards'), $this->comparisonPayload($pr, $quotation, 'generate_pos', null)),
            fn () => $this->actingAs($this->purchasing)->post(route('purchasing.quotations.generate-po', $quotation), ['estimated_arrival' => now()->addDays(14)->toDateString()]),
            fn () => $this->consolidate($pr, $quotation, null, existing: $award),
        ] as $attempt) {
            $attempt();
            $this->assertDatabaseCount('purchase_orders', 0);
            $this->assertDatabaseCount('po_quotations', 0);
            $this->assertDatabaseCount('po_documents', 0);
            $this->assertSame($before, $award->fresh()->getAttributes());
            $this->assertSame(Quotation::STATUS_SUBMITTED, $quotation->fresh()->status);
        }

        $this->artisan('procurement:reconcile', ['--json' => true])->assertExitCode(0);

        config(['procurement.ordered_quantity_write_enabled' => true, 'procurement.allow_offer_surplus' => true]);
        app(PurchaseOrderGenerationService::class)->generateFromAwards([$award->id], $this->purchasing);
        $award->refresh();
        $this->assertSame(12, $award->ordered_qty);
        $this->assertSame('38.4000', $award->ordered_total_weight_kg);
        $this->assertSame('96.0000', $award->ordered_amount);
    }

    public function test_kill_switch_still_stops_generation_with_the_write_path_on(): void
    {
        [$pr, $quotation] = $this->surplus();
        config(['procurement.po_generation_enabled' => false]);

        $this->actingAs($this->purchasing)
            ->post(route('purchasing.comparison.save-awards'), $this->comparisonPayload($pr, $quotation, 'generate_pos', 12))
            ->assertSessionHas('error', __('procurement.po_generation.disabled'));

        $this->assertDatabaseCount('purchase_orders', 0);
        $this->assertDatabaseCount('pr_item_awards', 0);
    }

    public function test_order_quantity_input_is_prefilled_with_min_of_requested_and_offered_only_when_enabled(): void
    {
        [$pr, $quotation] = $this->surplus();
        $prItemId = $pr->items[0]->id;
        app(PrItemAwardService::class)->awardBatch($pr, [$prItemId => $quotation->items[0]->id], $this->purchasing);
        $url = route('purchasing.comparison.inter-supplier', ['pr_id' => $pr]);

        $html = $this->actingAs($this->purchasing)->get($url)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/name="order_qty\['.$prItemId.'\]"[^>]*value="10"/', $html);
        $this->assertMatchesRegularExpression('/name="order_qty\['.$prItemId.'\]"[^>]*max="15"/', $html);

        config(['procurement.ordered_quantity_write_enabled' => false, 'procurement.allow_offer_surplus' => false]);
        $this->actingAs($this->purchasing)->get($url)->assertOk()->assertDontSee('name="order_qty[', false);
    }

    /**
     * PR 10, offer 15, 3.2 kg/unit, 2.5 per kg.
     *
     * @return array{0: PurchaseRequisition, 1: Quotation}
     */
    private function surplus(?float $thickness = null): array
    {
        return $this->requisitionWithOffer(10, 15, $thickness);
    }

    /**
     * @return array{0: PurchaseRequisition, 1: Quotation}
     */
    private function requisitionWithOffer(int $requested, int $offered, ?float $thickness = null): array
    {
        $pr = $this->createRequisition([['quantity' => $requested, 'weight_needed' => 3]]);
        $quotation = $this->createQuotation($pr, [['available_qty' => $offered, 'offered_weight_per_unit' => 3.2]]);
        if ($thickness !== null) {
            $quotation->items[0]->forceFill(['available_thickness' => $thickness, 'available_width' => 100, 'available_length' => 200])->save();
        }

        return [$pr, $quotation->fresh(['items'])];
    }

    private function comparisonPayload(PurchaseRequisition $pr, Quotation $quotation, string $action, mixed $qty): array
    {
        $payload = [
            'pr_id' => $pr->getRouteKey(),
            'awards' => [$pr->items[0]->id => $quotation->items[0]->id],
            'action' => $action,
            'estimated_arrival' => now()->addDays(14)->toDateString(),
        ];
        if ($qty !== null && $qty !== 'missing') {
            $payload['order_qty'] = [$pr->items[0]->id => $qty];
        }

        return $payload;
    }

    private function consolidate(PurchaseRequisition $pr, Quotation $quotation, mixed $qty, ?PrItemAward $existing = null)
    {
        $award = $existing ?? app(PrItemAwardService::class)->awardBatch($pr, [$pr->items[0]->id => $quotation->items[0]->id], $this->purchasing)->sole();
        $payload = [
            'award_ids' => [$award->id],
            'estimated_arrival' => now()->addDays(14)->toDateString(),
        ];
        if ($qty !== null && $qty !== 'missing') {
            $payload['order_qty'] = [$pr->items[0]->id => $qty];
        }

        return $this->actingAs($this->purchasing)->post(route('purchasing.purchase-orders.consolidate-awards.store'), $payload);
    }
}
