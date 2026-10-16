<?php

namespace Tests\Feature\Procurement;

use App\Models\HsCodeRule;
use App\Models\MasterDataAuditLog;
use App\Models\MaterialMaster;
use App\Models\MaterialReferencePrice;
use App\Models\User;
use App\Services\Materials\MasterDataDeletionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Mission 06A (decision 28, Q3): Purchasing manages the Material / HS Code
 * masters through the Admin controllers, with a guarded, audited hard delete
 * (AC-25, AC-26, AC-35; I-13, I-16).
 */
class MasterDataAccessTest extends TestCase
{
    use ProcurementFixtures;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurementActors();
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    #[DataProvider('managers')]
    public function test_admin_and_purchasing_manage_both_masters_with_an_audit_trail(string $role, string $prefix): void
    {
        $actor = $role === 'admin' ? $this->admin : $this->purchasing;
        $this->actingAs($actor)->get(route($prefix.'.material-hs-code.index'))->assertOk()
            // URLs are emitted through @json (escaped slashes).
            ->assertSee(str_replace('/', '\/', route($prefix.'.material-masters.data')), false)
            ->assertSee(str_replace('/', '\/', route($prefix.'.material-masters.destroy', 0)), false);
        $this->getJson(route($prefix.'.material-masters.data'))->assertOk();
        $this->getJson(route($prefix.'.hs-code-rules.data'))->assertOk();

        $this->post(route($prefix.'.material-masters.store'), $this->materialPayload())
            ->assertRedirect(route($prefix.'.material-hs-code.index').'#materials');
        $material = MaterialMaster::where('normalized_code', 'M06A-MATERIAL')->sole();
        $this->put(route($prefix.'.material-masters.update', $material), $this->materialPayload(['raw_category' => 'Changed']))
            ->assertRedirect(route($prefix.'.material-hs-code.index').'#materials');
        $this->patchJson(route($prefix.'.material-masters.status', $material), ['is_active' => false])->assertOk();
        $this->deleteJson(route($prefix.'.material-masters.destroy', $material))->assertOk()->assertJsonPath('success', true);
        $this->assertModelMissing($material);

        $this->post(route($prefix.'.hs-code-rules.store'), $this->rulePayload())->assertSessionHasNoErrors();
        $rule = HsCodeRule::query()->latest('id')->firstOrFail(); // hs_code is normalized on save
        $this->deleteJson(route($prefix.'.hs-code-rules.destroy', $rule))->assertOk();
        $this->assertModelMissing($rule);

        $this->assertSame(
            ['created', 'updated', 'status_changed', 'deleted'],
            MasterDataAuditLog::where('entity_type', 'material_master')->where('entity_id', $material->id)->orderBy('id')->pluck('action')->all()
        );
        $deleted = MasterDataAuditLog::where('entity_type', 'material_master')->where('entity_id', $material->id)->where('action', 'deleted')->sole();
        $this->assertSame('M06A-MATERIAL', $deleted->before['normalized_code']);
        $this->assertSame($actor->id, $deleted->actor_id);
        $this->assertSame($role, $deleted->actor_role);
        $this->assertSame(['created', 'deleted'], MasterDataAuditLog::where('entity_type', 'hs_code_rule')->orderBy('id')->pluck('action')->all());
    }

    public static function managers(): array
    {
        return ['admin' => ['admin', 'admin'], 'purchasing' => ['purchasing', 'purchasing.master-data']];
    }

    public function test_purchasing_gains_no_other_admin_area_and_other_roles_are_denied(): void
    {
        foreach (['admin.users.index', 'admin.exchange-rates.index', 'admin.announcements.index', 'admin.dashboard', 'admin.material-hs-code.index'] as $route) {
            $this->actingAs($this->purchasing)->get(route($route))->assertForbidden();
        }

        $material = $this->material();
        foreach (['supplier', 'qc', 'finance'] as $role) {
            $user = User::factory()->create(['role' => $role, 'is_active' => true]);
            $this->actingAs($user)->get(route('purchasing.master-data.material-hs-code.index'))->assertForbidden();
            $this->actingAs($user)->deleteJson(route('purchasing.master-data.material-masters.destroy', $material))->assertForbidden();
            $this->actingAs($user)->deleteJson(route('admin.material-masters.destroy', $material))->assertForbidden();
        }
        $this->assertModelExists($material);
        $this->assertSame(0, MasterDataAuditLog::count());
    }

    public function test_a_referenced_material_is_refused_with_counts_audited_and_kept(): void
    {
        $material = $this->material();
        $pr = $this->createRequisition();
        $pr->items[0]->forceFill(['material_master_id' => $material->id])->save();
        $material->aliases()->create(['alias' => 'M06A ALIAS', 'normalized_alias' => 'M06A ALIAS']);
        MaterialReferencePrice::create([
            'material_master_id' => $material->id, 'shape' => 'Flat', 'dimension_field' => 'thickness',
            'dimension_min' => 1, 'dimension_max' => 5, 'currency' => 'USD', 'price_per_kg' => 2,
            'valid_from' => now()->toDateString(), 'status' => 'active', 'version_no' => 1, 'created_by' => $this->purchasing->id,
        ]);

        $this->actingAs($this->purchasing)
            ->deleteJson(route('purchasing.master-data.material-masters.destroy', $material))
            ->assertUnprocessable()
            ->assertJsonPath('counts', ['pr_items' => 1, 'aliases' => 1, 'reference_prices' => 1])
            ->assertJsonPath('message', __('materials.master_data.delete_refused_material', ['pr_items' => 1, 'aliases' => 1, 'reference_prices' => 1]));

        $this->assertModelExists($material);
        $refused = MasterDataAuditLog::where('action', 'delete_refused')->sole();
        $this->assertSame('referenced', $refused->after['reason']);
        $this->assertSame($this->purchasing->id, $refused->actor_id);
    }

    public function test_a_referenced_hs_rule_is_refused(): void
    {
        $rule = $this->rule();
        $pr = $this->createRequisition();
        $pr->items[0]->forceFill(['hs_code_rule_id' => $rule->id])->save();

        $this->actingAs($this->admin)->deleteJson(route('admin.hs-code-rules.destroy', $rule))
            ->assertUnprocessable()->assertJsonPath('counts.pr_items', 1);
        $this->assertModelExists($rule);
        $this->assertSame(1, MasterDataAuditLog::where('action', 'delete_refused')->count());
    }

    public function test_a_failing_deleted_audit_rolls_the_delete_back(): void
    {
        $material = $this->material();
        MasterDataAuditLog::creating(function (MasterDataAuditLog $log): void {
            if ($log->action === MasterDataAuditLog::DELETED) {
                throw new RuntimeException('audit storage unavailable');
            }
        });

        try {
            app(MasterDataDeletionService::class)->delete($material, $this->admin);
            $this->fail('The audit failure must abort the delete.');
        } catch (RuntimeException) {
            // expected
        }
        $this->assertModelExists($material);
    }

    public function test_the_fk_backstop_refusal_is_audited_and_the_row_is_kept(): void
    {
        $material = $this->material();
        $pr = $this->createRequisition();
        $pr->items[0]->forceFill(['material_master_id' => $material->id])->save();

        // Simulates a child committed between the reference check and DELETE.
        $service = new class extends MasterDataDeletionService
        {
            protected function referenceCounts(Model $entity): array
            {
                return ['pr_items' => 0, 'aliases' => 0, 'reference_prices' => 0];
            }
        };
        $outcome = $service->delete($material, $this->admin);

        $this->assertFalse($outcome->deleted);
        $this->assertSame('fk_restrict', $outcome->reason);
        $this->assertModelExists($material);
        $this->assertNotNull($pr->items[0]->fresh());
        $this->assertSame('fk_restrict', MasterDataAuditLog::where('action', 'delete_refused')->sole()->after['reason']);
    }

    private function material(): MaterialMaster
    {
        $code = 'M06A-FIXTURE-'.strtoupper(uniqid());

        return MaterialMaster::create([
            'material_code' => $code, 'normalized_code' => $code,
            'raw_category' => 'Fixture', 'hs_category' => 'alloy_steel', 'density_profile' => 'steel',
            'manufacturer_scope' => 'unknown', 'is_active' => true,
        ]);
    }

    private function rule(): HsCodeRule
    {
        return HsCodeRule::create([
            'rule_key' => 'm06a-'.uniqid(), 'hs_code' => '9997.77.77', 'material_category' => 'alloy_steel', 'shape' => 'Round',
            'conditions' => ['d_outer' => ['min' => 1, 'min_inclusive' => true, 'max' => 9, 'max_inclusive' => true]],
            'priority' => 100, 'status' => HsCodeRule::STATUS_INACTIVE, 'source_refs' => [],
        ]);
    }

    private function materialPayload(array $overrides = []): array
    {
        return array_replace([
            'material_code' => 'M06A-MATERIAL', 'raw_category' => 'Fixture', 'hs_category' => 'alloy_steel',
            'density_profile' => 'steel', 'manufacturer_scope' => 'unknown', 'is_active' => 1, 'form_context' => 'material',
        ], $overrides);
    }

    private function rulePayload(): array
    {
        return [
            'hs_code' => '99999999', 'material_category' => 'alloy_steel', 'shape' => 'Round',
            'conditions_json' => json_encode(['d_outer' => ['min' => 10, 'min_inclusive' => true, 'max' => 165, 'max_inclusive' => true]], JSON_THROW_ON_ERROR),
            'priority' => 100, 'status' => 'inactive', 'notes' => 'M06A', 'form_context' => 'rule',
        ];
    }
}
