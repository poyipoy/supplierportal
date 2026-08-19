<?php

namespace Tests\Feature;

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
            'available_qty' => 5,
            'available_thickness' => 1.3,
            'available_width' => 125,
            'available_length' => 245,
            'available_d_inner' => 999,
            'available_d_outer' => 999,
        ]);
        $payload['items'][1] = array_merge($payload['items'][1], [
            'available_qty' => 4,
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
        $this->assertSame(5, $flat->available_qty);
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
            ->assertSee('width: 100% !important', false)
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
        $payload['items'][0]['available_qty'] = 99;

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
