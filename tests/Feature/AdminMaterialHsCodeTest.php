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
            ->assertSeeText('Data Quality')
            ->assertDontSeeText('Aliases')
            ->assertDontSeeText('Stable Rule Key');
        $materialTable = $this->actingAs($this->admin)
            ->getJson(route('admin.material-masters.data'))
            ->assertOk()
            ->assertJsonStructure(['data'])
            ->json('data');
        $this->assertStringNotContainsString('aliases_display', json_encode($materialTable, JSON_THROW_ON_ERROR));
        $this->actingAs($this->admin)
            ->getJson(route('admin.hs-code-rules.data'))
            ->assertOk()
            ->assertJsonStructure(['data']);

        $qualityResponse = $this->actingAs($this->admin)
            ->getJson(route('admin.master-data-quality.index'))
            ->assertOk()
            ->assertJsonPath('summary.materials', 84)
            ->assertJsonPath('summary.materials_with_hs_mapping', 64)
            ->assertJsonPath('summary.materials_needing_hs_mapping', 20)
            ->assertJsonPath('summary.active_hs_rules', 19)
            ->assertJsonPath('summary.rules_needing_review', 0)
            ->assertJsonFragment(['rule_categories_not_used_by_materials' => ['Strip Steel']])
            ->assertJsonFragment(['reference_only_materials' => ['S35C', 'Q345D', 'Q235', 'A3', 'WEARPLATE']]);

        $report = $qualityResponse->json();
        $this->assertArrayNotHasKey('overlaps', $report);
        $reportJson = json_encode($report, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('"rule_key"', $reportJson);
        $this->assertStringNotContainsString('"type"', $reportJson);
    }

    public function test_admin_material_crud_keeps_legacy_aliases_read_only(): void
    {
        $legacyMaterial = MaterialMaster::create([
            'material_code' => 'LEGACY-MATERIAL',
            'normalized_code' => 'LEGACY-MATERIAL',
            'raw_category' => 'Legacy category',
            'hs_category' => 'alloy_steel',
            'density_profile' => 'steel',
            'manufacturer_scope' => 'unknown',
            'is_active' => true,
        ]);
        $legacyMaterial->aliases()->create([
            'alias' => 'Legacy Alias',
            'normalized_alias' => 'LEGACY ALIAS',
            'source_note' => 'Existing source data',
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.material-masters.store'), $this->materialPayload([
                'material_code' => '  ab-1.2  ',
                'aliases_text' => 'Ignored Alias Input',
            ]))
            ->assertRedirect(route('admin.material-hs-code.index').'#materials')
            ->assertSessionHasNoErrors();

        $material = MaterialMaster::query()->where('normalized_code', 'AB-1.2')->firstOrFail();
        $this->assertSame($this->admin->id, $material->created_by);
        $this->assertCount(0, $material->aliases);

        $this->actingAs($this->admin)
            ->put(route('admin.material-masters.update', $legacyMaterial), $this->materialPayload([
                'material_code' => $legacyMaterial->material_code,
                'raw_category' => 'Updated Admin Category',
                'aliases_text' => 'Attempted Replacement Alias',
            ]))
            ->assertRedirect(route('admin.material-hs-code.index').'#materials')
            ->assertSessionHasNoErrors();
        $this->assertSame('Updated Admin Category', $legacyMaterial->fresh()->raw_category);
        $this->assertSame($this->admin->id, $legacyMaterial->fresh()->updated_by);
        $this->assertSame(['LEGACY ALIAS'], $legacyMaterial->aliases()->pluck('normalized_alias')->all());
        $this->assertSame($legacyMaterial->id, app(MaterialResolver::class)->resolveExact(' legacy alias ')?->id);
        $this->assertDatabaseMissing('material_aliases', ['normalized_alias' => 'ATTEMPTED REPLACEMENT ALIAS']);

        $this->actingAs($this->admin)
            ->post(route('admin.material-masters.store'), $this->materialPayload([
                'material_code' => 'legacy alias',
            ]))
            ->assertSessionHasErrors('material_code');

        $scm = MaterialMaster::query()->where('material_code', 'SCM440')->firstOrFail();
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
                'rule_key' => 'client-provided-blocking-key',
                'conditions_json' => json_encode($conditions, JSON_THROW_ON_ERROR),
            ]))
            ->assertSessionHasErrors('conditions');
        $this->assertDatabaseMissing('hs_code_rules', ['hs_code' => '9999.99.99']);

        $this->actingAs($this->admin)
            ->post(route('admin.hs-code-rules.store'), $this->rulePayload([
                'rule_key' => 'client-provided-rule-key',
                'priority' => 50,
                'conditions_json' => json_encode($conditions, JSON_THROW_ON_ERROR),
            ]))
            ->assertRedirect(route('admin.material-hs-code.index').'#rules')
            ->assertSessionHasNoErrors();
        $priorityOverride = HsCodeRule::query()->where('hs_code', '9999.99.99')->firstOrFail();
        $this->assertStringStartsWith('rule-', $priorityOverride->rule_key);
        $this->assertNotSame('client-provided-rule-key', $priorityOverride->rule_key);
        $originalRuleKey = $priorityOverride->rule_key;
        $this->actingAs($this->admin)
            ->put(route('admin.hs-code-rules.update', $priorityOverride), $this->rulePayload([
                'rule_key' => 'attempted-replacement-key',
                'priority' => 50,
                'notes' => 'Updated by Admin',
                'conditions_json' => json_encode($conditions, JSON_THROW_ON_ERROR),
            ]))
            ->assertRedirect(route('admin.material-hs-code.index').'#rules')
            ->assertSessionHasNoErrors();
        $this->assertSame('Updated by Admin', $priorityOverride->fresh()->notes);
        $this->assertSame($this->admin->id, $priorityOverride->fresh()->updated_by);
        $this->assertSame($originalRuleKey, $priorityOverride->fresh()->rule_key);

        $ruleTable = $this->actingAs($this->admin)
            ->getJson(route('admin.hs-code-rules.data'))
            ->assertOk()
            ->json('data');
        $this->assertStringNotContainsString($originalRuleKey, json_encode($ruleTable, JSON_THROW_ON_ERROR));

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

    public function test_data_quality_summarizes_duplicate_coverage_and_surfaces_rules_needing_review(): void
    {
        $existing = HsCodeRule::query()->where('status', HsCodeRule::STATUS_ACTIVE)->firstOrFail();
        HsCodeRule::create([
            'rule_key' => 'test-quality-duplicate-coverage',
            'hs_code' => $existing->hs_code,
            'material_category' => $existing->material_category,
            'shape' => $existing->shape,
            'conditions' => $existing->conditions,
            'priority' => $existing->priority,
            'status' => HsCodeRule::STATUS_ACTIVE,
            'source_refs' => [],
        ]);

        $duplicateResponse = $this->actingAs($this->admin)
            ->getJson(route('admin.master-data-quality.index'))
            ->assertOk();
        $this->assertGreaterThan(0, $duplicateResponse->json('reference_notes.duplicate_rule_coverage.count'));
        $this->assertStringContainsString('No action is required.', $duplicateResponse->json('reference_notes.duplicate_rule_coverage.message'));
        $this->assertSame(0, $duplicateResponse->json('summary.rules_needing_review'));

        HsCodeRule::create([
            'rule_key' => 'test-quality-needs-review',
            'hs_code' => '9999.99.99',
            'material_category' => $existing->material_category,
            'shape' => $existing->shape,
            'conditions' => $existing->conditions,
            'priority' => $existing->priority,
            'status' => HsCodeRule::STATUS_ACTIVE,
            'source_refs' => [],
        ]);

        $reviewResponse = $this->actingAs($this->admin)
            ->getJson(route('admin.master-data-quality.index'))
            ->assertOk();
        $this->assertGreaterThan(0, $reviewResponse->json('summary.rules_needing_review'));
        $review = $reviewResponse->json('needs_attention.rules_needing_review.0');
        $this->assertArrayHasKey('category', $review);
        $this->assertArrayHasKey('shape', $review);
        $this->assertArrayHasKey('hs_codes', $review);
        $this->assertArrayHasKey('message', $review);
        $this->assertArrayNotHasKey('rule_key', $review);
        $this->assertArrayNotHasKey('type', $review);
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
            'form_context' => 'material',
        ], $overrides);
    }

    private function rulePayload(array $overrides = []): array
    {
        return array_replace([
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
