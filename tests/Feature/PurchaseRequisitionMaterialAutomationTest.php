<?php

namespace Tests\Feature;

use App\Models\HsCodeRule;
use App\Models\MaterialMaster;
use App\Models\Period;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\User;
use Database\Seeders\MaterialHsCodeMasterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseRequisitionMaterialAutomationTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasing;

    private Period $period;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MaterialHsCodeMasterSeeder::class);
        $this->purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $this->period = Period::create([
            'name' => 'Automation August 2026',
            'month' => 8,
            'year' => 2026,
            'status' => 'open',
            'created_by' => $this->purchasing->id,
        ]);
    }

    public function test_partial_draft_stores_zero_incomplete_and_ignores_tampered_values(): void
    {
        $scm = MaterialMaster::where('material_code', 'SCM440')->firstOrFail();

        $this->actingAs($this->purchasing)
            ->post(route('purchasing.requisitions.store'), [
                'period_id' => $this->period->id,
                'action' => 'draft',
                'items' => [[
                    'material_master_id' => $scm->id,
                    'material_name' => 'Spoofed material',
                    'quantity' => 2,
                    'hs_code' => '9999.99.99',
                    'weight_needed' => 999999,
                ]],
            ])->assertRedirect();

        $item = PurchaseRequisition::firstOrFail()->items()->firstOrFail();
        $this->assertSame('SCM440', $item->material_name);
        $this->assertNull($item->hs_code);
        $this->assertSame('insufficient_data', $item->hs_code_resolution_status);
        $this->assertSame('0.0000', $item->weight_needed);
        $this->assertSame('incomplete', $item->weight_calculation_status);
    }

    public function test_create_and_edit_forms_render_adaptive_dimension_table_contract(): void
    {
        $this->actingAs($this->purchasing)
            ->get(route('purchasing.requisitions.create'))
            ->assertOk()
            ->assertSee('Dimension 1 (mm)')
            ->assertSee('Dimension 2 (mm)')
            ->assertSee('Dimension 3 (mm)')
            ->assertSee('KG / Unit (kg)')
            ->assertSee('HS Code')
            ->assertSee('data-pr-row-number', false)
            ->assertSee('renumberPrRows', false)
            ->assertSee('pr-sticky-number', false)
            ->assertSee('pr-sticky-material', false)
            ->assertSee('pr-sticky-action', false)
            ->assertSee('border-right: 1px solid var(--md-outline-variant) !important', false)
            ->assertSee('border-collapse: separate !important', false)
            ->assertSee('width: 100%;', false)
            ->assertSee('dimension-input', false)
            ->assertSee('data-dimension-slot="1"', false)
            ->assertSee('data-dimension-slot-header="3"', false)
            ->assertSee('data-dimension-canonical-field="d_outer"', false)
            ->assertDontSee('dimension-source-input', false)
            ->assertSee('data-dimension-slot-cell', false);

        $scm = MaterialMaster::where('material_code', 'SCM440')->firstOrFail();
        $pr = PurchaseRequisition::create([
            'period_id' => $this->period->id,
            'created_by' => $this->purchasing->id,
            'status' => 'draft',
        ]);
        $pr->items()->create([
            'material_master_id' => $scm->id,
            'material_name' => $scm->material_code,
            'quantity' => 1,
            'shape' => 'Hollow',
            'd_inner' => 60,
            'd_outer' => 100,
            'length' => 1000,
            'weight_needed' => 61.67,
        ]);

        $response = $this->actingAs($this->purchasing)
            ->get(route('purchasing.requisitions.edit', $pr))
            ->assertOk()
            ->assertSee('Outer Diameter')
            ->assertSee('Length')
            ->assertDontSee('Not used')
            ->assertSee('name="items[0][d_outer]"', false)
            ->assertSee('data-dimension-field="d_outer"', false)
            ->assertSee('data-dimension-slot="1"', false)
            ->assertSee('data-dimension-slot="2"', false)
            ->assertSee('data-dimension-slot="3"', false)
            ->assertSee('data-dimension-canonical-field="d_outer"', false)
            ->assertSee('is-disabled', false);

        $content = $response->getContent();
        $this->assertLessThan(
            strpos($content, 'data-dimension-field-cell="d_inner"'),
            strpos($content, 'data-dimension-field-cell="d_outer"'),
            'Hollow presentation must render Outer Diameter before Inner Diameter.'
        );
    }

    public function test_dimension_slots_render_shape_labels_for_mixed_rows(): void
    {
        $scm = MaterialMaster::where('material_code', 'SCM440')->firstOrFail();
        $pr = PurchaseRequisition::create([
            'period_id' => $this->period->id,
            'created_by' => $this->purchasing->id,
            'status' => 'draft',
        ]);

        $pr->items()->createMany([
            [
                'material_master_id' => $scm->id,
                'material_name' => $scm->material_code,
                'quantity' => 1,
                'shape' => 'Flat',
                'thickness' => 10,
                'width' => 100,
                'length' => 1000,
                'weight_needed' => 10,
            ],
            [
                'material_master_id' => $scm->id,
                'material_name' => $scm->material_code,
                'quantity' => 1,
                'shape' => 'Round',
                'd_outer' => 100,
                'length' => 1000,
                'weight_needed' => 10,
            ],
            [
                'material_master_id' => $scm->id,
                'material_name' => $scm->material_code,
                'quantity' => 1,
                'shape' => 'Hollow',
                'd_inner' => 60,
                'd_outer' => 100,
                'length' => 1000,
                'weight_needed' => 10,
            ],
            [
                'material_master_id' => $scm->id,
                'material_name' => $scm->material_code,
                'quantity' => 1,
                'shape' => null,
                'weight_needed' => 10,
            ],
        ]);

        $this->actingAs($this->purchasing)
            ->get(route('purchasing.requisitions.edit', $pr))
            ->assertOk()
            ->assertSee('Dimension 1 (mm)')
            ->assertSee('Thickness (mm)')
            ->assertSee('Width (mm)')
            ->assertSee('Outer Diameter')
            ->assertSee('Inner Diameter')
            ->assertSee('data-dimension-canonical-field="thickness"', false)
            ->assertSee('data-dimension-canonical-field="d_inner"', false)
            ->assertSee('data-dimension-canonical-field="d_outer"', false)
            ->assertSee('data-dimension-canonical-field="width"', false)
            ->assertSee('data-dimension-canonical-field="length"', false)
            ->assertSee('data-dimension-field-cell=""', false)
            ->assertSee('aria-label="Dimension 1 unavailable"', false)
            ->assertSee('materialDimensionSlotCount', false)
            ->assertSee('updateMaterialDimensionHeaders', false);
    }

    public function test_submitted_item_is_recomputed_and_valid_auto_match_cannot_be_overridden(): void
    {
        $scm = MaterialMaster::where('material_code', 'SCM440')->firstOrFail();

        $this->actingAs($this->purchasing)
            ->post(route('purchasing.requisitions.store'), $this->payload('submitted', [[
                'material_master_id' => $scm->id,
                'quantity' => 2,
                'shape' => 'Round',
                'd_outer' => 100,
                'length' => 1000,
                'manual_hs_code' => '99999999',
                'hs_code' => '9999.99.99',
                'weight_needed' => 1,
            ]]))->assertRedirect();

        $pr = PurchaseRequisition::firstOrFail();
        $item = $pr->items()->firstOrFail();
        $this->assertSame('submitted', $pr->status);
        $this->assertSame('7228.30.10', $item->hs_code);
        $this->assertSame('auto', $item->hs_code_source);
        $this->assertSame('61.6700', $item->weight_needed);
        $this->assertSame(123.34, $item->total_weight);
        $this->assertNull($item->hs_code_manual_selected_by);
    }

    public function test_explicit_manual_hs_and_weight_overrides_are_persisted(): void
    {
        $scm = MaterialMaster::where('material_code', 'SCM440')->firstOrFail();

        $this->actingAs($this->purchasing)
            ->post(route('purchasing.requisitions.store'), $this->payload('submitted', [[
                'material_master_id' => $scm->id,
                'quantity' => 2,
                'shape' => 'Round',
                'd_outer' => 100,
                'length' => 1000,
                'hs_code' => '99999999',
                'hs_code_manual_override' => 1,
                'weight_needed' => 12.34567,
                'weight_manual_override' => 1,
            ]]))
            ->assertRedirect();

        $item = PurchaseRequisition::firstOrFail()->items()->firstOrFail();

        $this->assertSame('9999.99.99', $item->hs_code);
        $this->assertSame('manual', $item->hs_code_source);
        $this->assertNotNull($item->hs_code_manual_selected_by);
        $this->assertSame('12.3457', $item->weight_needed);
        $this->assertSame('manual', $item->weight_calculation_status);
        $this->assertSame('manual', $item->weight_formula_key);
    }

    public function test_unmapped_material_accepts_audited_manual_hs_without_reason(): void
    {
        $material = MaterialMaster::where('material_code', 'F3RV')->firstOrFail();

        $this->actingAs($this->purchasing)
            ->post(route('purchasing.requisitions.store'), $this->payload('submitted', [[
                'material_master_id' => $material->id,
                'quantity' => 1,
                'shape' => 'Flat',
                'thickness' => 10,
                'width' => 100,
                'length' => 1000,
                'manual_hs_code' => '72284090',
            ]]))->assertRedirect();

        $item = PurchaseRequisition::firstOrFail()->items()->firstOrFail();
        $this->assertSame('7228.40.90', $item->hs_code);
        $this->assertSame('manual', $item->hs_code_source);
        $this->assertSame('unmapped_material', $item->hs_code_resolution_status);
        $this->assertSame($this->purchasing->id, $item->hs_code_manual_selected_by);
        $this->assertNotNull($item->hs_code_manual_selected_at);
        $this->assertNull($item->hs_code_rule_id);
    }

    public function test_valid_submission_allows_unresolved_hs_code_without_fabricating_a_value(): void
    {
        $material = MaterialMaster::where('material_code', 'F3RV')->firstOrFail();

        $this->actingAs($this->purchasing)
            ->post(route('purchasing.requisitions.store'), $this->payload('submitted', [[
                'material_master_id' => $material->id,
                'quantity' => 2,
                'shape' => 'Flat',
                'thickness' => 10,
                'width' => 100,
                'length' => 1000,
            ]]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $pr = PurchaseRequisition::firstOrFail();
        $item = $pr->items()->firstOrFail();

        $this->assertSame('submitted', $pr->status);
        $this->assertNull($item->hs_code);
        $this->assertSame('unmapped_material', $item->hs_code_resolution_status);
        $this->assertSame('auto', $item->hs_code_source);
        $this->assertGreaterThan(0, $item->total_weight);
    }

    public function test_valid_draft_preserves_unresolved_hs_code_as_null(): void
    {
        $material = MaterialMaster::where('material_code', 'F3RV')->firstOrFail();

        $this->actingAs($this->purchasing)
            ->post(route('purchasing.requisitions.store'), $this->payload('draft', [[
                'material_master_id' => $material->id,
                'quantity' => 1,
                'shape' => 'Round',
                'd_outer' => 50,
                'length' => 500,
            ]]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $item = PurchaseRequisition::firstOrFail()->items()->firstOrFail();

        $this->assertNull($item->hs_code);
        $this->assertSame('unmapped_material', $item->hs_code_resolution_status);
        $this->assertGreaterThan(0, (float) $item->weight_needed);
    }

    public function test_manual_hs_is_rejected_for_insufficient_data_or_noncanonical_format(): void
    {
        $scm = MaterialMaster::where('material_code', 'SCM440')->firstOrFail();

        $this->actingAs($this->purchasing)
            ->post(route('purchasing.requisitions.store'), $this->payload('submitted', [[
                'material_master_id' => $scm->id,
                'quantity' => 1,
                'manual_hs_code' => '72284090',
            ]]))
            ->assertSessionHasErrors(['items.0.shape']);
        $this->assertDatabaseCount('purchase_requisitions', 0);

        $unmapped = MaterialMaster::where('material_code', 'F3RV')->firstOrFail();
        $this->actingAs($this->purchasing)
            ->post(route('purchasing.requisitions.store'), $this->payload('submitted', [[
                'material_master_id' => $unmapped->id,
                'quantity' => 1,
                'shape' => 'Flat',
                'thickness' => 10,
                'width' => 100,
                'length' => 1000,
                'manual_hs_code' => '7228-40-90',
            ]]))
            ->assertSessionHasErrors('items.0.manual_hs_code');
        $this->assertDatabaseCount('purchase_requisitions', 0);
    }

    public function test_no_rule_resolution_accepts_manual_fallback(): void
    {
        $scm = MaterialMaster::where('material_code', 'SCM440')->firstOrFail();

        $this->actingAs($this->purchasing)
            ->post(route('purchasing.requisitions.store'), $this->payload('submitted', [[
                'material_master_id' => $scm->id,
                'quantity' => 1,
                'shape' => 'Round',
                'd_outer' => 5,
                'length' => 1000,
                'manual_hs_code' => '7228.30.10',
            ]]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $item = PurchaseRequisition::firstOrFail()->items()->firstOrFail();
        $this->assertSame('no_rule', $item->hs_code_resolution_status);
        $this->assertSame('manual', $item->hs_code_source);
        $this->assertSame('7228.30.10', $item->hs_code);
        $this->assertSame($this->purchasing->id, $item->hs_code_manual_selected_by);
    }

    public function test_ambiguous_resolution_accepts_manual_fallback_with_audit(): void
    {
        HsCodeRule::create([
            'rule_key' => 'test-ambiguous-alloy-round',
            'hs_code' => '9999.99.99',
            'material_category' => 'alloy_steel',
            'shape' => 'Round',
            'conditions' => [
                'd_outer' => [
                    'min' => 10,
                    'min_inclusive' => true,
                    'max' => 165,
                    'max_inclusive' => true,
                ],
            ],
            'priority' => 100,
            'status' => 'active',
            'source_refs' => [],
        ]);
        $scm = MaterialMaster::where('material_code', 'SCM440')->firstOrFail();

        $this->actingAs($this->purchasing)
            ->post(route('purchasing.requisitions.store'), $this->payload('submitted', [[
                'material_master_id' => $scm->id,
                'quantity' => 1,
                'shape' => 'Round',
                'd_outer' => 100,
                'length' => 1000,
                'manual_hs_code' => '7228.30.10',
            ]]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $item = PurchaseRequisition::firstOrFail()->items()->firstOrFail();
        $this->assertSame('ambiguous', $item->hs_code_resolution_status);
        $this->assertSame('manual', $item->hs_code_source);
        $this->assertSame('7228.30.10', $item->hs_code);
        $this->assertSame($this->purchasing->id, $item->hs_code_manual_selected_by);
    }

    public function test_quick_submit_revalidates_saved_items_and_keeps_incomplete_draft(): void
    {
        $scm = MaterialMaster::where('material_code', 'SCM440')->firstOrFail();
        $pr = PurchaseRequisition::create([
            'period_id' => $this->period->id,
            'created_by' => $this->purchasing->id,
            'status' => 'draft',
        ]);
        $pr->items()->create([
            'material_master_id' => $scm->id,
            'material_name' => $scm->material_code,
            'quantity' => 1,
            'weight_needed' => 0,
            'weight_calculation_status' => 'incomplete',
            'hs_code_source' => 'auto',
            'hs_code_resolution_status' => 'insufficient_data',
        ]);

        $this->actingAs($this->purchasing)
            ->put(route('purchasing.requisitions.submit', $pr))
            ->assertRedirect()
            ->assertSessionHasErrors();

        $this->assertSame('draft', $pr->fresh()->status);
        $this->assertNull($pr->fresh()->pr_number);
    }

    public function test_id_aware_update_preserves_quotation_item_reference(): void
    {
        $supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $scm = MaterialMaster::where('material_code', 'SCM440')->firstOrFail();

        $this->actingAs($this->purchasing)->post(route('purchasing.requisitions.store'), $this->payload('draft', [[
            'material_master_id' => $scm->id,
            'quantity' => 1,
            'shape' => 'Round',
            'd_outer' => 100,
            'length' => 1000,
        ]]))->assertRedirect();

        $pr = PurchaseRequisition::firstOrFail();
        $item = $pr->items()->firstOrFail();
        $quotation = Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $supplier->id,
            'currency' => 'USD',
            'status' => 'draft',
        ]);
        $quotationItem = $quotation->items()->create([
            'pr_item_id' => $item->id,
            'price_per_kg' => 5,
            'amount' => 0,
        ]);

        $this->actingAs($this->purchasing)->put(route('purchasing.requisitions.update', $pr), $this->payload('draft', [[
            'id' => $item->id,
            'material_master_id' => $scm->id,
            'quantity' => 3,
            'shape' => 'Round',
            'd_outer' => 100,
            'length' => 1000,
        ]]))->assertRedirect();

        $this->assertSame(3, $item->fresh()->quantity);
        $this->assertSame($item->id, $quotationItem->fresh()->pr_item_id);
        $this->assertDatabaseCount('pr_items', 1);

        $this->actingAs($this->purchasing)->put(route('purchasing.requisitions.update', $pr), $this->payload('draft', [[
            'material_master_id' => $scm->id,
            'quantity' => 1,
            'shape' => 'Round',
            'd_outer' => 80,
            'length' => 1000,
        ]]))
            ->assertSessionHasErrors('items');
        $this->assertDatabaseHas('quotation_items', ['id' => $quotationItem->id, 'pr_item_id' => $item->id]);
        $this->assertDatabaseCount('pr_items', 1);

        $this->actingAs($this->purchasing)
            ->delete(route('purchasing.requisitions.destroy', $pr))
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->assertDatabaseHas('purchase_requisitions', ['id' => $pr->id]);
        $this->assertDatabaseHas('quotation_items', ['id' => $quotationItem->id]);
    }

    public function test_individual_item_endpoint_uses_the_same_server_processor(): void
    {
        $scm = MaterialMaster::where('material_code', 'SCM440')->firstOrFail();
        $pr = PurchaseRequisition::create([
            'period_id' => $this->period->id,
            'created_by' => $this->purchasing->id,
            'status' => 'draft',
        ]);

        $this->actingAs($this->purchasing)
            ->postJson(route('purchasing.pr-items.store'), [
                'material_master_id' => $scm->id,
                'quantity' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('pr_id');

        $this->actingAs($this->purchasing)
            ->postJson(route('purchasing.pr-items.store'), [
                'pr_id' => $pr->id,
                'material_master_id' => $scm->id,
                'material_name' => 'Spoofed',
                'quantity' => 2,
                'shape' => 'Round',
                'd_outer' => 100,
                'length' => 1000,
                'hs_code' => '9999.99.99',
                'weight_needed' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('item.material_name', 'SCM440')
            ->assertJsonPath('item.hs_code', '7228.30.10')
            ->assertJsonPath('item.weight_needed', '61.6700');
    }

    private function payload(string $action, array $items): array
    {
        return [
            'period_id' => $this->period->id,
            'action' => $action,
            'notes' => 'Automation test',
            'items' => $items,
        ];
    }
}
