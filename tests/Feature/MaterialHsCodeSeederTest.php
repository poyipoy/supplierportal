<?php

namespace Tests\Feature;

use App\Models\HsCodeRule;
use App\Models\MaterialMaster;
use Database\Seeders\MaterialHsCodeMasterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaterialHsCodeSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_fixture_counts_decisions_and_insert_only_idempotency(): void
    {
        $this->seed(MaterialHsCodeMasterSeeder::class);

        $this->assertDatabaseCount('material_masters', 84);
        $this->assertSame(64, MaterialMaster::whereNotNull('hs_category')->count());
        $this->assertSame(20, MaterialMaster::whereNull('hs_category')->count());
        $this->assertSame(39, MaterialMaster::where('manufacturer_scope', 'daido')->count());
        $this->assertSame(45, MaterialMaster::where('manufacturer_scope', 'non_daido')->count());
        $this->assertSame(4, MaterialMaster::where('density_profile', 'aluminium')->count());
        $this->assertSame(84, MaterialMaster::where('source_sheet', 'master material')->count());
        $this->assertDatabaseCount('hs_code_rules', 21);
        $this->assertSame(19, HsCodeRule::active()->count());

        $this->assertDatabaseHas('hs_code_rules', ['hs_code' => '7304.31.90', 'status' => 'active']);
        $this->assertDatabaseHas('hs_code_rules', ['hs_code' => '7304.31.40', 'status' => 'inactive']);
        $this->assertDatabaseHas('hs_code_rules', ['hs_code' => '7304.59.90', 'status' => 'active']);
        $this->assertDatabaseHas('hs_code_rules', ['hs_code' => '7304.51.90', 'status' => 'inactive']);

        $scm = MaterialMaster::where('material_code', 'SCM440')->firstOrFail();
        $scm->update(['raw_category' => 'Admin protected value']);
        $rule = HsCodeRule::where('rule_key', 'pdf-002-alloy-round-d-10-165')->firstOrFail();
        $rule->update(['notes' => 'Admin protected rule value']);
        $this->seed(MaterialHsCodeMasterSeeder::class);

        $this->assertDatabaseCount('material_masters', 84);
        $this->assertDatabaseCount('hs_code_rules', 21);
        $this->assertSame('Admin protected value', $scm->fresh()->raw_category);
        $this->assertSame('Admin protected rule value', $rule->fresh()->notes);
    }
}
