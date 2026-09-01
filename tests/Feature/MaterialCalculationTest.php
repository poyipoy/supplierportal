<?php

namespace Tests\Feature;

use App\Models\MaterialMaster;
use App\Models\User;
use Database\Seeders\MaterialHsCodeMasterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaterialCalculationTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MaterialHsCodeMasterSeeder::class);
        $this->purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
    }

    public function test_scm440_round_and_fixed_source_conflicts_resolve_deterministically(): void
    {
        $scm = MaterialMaster::where('material_code', 'SCM440')->firstOrFail();
        $this->actingAs($this->purchasing)
            ->postJson(route('purchasing.material-calculations.preview'), [
                'material_master_id' => $scm->id,
                'shape' => 'Round',
                'quantity' => 2,
                'd_outer' => 100,
                'length' => 1000,
            ])
            ->assertOk()
            ->assertJsonPath('hs_code.status', 'matched')
            ->assertJsonPath('hs_code.code', '7228.30.10')
            ->assertJsonPath('weight.unit_kg', 61.67)
            ->assertJsonPath('weight.total_kg', 123.34);

        $st52 = MaterialMaster::where('material_code', 'ST52')->firstOrFail();
        $this->actingAs($this->purchasing)
            ->postJson(route('purchasing.material-calculations.preview'), [
                'material_master_id' => $st52->id,
                'shape' => 'Hollow',
                'quantity' => 1,
                'd_outer' => 200,
                'd_inner' => 100,
                'length' => 1000,
            ])
            ->assertOk()
            ->assertJsonPath('hs_code.code', '7304.31.90')
            ->assertJsonPath('weight.unit_kg', 185.01);

        $this->actingAs($this->purchasing)
            ->postJson(route('purchasing.material-calculations.preview'), [
                'material_master_id' => $scm->id,
                'shape' => 'Hollow',
                'quantity' => 1,
                'd_outer' => 200,
                'd_inner' => 150,
                'length' => 1000,
            ])
            ->assertOk()
            ->assertJsonPath('hs_code.code', '7304.59.90')
            ->assertJsonPath('weight.unit_kg', 107.9225);
    }

    public function test_hollow_preview_rejects_equal_and_reversed_diameters_without_writes(): void
    {
        $scm = MaterialMaster::where('material_code', 'SCM440')->firstOrFail();

        foreach ([[100, 100], [101, 100]] as [$inner, $outer]) {
            $this->actingAs($this->purchasing)
                ->postJson(route('purchasing.material-calculations.preview'), [
                    'material_master_id' => $scm->id,
                    'shape' => 'Hollow',
                    'quantity' => 1,
                    'd_outer' => $outer,
                    'd_inner' => $inner,
                    'length' => 1000,
                ])
                ->assertUnprocessable()
                ->assertJsonPath('success', false)
                ->assertJsonPath('errors.d_inner', 'Inner diameter must be smaller than outer diameter.')
                ->assertJsonPath('weight.status', 'invalid')
                ->assertJsonPath('weight.message', 'Inner diameter must be smaller than outer diameter.');
        }

        $this->assertDatabaseCount('pr_items', 0);
    }

    public function test_aluminium_weight_and_manual_unresolved_preview(): void
    {
        $aluminium = MaterialMaster::where('material_code', 'Aluminium 7075')->firstOrFail();

        $this->actingAs($this->purchasing)
            ->postJson(route('purchasing.material-calculations.preview'), [
                'material_master_id' => $aluminium->id,
                'shape' => 'Flat',
                'quantity' => 1,
                'thickness' => 10,
                'width' => 100,
                'length' => 1000,
                'manual_hs_code' => '76061290',
            ])
            ->assertOk()
            ->assertJsonPath('material.density_profile', 'aluminium')
            ->assertJsonPath('hs_code.status', 'no_rule')
            ->assertJsonPath('hs_code.selected_code', '7606.12.90')
            ->assertJsonPath('hs_code.source', 'manual')
            ->assertJsonPath('weight.unit_kg', 2.73);
    }

    public function test_preview_returns_explicit_manual_hs_and_weight_overrides(): void
    {
        $scm = MaterialMaster::where('material_code', 'SCM440')->firstOrFail();

        $this->actingAs($this->purchasing)
            ->postJson(route('purchasing.material-calculations.preview'), [
                'material_master_id' => $scm->id,
                'shape' => 'Round',
                'quantity' => 2,
                'd_outer' => 100,
                'length' => 1000,
                'hs_code' => '99999999',
                'hs_code_manual_override' => 1,
                'weight_needed' => 12.34567,
                'weight_manual_override' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('hs_code.selected_code', '9999.99.99')
            ->assertJsonPath('hs_code.source', 'manual')
            ->assertJsonPath('weight.status', 'manual')
            ->assertJsonPath('weight.unit_kg', 12.3457)
            ->assertJsonPath('weight.total_kg', 24.6914);
    }

    public function test_endpoint_is_purchasing_only_and_preview_does_not_write(): void
    {
        $material = MaterialMaster::firstOrFail();
        $before = MaterialMaster::count();

        foreach (['supplier', 'qc', 'admin'] as $role) {
            $user = User::factory()->create(['role' => $role, 'is_active' => true]);
            $this->actingAs($user)->postJson(route('purchasing.material-calculations.preview'), [
                'material_master_id' => $material->id,
                'shape' => 'Round',
                'quantity' => 1,
                'd_outer' => 100,
                'length' => 1000,
            ])->assertForbidden();
        }

        $this->assertSame($before, MaterialMaster::count());
        $this->assertDatabaseCount('pr_items', 0);
    }
}
