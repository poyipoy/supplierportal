<?php

namespace Tests\Feature;

use App\Models\HsCodeRule;
use App\Models\MaterialMaster;
use App\Models\User;
use App\Services\Materials\MaterialResolver;
use Database\Seeders\MaterialHsCodeMasterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminMaterialHsCodeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MaterialHsCodeMasterSeeder::class);
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    public function test_admin_page_and_quality_report_are_role_protected(): void
    {
        $this->get(route('admin.material-hs-code.index'))->assertRedirect(route('login'));

        foreach (['purchasing', 'supplier', 'qc'] as $role) {
            $user = User::factory()->create(['role' => $role, 'is_active' => true]);
            $this->actingAs($user)->get(route('admin.material-hs-code.index'))->assertForbidden();
            $this->actingAs($user)->getJson(route('admin.master-data-quality.index'))->assertForbidden();
        }

        $this->actingAs($this->admin)
            ->get(route('admin.material-hs-code.index'))
            ->assertOk()
            ->assertSeeText('Master Material & HS Code')
            ->assertSeeText('Data Quality');
        $this->actingAs($this->admin)
            ->getJson(route('admin.material-masters.data'))
            ->assertOk()
            ->assertJsonStructure(['data']);
        $this->actingAs($this->admin)
            ->getJson(route('admin.hs-code-rules.data'))
            ->assertOk()
            ->assertJsonStructure(['data']);

        $this->actingAs($this->admin)
            ->getJson(route('admin.master-data-quality.index'))
            ->assertOk()
            ->assertJsonPath('counts.materials', 84)
            ->assertJsonPath('counts.mapped_materials', 64)
            ->assertJsonPath('counts.unmapped_materials', 20)
            ->assertJsonPath('counts.active_rules', 19)
            ->assertJsonPath('counts.inactive_rules', 2)
            ->assertJsonPath('counts.source_rule_entries', 30)
            ->assertJsonPath('counts.blocking_conflicts', 0)
            ->assertJsonFragment(['unreachable_rule_categories' => ['strip_steel']])
            ->assertJsonFragment(['unreachable_reference_materials' => ['S35C', 'Q345D', 'Q235', 'A3', 'WEARPLATE']]);
    }

    public function test_admin_material_crud_normalizes_aliases_and_rejects_cross_catalog_collisions(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.material-masters.store'), $this->materialPayload([
                'material_code' => '  ab-1.2  ',
                'aliases_text' => "AB ONE\nab   alt",
            ]))
            ->assertRedirect(route('admin.material-hs-code.index').'#materials')
            ->assertSessionHasNoErrors();

        $material = MaterialMaster::query()->where('normalized_code', 'AB-1.2')->firstOrFail();
        $this->assertSame($this->admin->id, $material->created_by);
        $this->assertSame(['AB ALT', 'AB ONE'], $material->aliases()->orderBy('normalized_alias')->pluck('normalized_alias')->all());
        $this->assertSame($material->id, app(MaterialResolver::class)->resolveExact(' ab   one ')?->id);
        $this->assertNull(app(MaterialResolver::class)->resolveExact('AB ON'));

        $this->actingAs($this->admin)
            ->put(route('admin.material-masters.update', $material), $this->materialPayload([
                'material_code' => $material->material_code,
                'raw_category' => 'Updated Admin Category',
                'aliases_text' => "AB ONE\nAB ALT",
            ]))
            ->assertRedirect(route('admin.material-hs-code.index').'#materials')
            ->assertSessionHasNoErrors();
        $this->assertSame('Updated Admin Category', $material->fresh()->raw_category);
        $this->assertSame($this->admin->id, $material->fresh()->updated_by);

        $this->actingAs($this->admin)
            ->post(route('admin.material-masters.store'), $this->materialPayload([
                'material_code' => 'ab alt',
                'aliases_text' => null,
            ]))
            ->assertSessionHasErrors('material_code');

        $scm = MaterialMaster::query()->where('material_code', 'SCM440')->firstOrFail();
        $this->actingAs($this->admin)
            ->put(route('admin.material-masters.update', $material), $this->materialPayload([
                'material_code' => $material->material_code,
                'aliases_text' => 'SCM440',
            ]))
            ->assertSessionHasErrors('aliases_text');

        $this->actingAs($this->admin)
            ->patchJson(route('admin.material-masters.status', $scm), ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('success', true);
        $this->assertFalse($scm->fresh()->is_active);

        $this->actingAs($this->admin)
            ->delete('/admin/material-masters/'.$material->id)
            ->assertStatus(405);
        $this->assertDatabaseHas('material_masters', ['id' => $material->id]);
    }

    public function test_rule_activation_rejects_same_priority_different_code_overlap(): void
    {
        $conditions = [
            'd_outer' => [
                'min' => 10,
                'min_inclusive' => true,
                'max' => 165,
                'max_inclusive' => true,
            ],
        ];

        $this->actingAs($this->admin)
            ->post(route('admin.hs-code-rules.store'), $this->rulePayload([
                'rule_key' => 'admin-blocking-overlap',
                'conditions_json' => json_encode($conditions, JSON_THROW_ON_ERROR),
            ]))
            ->assertSessionHasErrors('conditions');
        $this->assertDatabaseMissing('hs_code_rules', ['rule_key' => 'admin-blocking-overlap']);

        $this->actingAs($this->admin)
            ->post(route('admin.hs-code-rules.store'), $this->rulePayload([
                'rule_key' => 'admin-priority-override',
                'priority' => 50,
                'conditions_json' => json_encode($conditions, JSON_THROW_ON_ERROR),
            ]))
            ->assertRedirect(route('admin.material-hs-code.index').'#rules')
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('hs_code_rules', [
            'rule_key' => 'admin-priority-override',
            'hs_code' => '9999.99.99',
            'priority' => 50,
            'status' => HsCodeRule::STATUS_ACTIVE,
        ]);

        $priorityOverride = HsCodeRule::query()->where('rule_key', 'admin-priority-override')->firstOrFail();
        $this->actingAs($this->admin)
            ->put(route('admin.hs-code-rules.update', $priorityOverride), $this->rulePayload([
                'rule_key' => $priorityOverride->rule_key,
                'priority' => 50,
                'notes' => 'Updated by Admin',
                'conditions_json' => json_encode($conditions, JSON_THROW_ON_ERROR),
            ]))
            ->assertRedirect(route('admin.material-hs-code.index').'#rules')
            ->assertSessionHasNoErrors();
        $this->assertSame('Updated by Admin', $priorityOverride->fresh()->notes);
        $this->assertSame($this->admin->id, $priorityOverride->fresh()->updated_by);

        $inactive = HsCodeRule::create([
            'rule_key' => 'admin-inactive-conflict',
            'hs_code' => '8888.88.88',
            'material_category' => 'alloy_steel',
            'shape' => 'Round',
            'conditions' => $conditions,
            'priority' => 100,
            'status' => HsCodeRule::STATUS_INACTIVE,
            'source_refs' => [],
        ]);

        $this->actingAs($this->admin)
            ->patchJson(route('admin.hs-code-rules.status', $inactive), ['status' => 'active'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('conditions');
        $this->assertSame(HsCodeRule::STATUS_INACTIVE, $inactive->fresh()->status);

        $this->actingAs($this->admin)
            ->delete('/admin/hs-code-rules/'.$inactive->id)
            ->assertStatus(405);
        $this->assertDatabaseHas('hs_code_rules', ['id' => $inactive->id]);
    }

    public function test_purchasing_search_returns_only_active_materials_and_other_roles_are_denied(): void
    {
        $purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $qc = User::factory()->create(['role' => 'qc', 'is_active' => true]);

        $this->actingAs($purchasing)
            ->getJson(route('purchasing.material-masters.search', ['q' => 'SCM440']))
            ->assertOk()
            ->assertJsonPath('results.0.material_code', 'SCM440');

        $scm = MaterialMaster::query()->where('material_code', 'SCM440')->firstOrFail();
        $scm->update(['is_active' => false]);
        $inactiveResponse = $this->actingAs($purchasing)
            ->getJson(route('purchasing.material-masters.search', ['q' => 'SCM440']))
            ->assertOk();
        $this->assertFalse(collect($inactiveResponse->json('results'))->contains('id', $scm->id));

        $this->actingAs($supplier)
            ->getJson(route('purchasing.material-masters.search', ['q' => 'SCM']))
            ->assertForbidden();
        $this->actingAs($qc)
            ->getJson(route('purchasing.material-masters.search', ['q' => 'SCM']))
            ->assertForbidden();
        $this->actingAs($this->admin)
            ->getJson(route('purchasing.material-masters.search', ['q' => 'SCM']))
            ->assertForbidden();
    }

    private function materialPayload(array $overrides = []): array
    {
        return array_replace([
            'material_code' => 'ADMIN-MATERIAL',
            'raw_category' => 'Admin Category',
            'hs_category' => 'alloy_steel',
            'density_profile' => 'steel',
            'manufacturer_scope' => 'unknown',
            'is_active' => 1,
            'aliases_text' => null,
            'form_context' => 'material',
        ], $overrides);
    }

    private function rulePayload(array $overrides = []): array
    {
        return array_replace([
            'rule_key' => 'admin-rule',
            'hs_code' => '99999999',
            'material_category' => 'alloy_steel',
            'shape' => 'Round',
            'conditions_json' => json_encode([
                'd_outer' => [
                    'min' => 10,
                    'min_inclusive' => true,
                    'max' => 165,
                    'max_inclusive' => true,
                ],
            ], JSON_THROW_ON_ERROR),
            'priority' => 100,
            'status' => 'active',
            'notes' => 'Admin test rule',
            'form_context' => 'rule',
        ], $overrides);
    }
}
