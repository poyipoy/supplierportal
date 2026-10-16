<?php

namespace Tests\Feature\Procurement;

use App\Models\MaterialMaster;
use App\Models\Period;
use App\Models\PrItem;
use App\Models\PurchaseRequisition;
use App\Models\User;
use Database\Seeders\MaterialHsCodeMasterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Decisions 11-13 / AC-07: Port of Discharge and Incoterms are optional on a
 * draft and required on every submit path (store, update, list quick-submit).
 */
class PrLogisticsSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasing;

    private Period $period;

    private MaterialMaster $material;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MaterialHsCodeMasterSeeder::class);
        $this->material = MaterialMaster::query()->where('material_code', 'S45C')->firstOrFail();
        $this->purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $this->period = Period::create([
            'name' => 'Logistics Period', 'month' => 10, 'year' => 2026, 'status' => 'open', 'created_by' => $this->purchasing->id,
        ]);
    }

    public function test_draft_is_saved_without_logistics(): void
    {
        $this->actingAs($this->purchasing)
            ->post(route('purchasing.requisitions.store'), $this->payload('draft'))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $pr = PurchaseRequisition::sole();
        $this->assertSame('draft', $pr->status);
        $this->assertNull($pr->port_of_discharge);
        $this->assertNull($pr->incoterm);
    }

    public function test_draft_keeps_partial_logistics(): void
    {
        $this->actingAs($this->purchasing)
            ->post(route('purchasing.requisitions.store'), $this->payload('draft', ['port_of_discharge' => 'TANJUNG_PERAK']))
            ->assertSessionHasNoErrors();

        $this->assertSame('TANJUNG_PERAK', PurchaseRequisition::sole()->port_of_discharge);
        $this->assertNull(PurchaseRequisition::sole()->incoterm);
    }

    public function test_store_submit_requires_both_logistics_terms(): void
    {
        $this->actingAs($this->purchasing)
            ->post(route('purchasing.requisitions.store'), $this->payload('submitted'))
            ->assertSessionHasErrors(['port_of_discharge', 'incoterm']);

        $this->assertDatabaseCount('purchase_requisitions', 0);
    }

    public function test_store_submit_persists_logistics(): void
    {
        $this->actingAs($this->purchasing)
            ->post(route('purchasing.requisitions.store'), $this->payload('submitted', ['port_of_discharge' => 'TANJUNG_PERAK', 'incoterm' => 'FOB']))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $pr = PurchaseRequisition::sole();
        $this->assertSame('submitted', $pr->status);
        $this->assertSame(['TANJUNG_PERAK', 'FOB'], [$pr->port_of_discharge, $pr->incoterm]);
    }

    public function test_out_of_list_codes_are_rejected_even_on_a_draft(): void
    {
        $this->actingAs($this->purchasing)
            ->post(route('purchasing.requisitions.store'), $this->payload('draft', ['port_of_discharge' => 'BELAWAN', 'incoterm' => 'cif']))
            ->assertSessionHasErrors(['port_of_discharge', 'incoterm']);

        $this->assertDatabaseCount('purchase_requisitions', 0);
    }

    public function test_update_submit_requires_logistics_and_persists_them(): void
    {
        $pr = $this->draft();

        $this->actingAs($this->purchasing)
            ->put(route('purchasing.requisitions.update', $pr), $this->payload('submitted'))
            ->assertSessionHasErrors(['port_of_discharge', 'incoterm']);
        $this->assertSame('draft', $pr->fresh()->status);

        $this->actingAs($this->purchasing)
            ->put(route('purchasing.requisitions.update', $pr), $this->payload('submitted', ['port_of_discharge' => 'TANJUNG_PRIOK', 'incoterm' => 'CIF']))
            ->assertSessionHasNoErrors();

        $fresh = $pr->fresh();
        $this->assertSame('submitted', $fresh->status);
        $this->assertSame(['TANJUNG_PRIOK', 'CIF'], [$fresh->port_of_discharge, $fresh->incoterm]);
    }

    public function test_quick_submit_without_logistics_is_refused_and_draft_unchanged(): void
    {
        $pr = $this->draft();

        $this->actingAs($this->purchasing)
            ->put(route('purchasing.requisitions.submit', $pr))
            ->assertRedirect()
            ->assertSessionHasErrors(['port_of_discharge', 'incoterm']);

        $fresh = $pr->fresh();
        $this->assertSame('draft', $fresh->status);
        $this->assertNull($fresh->pr_number);
    }

    public function test_quick_submit_with_draft_logistics_succeeds(): void
    {
        $pr = $this->draft(['port_of_discharge' => 'TANJUNG_PRIOK', 'incoterm' => 'CFR']);

        $this->actingAs($this->purchasing)
            ->put(route('purchasing.requisitions.submit', $pr))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('submitted', $pr->fresh()->status);
    }

    public function test_forms_and_show_expose_the_logistics_terms(): void
    {
        $this->actingAs($this->purchasing)->get(route('purchasing.requisitions.create'))
            ->assertOk()
            ->assertSee('name="port_of_discharge"', false)
            ->assertSee('name="incoterm"', false)
            ->assertSee('CIF — Cost, Insurance and Freight');

        $pr = $this->draft(['port_of_discharge' => 'TANJUNG_PERAK', 'incoterm' => 'FOB']);
        $this->actingAs($this->purchasing)->get(route('purchasing.requisitions.edit', $pr))
            ->assertOk()
            ->assertSee('value="TANJUNG_PERAK" selected', false);
        $this->actingAs($this->purchasing)->get(route('purchasing.requisitions.show', $pr))
            ->assertOk()
            ->assertSeeText('Tanjung Perak')
            ->assertSeeText('FOB — Free On Board');
    }

    public function test_show_footer_totals_are_computed_by_the_server(): void
    {
        $pr = $this->draft();
        $pr->items()->create($this->item('Second', 3, 10.25));

        $response = $this->actingAs($this->purchasing)->get(route('purchasing.requisitions.show', $pr))->assertOk();

        // 2 x 150.5 + 3 x 10.25 = 331.75 kg over 5 pcs.
        $footer = $this->footer($response->getContent());
        $this->assertStringContainsString('5', $footer);
        $this->assertStringContainsString('331.75', $footer);
    }

    private function footer(string $html): string
    {
        $this->assertMatchesRegularExpression('/<tfoot[^>]*id="prItemTotals"[^>]*>.*?<\/tfoot>/s', $html);
        preg_match('/<tfoot[^>]*id="prItemTotals"[^>]*>(.*?)<\/tfoot>/s', $html, $matches);

        return strip_tags($matches[1]);
    }

    private function draft(array $logistics = []): PurchaseRequisition
    {
        $pr = PurchaseRequisition::create(array_merge([
            'period_id' => $this->period->id,
            'created_by' => $this->purchasing->id,
            'status' => 'draft',
        ], $logistics));
        $pr->items()->create($this->item('Draft Material', 2, 150.5));

        return $pr;
    }

    private function payload(string $action, array $extra = []): array
    {
        return array_merge([
            'period_id' => $this->period->id,
            'action' => $action,
            'supplier_selection_present' => 1,
            'items' => [$this->item('Logistics Material', 2, 150.5)],
        ], $extra);
    }

    private function item(string $name, int $quantity, float $weight): array
    {
        return [
            'material_master_id' => $this->material->id,
            'hs_code' => '7209.16.00',
            'material_name' => $name,
            'quantity' => $quantity,
            'shape' => PrItem::SHAPE_FLAT,
            'thickness' => 5,
            'width' => 700,
            'length' => 2000,
            'weight_needed' => $weight,
        ];
    }
}
