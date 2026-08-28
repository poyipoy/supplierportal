<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\MaterialMaster;
use App\Models\Period;
use App\Models\PrItem;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class QuotationAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasing;

    private User $supplier;

    private User $otherSupplier;

    private int $requisitionSequence = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $this->supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $this->otherSupplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
    }

    public function test_availability_columns_exist_and_supplier_availability_is_sanitized_by_shape(): void
    {
        $this->assertTrue(Schema::hasColumns('quotation_items', [
            'available_qty',
            'available_thickness',
            'available_d_inner',
            'available_d_outer',
            'available_width',
            'available_length',
            'is_available',
            'available_length_min',
            'available_length_max',
            'offered_weight_per_unit',
            'offered_weight_source',
        ]));

        $pr = $this->createRequisition([
            [
                'shape' => PrItem::SHAPE_FLAT,
                'quantity' => 2,
                'thickness' => 1.2,
                'width' => 120,
                'length' => 240,
                'weight_needed' => 10,
            ],
            [
                'shape' => PrItem::SHAPE_ROUND,
                'quantity' => 3,
                'd_outer' => 20,
                'length' => 300,
                'weight_needed' => 5,
            ],
            [
                'shape' => PrItem::SHAPE_HOLLOW,
                'quantity' => 4,
                'd_inner' => 10,
                'd_outer' => 20,
                'length' => 400,
                'weight_needed' => 2,
            ],
        ]);

        $payload = $this->quotationPayload($pr);
        $payload['items'][0] = array_merge($payload['items'][0], [
            'available_qty' => 2,
            'available_thickness' => 1.3,
            'available_width' => 125,
            'available_length' => 245,
            'available_d_inner' => 999,
            'available_d_outer' => 999,
        ]);
        $payload['items'][1] = array_merge($payload['items'][1], [
            'available_qty' => 3,
            'available_thickness' => 999,
            'available_d_inner' => 999,
            'available_d_outer' => 21,
            'available_width' => 999,
            'available_length' => 305,
        ]);
        $payload['items'][2] = array_merge($payload['items'][2], [
            'available_qty' => 3,
            'available_thickness' => 999,
            'available_d_inner' => 11,
            'available_d_outer' => 21,
            'available_width' => 999,
            'available_length' => 405,
        ]);

        $this->actingAs($this->supplier)
            ->post(route('supplier.quotations.store', $pr), $payload)
            ->assertRedirect(route('supplier.quotations.period', $pr->period_id));

        $quotation = Quotation::where('pr_id', $pr->id)->where('supplier_id', $this->supplier->id)->firstOrFail();
        $items = $quotation->items()->with('prItem')->get()->keyBy('pr_item_id');

        $flat = $items->get($pr->items[0]->id);
        $this->assertSame(2, $flat->available_qty);
        $this->assertSame('1.3000', $flat->available_thickness);
        $this->assertSame('125.0000', $flat->available_width);
        $this->assertSame('245.0000', $flat->available_length);
        $this->assertNull($flat->available_d_inner);
        $this->assertNull($flat->available_d_outer);

        $round = $items->get($pr->items[1]->id);
        $this->assertSame('21.0000', $round->available_d_outer);
        $this->assertSame('305.0000', $round->available_length);
        $this->assertNull($round->available_thickness);
        $this->assertNull($round->available_d_inner);
        $this->assertNull($round->available_width);

        $hollow = $items->get($pr->items[2]->id);
        $this->assertSame('11.0000', $hollow->available_d_inner);
        $this->assertSame('21.0000', $hollow->available_d_outer);
        $this->assertSame('405.0000', $hollow->available_length);
        $this->assertNull($hollow->available_thickness);
        $this->assertNull($hollow->available_width);

        // Amount always uses the requested total weight: 2 × 10 kg × 2.5.
        $this->assertSame('50.0000', $flat->amount);
    }

    public function test_availability_is_optional_and_legacy_quotation_remains_viewable(): void
    {
        $pr = $this->createRequisition();

        $this->actingAs($this->supplier)
            ->post(route('supplier.quotations.store', $pr), $this->quotationPayload($pr))
            ->assertRedirect();

        $quotation = Quotation::where('pr_id', $pr->id)->where('supplier_id', $this->supplier->id)->firstOrFail();
        $item = $quotation->items()->with('prItem')->firstOrFail();

        $this->assertNull($item->available_qty);
        $this->assertSame('Not Specified', $item->availability_comparison['specification']['label']);

        $this->actingAs($this->supplier)
            ->get(route('supplier.quotations.create', $pr))
            ->assertOk()
            ->assertSee('Supplier Availability')
            ->assertSee('Supplier Offer')
            ->assertSee('Commercial')
            ->assertSee('Supporting')
            ->assertSee('quotation-sticky-number', false)
            ->assertSee('quotation-sticky-material', false)
            ->assertSee('border-right: 1px solid var(--md-outline) !important', false)
            ->assertSee('border-collapse: separate !important', false)
            ->assertSee('min-width: 1957px !important', false)
            ->assertSee('quotation-calculated', false)
            ->assertSee('mtc-file-input', false);

        $this->actingAs($this->supplier)
            ->get(route('supplier.quotations.show', $quotation))
            ->assertOk()
            ->assertSee('Not specified');

        $this->actingAs($this->purchasing)
            ->get(route('purchasing.quotations.show', $quotation))
            ->assertOk()
            ->assertSee('Requested vs Offered')
            ->assertSee('Not Specified');
    }

    public function test_amount_uses_fresh_manual_weight_and_quantity_for_draft_and_revision(): void
    {
        $pr = $this->createRequisition([[
            'quantity' => 3,
            'weight_needed' => 4.125,
            'weight_calculation_status' => PrItem::WEIGHT_STATUS_MANUAL,
        ]]);

        $payload = $this->quotationPayload($pr);
        $payload['items'][0]['price_per_kg'] = 2.25;

        $this->actingAs($this->supplier)
            ->post(route('supplier.quotations.store', $pr), $payload)
            ->assertRedirect();

        $quotationItem = Quotation::where('pr_id', $pr->id)
            ->where('supplier_id', $this->supplier->id)
            ->firstOrFail()
            ->items()
            ->firstOrFail();

        $this->assertSame('27.8438', $quotationItem->amount);

        // Simulate a PR quantity/weight update after the draft was first saved.
        // The quotation controller must not use a cached relation for the next save.
        $prItem = $pr->items->firstOrFail();
        $prItem->update([
            'quantity' => 4,
            'weight_needed' => 5.5,
        ]);
        $payload['items'][0]['price_per_kg'] = 3;

        $this->actingAs($this->supplier)
            ->post(route('supplier.quotations.store', $pr), $payload)
            ->assertRedirect();

        $this->assertSame(
            '66.0000',
            Quotation::where('pr_id', $pr->id)
                ->where('supplier_id', $this->supplier->id)
                ->firstOrFail()
                ->items()
                ->firstOrFail()
                ->amount,
        );
    }

    public function test_submitted_amount_is_server_authoritative_and_idr_snapshot_is_available(): void
    {
        ExchangeRate::create([
            'currency' => 'USD',
            'rate_to_idr' => 16000,
            'valid_from' => now()->subDay(),
            'created_by' => $this->purchasing->id,
        ]);

        $pr = $this->createRequisition([[
            'quantity' => 2,
            'weight_needed' => 7.5,
            'weight_calculation_status' => PrItem::WEIGHT_STATUS_MANUAL,
        ]]);
        $payload = $this->quotationPayload($pr);
        $payload['action'] = 'submitted';
        $payload['items'][0]['price_per_kg'] = 4.2;

        $this->actingAs($this->supplier)
            ->post(route('supplier.quotations.store', $pr), $payload)
            ->assertRedirect();

        $quotation = Quotation::where('pr_id', $pr->id)
            ->where('supplier_id', $this->supplier->id)
            ->firstOrFail();
        $item = $quotation->items()->firstOrFail();

        $this->assertSame('63.0000', $item->amount);
        $this->assertNotNull($quotation->submitted_at);
        $this->assertNotNull($quotation->exchange_rate_id);
        $this->assertSame(1008000.0, round((float) $item->amount * 16000, 1));
    }

    public function test_quotation_detail_recovers_an_eligible_legacy_zero_amount_for_display(): void
    {
        $pr = $this->createRequisition();
        $quotation = $this->createDraftQuotation($pr, $this->supplier, 2.5);
        $item = $quotation->items()->firstOrFail();
        $item->update(['amount' => 0]);

        $this->actingAs($this->supplier)
            ->get(route('supplier.quotations.show', $quotation))
            ->assertOk()
            ->assertSee('50.00');

        $this->actingAs($this->purchasing)
            ->get(route('purchasing.quotations.show', $quotation))
            ->assertOk()
            ->assertSee('50.00');
    }

    public function test_zero_amount_repair_command_is_dry_run_until_explicitly_enabled(): void
    {
        $pr = $this->createRequisition([
            ['weight_needed' => 10],
            ['weight_needed' => 0],
        ]);
        $quotation = $this->createDraftQuotation($pr, $this->supplier, 2.5);
        $quotation->items()->update(['amount' => 0]);

        $this->artisan('quotations:repair-zero-amounts')
            ->assertExitCode(0);

        $this->assertSame(['0.0000', '0.0000'], $quotation->items()->orderBy('id')->pluck('amount')->all());

        $this->artisan('quotations:repair-zero-amounts', ['--execute' => true])
            ->assertExitCode(0);

        $amounts = $quotation->items()->orderBy('id')->pluck('amount')->all();
        $this->assertSame('50.0000', $amounts[0]);
        $this->assertSame('0.0000', $amounts[1]);
    }

    public function test_zero_amount_repair_command_does_not_rewrite_offer_rows(): void
    {
        $pr = $this->createRequisition();
        $quotation = $this->createDraftQuotation($pr, $this->supplier, 2.5);
        $quotationItem = $quotation->items()->firstOrFail();
        $quotationItem->update([
            'amount' => 0,
            'is_available' => true,
            'available_qty' => 1,
            'offered_weight_per_unit' => 2.4,
            'offered_weight_source' => 'estimated',
        ]);

        $this->artisan('quotations:repair-zero-amounts', ['--execute' => true])
            ->assertExitCode(0);

        $this->assertSame('0.0000', $quotationItem->fresh()->amount);
    }

    public function test_supplier_cannot_submit_items_from_another_pr_or_replace_existing_items(): void
    {
        $pr = $this->createRequisition();
        $otherPr = $this->createRequisition();
        $quotation = $this->createDraftQuotation($pr, $this->supplier, 7.5);
        $existingItem = $quotation->items()->firstOrFail();

        $payload = $this->quotationPayload($pr);
        $payload['items'][0]['pr_item_id'] = $otherPr->items->first()->id;
        $payload['items'][0]['price_per_kg'] = 99;

        $this->actingAs($this->supplier)
            ->from(route('supplier.quotations.create', $pr))
            ->post(route('supplier.quotations.store', $pr), $payload)
            ->assertRedirect(route('supplier.quotations.create', $pr))
            ->assertSessionHasErrors('items.0.pr_item_id');

        $this->assertSame('7.5000', $existingItem->fresh()->price_per_kg);
        $this->assertCount(1, $quotation->fresh()->items);
    }

    public function test_duplicate_or_incomplete_item_sets_are_rejected_before_the_quotation_is_changed(): void
    {
        $pr = $this->createRequisition([[], []]);
        $quotation = $this->createDraftQuotation($pr, $this->supplier, 3.5);
        $firstItemId = $pr->items[0]->id;

        $duplicatePayload = $this->quotationPayload($pr);
        $duplicatePayload['items'][1]['pr_item_id'] = $firstItemId;

        $this->actingAs($this->supplier)
            ->from(route('supplier.quotations.create', $pr))
            ->post(route('supplier.quotations.store', $pr), $duplicatePayload)
            ->assertRedirect(route('supplier.quotations.create', $pr))
            ->assertSessionHasErrors('items.1.pr_item_id');

        $incompletePayload = $this->quotationPayload($pr);
        array_pop($incompletePayload['items']);

        $this->actingAs($this->supplier)
            ->from(route('supplier.quotations.create', $pr))
            ->post(route('supplier.quotations.store', $pr), $incompletePayload)
            ->assertRedirect(route('supplier.quotations.create', $pr))
            ->assertSessionHasErrors('items');

        $this->assertCount(2, $quotation->fresh()->items);
    }

    public function test_supplier_submission_does_not_modify_another_suppliers_quotation(): void
    {
        $pr = $this->createRequisition();
        $pr->invitedSuppliers()->sync([$this->supplier->id, $this->otherSupplier->id]);
        $quotation = $this->createDraftQuotation($pr, $this->supplier, 4.25);

        $payload = $this->quotationPayload($pr);
        $payload['items'][0]['available_qty'] = 2;

        $this->actingAs($this->otherSupplier)
            ->post(route('supplier.quotations.store', $pr), $payload)
            ->assertRedirect();

        $this->assertSame('4.2500', $quotation->items()->firstOrFail()->fresh()->price_per_kg);
        $this->assertDatabaseHas('quotations', [
            'pr_id' => $pr->id,
            'supplier_id' => $this->otherSupplier->id,
        ]);
    }

    public function test_purchasing_comparison_reports_independent_quantity_and_specification_statuses(): void
    {
        $pr = $this->createRequisition([[
            'shape' => PrItem::SHAPE_FLAT,
            'quantity' => 5,
            'thickness' => 1,
            'width' => 100,
            'length' => 200,
        ]]);
        $quotation = $this->createDraftQuotation($pr, $this->supplier, 2.5);
        $item = $quotation->items()->firstOrFail();

        $item->update([
            'available_qty' => 3,
            'available_thickness' => 1.2,
            'available_width' => 100,
            'available_length' => 200,
        ]);
        $comparison = $item->fresh()->load('prItem')->availability_comparison;

        $this->assertSame('shortage', $comparison['quantity']['code']);
        $this->assertSame('different', $comparison['specification']['code']);

        $item->update([
            'available_qty' => 5,
            'available_thickness' => 1.0001,
            'available_width' => 100,
            'available_length' => 200,
        ]);
        $comparison = $item->fresh()->load('prItem')->availability_comparison;

        $this->assertSame('match', $comparison['quantity']['code']);
        $this->assertSame('exact', $comparison['specification']['code']);
    }

    public function test_explicit_available_offer_uses_auto_weight_and_offer_amount(): void
    {
        $material = MaterialMaster::create([
            'material_code' => 'OFFER-AUTO-'.uniqid(),
            'normalized_code' => 'OFFER-AUTO-'.uniqid(),
            'density_profile' => MaterialMaster::DENSITY_STEEL,
            'is_active' => true,
        ]);
        $pr = $this->createRequisition([[
            'quantity' => 2,
            'thickness' => 1,
            'width' => 100,
            'length' => 200,
            'material_master_id' => $material->id,
            'weight_needed' => 10,
        ]]);
        $item = $pr->items->firstOrFail();
        $payload = $this->quotationPayload($pr);
        $payload['items'][0] = [
            'pr_item_id' => $item->id,
            'is_available' => true,
            'available_qty' => 1,
            'available_thickness' => 1,
            'available_width' => 100,
            'available_length_input' => '200',
            'price_per_kg' => 100,
            'notes' => 'Offer note',
        ];

        $this->actingAs($this->supplier)
            ->post(route('supplier.quotations.store', $pr), $payload)
            ->assertRedirect();

        $quotationItem = Quotation::where('pr_id', $pr->id)
            ->where('supplier_id', $this->supplier->id)
            ->firstOrFail()
            ->items()
            ->firstOrFail();

        $this->assertTrue($quotationItem->is_available);
        $this->assertSame('auto', $quotationItem->offered_weight_source);
        $this->assertSame('0.1570', $quotationItem->offered_weight_per_unit);
        $this->assertSame('0.1570', $quotationItem->offered_total_weight === null
            ? null
            : number_format($quotationItem->offered_total_weight, 4, '.', ''));
        $this->assertSame('15.7000', $quotationItem->amount);
        $this->assertSame(2000.0, $quotationItem->requested_amount);
    }

    public function test_range_length_requires_estimated_weight_and_persists_offer_amount(): void
    {
        $pr = $this->createRequisition([[
            'quantity' => 2,
            'length' => 2400,
            'weight_needed' => 10,
        ]]);
        $item = $pr->items->firstOrFail();
        $payload = $this->quotationPayload($pr);
        $payload['items'][0] = [
            'pr_item_id' => $item->id,
            'is_available' => true,
            'available_qty' => 2,
            'available_thickness' => 1,
            'available_width' => 100,
            'available_length_input' => '2300 - 2500',
            'offered_weight_per_unit' => 2.4,
            'price_per_kg' => 100,
            'notes' => 'Range offer',
        ];

        $this->actingAs($this->supplier)
            ->post(route('supplier.quotations.store', $pr), $payload)
            ->assertRedirect();

        $quotationItem = Quotation::where('pr_id', $pr->id)
            ->where('supplier_id', $this->supplier->id)
            ->firstOrFail()
            ->items()
            ->with('prItem')
            ->firstOrFail();

        $this->assertNull($quotationItem->available_length);
        $this->assertSame('2300.0000', $quotationItem->available_length_min);
        $this->assertSame('2500.0000', $quotationItem->available_length_max);
        $this->assertSame('estimated', $quotationItem->offered_weight_source);
        $this->assertSame('480.0000', $quotationItem->amount);
        $this->assertSame('within_range', $quotationItem->availability_comparison['specification']['code']);
    }

    public function test_submitted_available_offer_requires_authoritative_weight(): void
    {
        ExchangeRate::create([
            'currency' => 'USD',
            'rate_to_idr' => 16000,
            'valid_from' => now()->subMinute(),
            'created_by' => $this->purchasing->id,
        ]);
        $pr = $this->createRequisition([['quantity' => 2]]);
        $payload = $this->quotationPayload($pr);
        $payload['action'] = 'submitted';
        $payload['items'][0] = [
            'pr_item_id' => $pr->items->firstOrFail()->id,
            'is_available' => true,
            'available_qty' => 2,
            'available_thickness' => 1,
            'available_width' => 100,
            'available_length_input' => '200',
            'price_per_kg' => 100,
        ];

        $this->actingAs($this->supplier)
            ->from(route('supplier.quotations.create', $pr))
            ->post(route('supplier.quotations.store', $pr), $payload)
            ->assertRedirect(route('supplier.quotations.create', $pr))
            ->assertSessionHasErrors('items.0.offered_weight_per_unit');

        $this->assertDatabaseCount('quotations', 0);
    }

    public function test_manual_offer_weight_is_persisted_as_estimated(): void
    {
        $pr = $this->createRequisition([['quantity' => 2]]);
        $payload = $this->quotationPayload($pr);
        $payload['items'][0] = [
            'pr_item_id' => $pr->items->firstOrFail()->id,
            'is_available' => true,
            'available_qty' => 1,
            'offered_weight_per_unit' => 1.2345,
            'offered_weight_manual_override' => true,
            'price_per_kg' => 100,
        ];

        $this->actingAs($this->supplier)
            ->post(route('supplier.quotations.store', $pr), $payload)
            ->assertRedirect();

        $item = Quotation::where('pr_id', $pr->id)
            ->where('supplier_id', $this->supplier->id)
            ->firstOrFail()
            ->items()
            ->firstOrFail();

        $this->assertSame('estimated', $item->offered_weight_source);
        $this->assertSame('1.2345', $item->offered_weight_per_unit);
        $this->assertSame('123.4500', $item->amount);
    }

    public function test_explicit_available_quantity_above_requested_is_rejected_without_writes(): void
    {
        $pr = $this->createRequisition([['quantity' => 2]]);
        $payload = $this->quotationPayload($pr);
        $payload['items'][0] = array_merge($payload['items'][0], [
            'is_available' => true,
            'available_qty' => 3,
            'offered_weight_per_unit' => 2,
        ]);

        $this->actingAs($this->supplier)
            ->from(route('supplier.quotations.create', $pr))
            ->post(route('supplier.quotations.store', $pr), $payload)
            ->assertRedirect(route('supplier.quotations.create', $pr))
            ->assertSessionHasErrors('items.0.available_qty');

        $this->assertDatabaseCount('quotations', 0);
    }

    public function test_not_available_clears_offer_fields_and_can_be_submitted(): void
    {
        ExchangeRate::create([
            'currency' => 'USD',
            'rate_to_idr' => 16000,
            'valid_from' => now()->subMinute(),
            'created_by' => $this->purchasing->id,
        ]);
        $pr = $this->createRequisition([['quantity' => 2]]);
        $payload = $this->quotationPayload($pr);
        $payload['action'] = 'submitted';
        $payload['items'][0] = [
            'pr_item_id' => $pr->items->firstOrFail()->id,
            'is_available' => false,
            'available_qty' => 99,
            'available_length_input' => 'bad-range',
            'offered_weight_per_unit' => 99,
            'price_per_kg' => 99,
            'notes' => 'Not available note',
        ];

        $this->actingAs($this->supplier)
            ->post(route('supplier.quotations.store', $pr), $payload)
            ->assertRedirect();

        $quotation = Quotation::where('pr_id', $pr->id)->where('supplier_id', $this->supplier->id)->firstOrFail();
        $item = $quotation->items()->firstOrFail();
        $this->assertSame('submitted', $quotation->status);
        $this->assertFalse($item->is_available);
        $this->assertNull($item->price_per_kg);
        $this->assertSame('0.0000', $item->amount);
        $this->assertNull($item->available_qty);
        $this->assertNull($item->offered_weight_per_unit);
        $this->assertSame('Not available note', $item->notes);
        $this->assertSame('not_available', $item->availability_comparison['specification']['code']);

        $this->actingAs($this->supplier)
            ->get(route('supplier.quotations.show', $quotation))
            ->assertOk()
            ->assertSee('Not Available');

        $this->actingAs($this->purchasing)
            ->get(route('purchasing.quotations.show', $quotation))
            ->assertOk()
            ->assertSee('Not Available');
    }

    private function createRequisition(array $items = [[]]): PurchaseRequisition
    {
        $period = Period::create([
            'name' => 'Availability Test '.$this->requisitionSequence,
            'month' => 8,
            'year' => 2026,
            'status' => 'open',
            'created_by' => $this->purchasing->id,
        ]);
        $pr = PurchaseRequisition::create([
            'period_id' => $period->id,
            'created_by' => $this->purchasing->id,
            'pr_number' => 'REQ/08/2026/'.str_pad((string) $this->requisitionSequence++, 3, '0', STR_PAD_LEFT),
            'status' => 'submitted',
            'notes' => 'Availability test requisition',
        ]);

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

    private function quotationPayload(PurchaseRequisition $pr): array
    {
        return [
            'action' => 'draft',
            'currency' => 'USD',
            'estimated_delivery' => now()->addDays(14)->toDateString(),
            'validity_period' => now()->addDays(30)->toDateString(),
            'payment_terms' => 'TT 30 Days',
            'general_notes' => 'Availability test',
            'items' => $pr->items->map(fn (PrItem $item) => [
                'pr_item_id' => $item->id,
                'price_per_kg' => 2.5,
                'notes' => 'Item note',
            ])->values()->all(),
        ];
    }

    private function createDraftQuotation(PurchaseRequisition $pr, User $supplier, float $price): Quotation
    {
        $quotation = Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $supplier->id,
            'currency' => 'USD',
            'status' => Quotation::STATUS_DRAFT,
            'estimated_delivery' => now()->addDays(14),
            'payment_terms' => 'TT 30 Days',
            'validity_period' => now()->addDays(30),
        ]);

        foreach ($pr->items as $item) {
            $quotation->items()->create([
                'pr_item_id' => $item->id,
                'price_per_kg' => $price,
                'amount' => $price * $item->total_weight,
            ]);
        }

        return $quotation;
    }
}
