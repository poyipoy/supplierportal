<?php

namespace Tests\Feature\Purchasing;

use App\Models\MasterDataAuditLog;
use App\Models\MaterialMaster;
use App\Models\Period;
use App\Models\PrItem;
use App\Models\PurchaseRequisition;
use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaterialTotalsTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasing;

    private MaterialMaster $steelMaterial;

    private MaterialMaster $aluminiumMaterial;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasing = User::factory()->create([
            'role' => 'purchasing',
            'is_active' => true,
            'account_status' => User::ACCOUNT_STATUS_ACTIVE,
        ]);

        $this->steelMaterial = MaterialMaster::create([
            'material_code' => 'SKD11',
            'normalized_code' => 'SKD11',
            'hs_category' => 'alloy_steel',
            'density_profile' => MaterialMaster::DENSITY_STEEL,
            'is_active' => true,
        ]);

        $this->aluminiumMaterial = MaterialMaster::create([
            'material_code' => 'A6061',
            'normalized_code' => 'A6061',
            'hs_category' => 'aluminium',
            'density_profile' => MaterialMaster::DENSITY_ALUMINIUM,
            'is_active' => true,
        ]);
    }

    public function test_calculates_flat_steel_aluminium_round_hollow_and_manual_weights_without_database_writes(): void
    {
        $countsBefore = $this->dbCounts();

        $response = $this->actingAs($this->purchasing)
            ->postJson(route('purchasing.material-calculations.totals'), [
                'items' => [
                    [
                        'index' => 0,
                        'material_master_id' => $this->steelMaterial->id,
                        'shape' => PrItem::SHAPE_FLAT,
                        'quantity' => 2,
                        'thickness' => 10,
                        'width' => 100,
                        'length' => 1000,
                    ],
                    [
                        'index' => 1,
                        'material_master_id' => $this->aluminiumMaterial->id,
                        'shape' => PrItem::SHAPE_FLAT,
                        'quantity' => 3,
                        'thickness' => 10,
                        'width' => 100,
                        'length' => 1000,
                    ],
                    [
                        'index' => 2,
                        'material_master_id' => $this->steelMaterial->id,
                        'shape' => PrItem::SHAPE_ROUND,
                        'quantity' => 4,
                        'd_outer' => 50,
                        'length' => 1000,
                    ],
                    [
                        'index' => 3,
                        'material_master_id' => $this->steelMaterial->id,
                        'shape' => PrItem::SHAPE_HOLLOW,
                        'quantity' => 2,
                        'd_outer' => 60,
                        'd_inner' => 40,
                        'length' => 1000,
                    ],
                    [
                        'index' => 4,
                        'material_master_id' => $this->steelMaterial->id,
                        'shape' => PrItem::SHAPE_FLAT,
                        'quantity' => 5,
                        'weight_needed' => '12.3456',
                        'weight_manual_override' => '1',
                    ],
                ],
            ])
            ->assertOk();

        $this->assertSame($countsBefore, $this->dbCounts());

        // Row 0: Flat steel: 10 * 100 * 1000 * 0.00785 / 1000 = 7.8500 * 2 = 15.7000
        $response->assertJsonPath('rows.0.unit_kg', '7.8500');
        $response->assertJsonPath('rows.0.row_total_kg', '15.7000');
        $response->assertJsonPath('rows.0.counted_in_kg', true);

        // Row 1: Flat aluminium: 10 * 100 * 1000 * 0.00273 / 1000 = 2.7300 * 3 = 8.1900
        $response->assertJsonPath('rows.1.unit_kg', '2.7300');
        $response->assertJsonPath('rows.1.row_total_kg', '8.1900');
        $response->assertJsonPath('rows.1.counted_in_kg', true);

        // Row 2: Round: 50 * 50 * 1000 * 0.006167 / 1000 = 15.4175 * 4 = 61.6700
        $response->assertJsonPath('rows.2.unit_kg', '15.4175');
        $response->assertJsonPath('rows.2.row_total_kg', '61.6700');
        $response->assertJsonPath('rows.2.counted_in_kg', true);

        // Row 3: Hollow: (3600 - 1600) * 1000 * 0.006167 / 1000 = 12.3340 * 2 = 24.6680
        $response->assertJsonPath('rows.3.unit_kg', '12.3340');
        $response->assertJsonPath('rows.3.row_total_kg', '24.6680');
        $response->assertJsonPath('rows.3.counted_in_kg', true);

        // Row 4: Manual override: 12.3456 * 5 = 61.7280
        $response->assertJsonPath('rows.4.unit_kg', '12.3456');
        $response->assertJsonPath('rows.4.row_total_kg', '61.7280');
        $response->assertJsonPath('rows.4.weight_status', PrItem::WEIGHT_STATUS_MANUAL);
        $response->assertJsonPath('rows.4.counted_in_kg', true);

        // Total Qty: 2 + 3 + 4 + 2 + 5 = 16
        // Total KG: 15.7000 + 8.1900 + 61.6700 + 24.6680 + 61.7280 = 171.9560
        $response->assertJsonPath('totals.total_quantity', 16);
        $response->assertJsonPath('totals.total_kg', '171.9560');
        $response->assertJsonPath('totals.row_count', 5);
        $response->assertJsonPath('totals.complete_row_count', 5);
    }

    public function test_incomplete_rows_count_valid_quantity_but_contribute_zero_to_total_kg(): void
    {
        $response = $this->actingAs($this->purchasing)
            ->postJson(route('purchasing.material-calculations.totals'), [
                'items' => [
                    [
                        'index' => 0,
                        'material_master_id' => '',
                        'shape' => '',
                        'quantity' => '10',
                    ],
                    [
                        'index' => 1,
                        'material_master_id' => $this->steelMaterial->id,
                        'shape' => PrItem::SHAPE_FLAT,
                        'quantity' => '4',
                        'thickness' => '10',
                        'width' => '',
                        'length' => '1000',
                    ],
                    [
                        'index' => 2,
                        'material_master_id' => $this->steelMaterial->id,
                        'shape' => PrItem::SHAPE_FLAT,
                        'quantity' => '0',
                        'thickness' => '10',
                        'width' => '100',
                        'length' => '1000',
                    ],
                ],
            ])
            ->assertOk();

        $response->assertJsonPath('rows.0.quantity', 10);
        $response->assertJsonPath('rows.0.row_total_kg', null);
        $response->assertJsonPath('rows.0.counted_in_kg', false);

        $response->assertJsonPath('rows.1.quantity', 4);
        $response->assertJsonPath('rows.1.row_total_kg', null);
        $response->assertJsonPath('rows.1.counted_in_kg', false);

        $response->assertJsonPath('rows.2.quantity', null);
        $response->assertJsonPath('rows.2.row_total_kg', null);
        $response->assertJsonPath('rows.2.counted_in_kg', false);

        $response->assertJsonPath('totals.total_quantity', 14);
        $response->assertJsonPath('totals.total_kg', '0.0000');
        $response->assertJsonPath('totals.complete_row_count', 0);
    }

    public function test_regional_number_formatting_honors_user_preference(): void
    {
        UserPreference::forceCreate([
            ...config('user_preferences.defaults'),
            'user_id' => $this->purchasing->id,
            'number_format' => 'indonesian',
        ]);

        $response = $this->actingAs($this->purchasing)
            ->postJson(route('purchasing.material-calculations.totals'), [
                'items' => [
                    [
                        'index' => 0,
                        'material_master_id' => $this->steelMaterial->id,
                        'shape' => PrItem::SHAPE_FLAT,
                        'quantity' => 1200,
                        'weight_needed' => '10.5000',
                        'weight_manual_override' => '1',
                    ],
                ],
            ])
            ->assertOk();

        $response->assertJsonPath('rows.0.row_total_kg', '12600.0000');
        $response->assertJsonPath('rows.0.row_total_kg_display', '12.600,0000');
        $response->assertJsonPath('totals.total_quantity_display', '1.200');
        $response->assertJsonPath('totals.total_kg_display', '12.600,0000');
    }

    public function test_non_purchasing_roles_are_forbidden_and_over_limit_rows_return_422(): void
    {
        $this->postJson(route('purchasing.material-calculations.totals'), ['items' => []])
            ->assertUnauthorized();

        foreach (['supplier', 'finance', 'qc'] as $role) {
            $user = User::factory()->create([
                'role' => $role,
                'is_active' => true,
                'account_status' => User::ACCOUNT_STATUS_ACTIVE,
            ]);

            $this->actingAs($user)
                ->postJson(route('purchasing.material-calculations.totals'), ['items' => []])
                ->assertForbidden();
        }

        $tooMany = array_fill(0, 501, ['quantity' => 1]);
        $this->actingAs($this->purchasing)
            ->postJson(route('purchasing.material-calculations.totals'), ['items' => $tooMany])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items']);
    }

    public function test_create_and_edit_pr_pages_render_aligned_colgroup_and_external_totals_note(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $period = Period::create([
            'name' => 'Totals Layout Period',
            'month' => 10,
            'year' => 2026,
            'status' => 'open',
            'created_by' => $admin->id,
        ]);
        $pr = PurchaseRequisition::create([
            'period_id' => $period->id,
            'created_by' => $this->purchasing->id,
            'pr_number' => 'REQ/10/2026/901',
            'status' => 'draft',
        ]);
        PrItem::create([
            'pr_id' => $pr->id,
            'material_master_id' => $this->steelMaterial->id,
            'hs_code' => '7228.30.90',
            'material_name' => 'SKD11',
            'shape' => PrItem::SHAPE_FLAT,
            'quantity' => 3,
            'thickness' => 10,
            'width' => 100,
            'length' => 1000,
            'weight_needed' => 7.85,
        ]);

        foreach ([
            route('purchasing.requisitions.create'),
            route('purchasing.requisitions.edit', $pr),
        ] as $url) {
            $html = $this->actingAs($this->purchasing)->get($url)->assertOk()->getContent();
            $dom = new \DOMDocument;
            libxml_use_internal_errors(true);
            $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
            libxml_clear_errors();
            $xpath = new \DOMXPath($dom);

            $cols = $xpath->query('//table[@id="itemsTable"]/colgroup/col')->length;
            $ths = $xpath->query('//table[@id="itemsTable"]/thead/tr/th')->length;
            $this->assertSame(14, $cols);
            $this->assertSame($ths, $cols);

            $noteInTfoot = $xpath->query('//tfoot[@id="prFormTotals"]//*[@id="prFormTotalsStatus"]')->length;
            $noteOutside = $xpath->query('//p[@id="prFormTotalsStatus" and @role="note"]')->length;
            $this->assertSame(0, $noteInTfoot);
            $this->assertSame(1, $noteOutside);
        }
    }

    /**
     * @return array<string, int>
     */
    private function dbCounts(): array
    {
        return [
            'materials' => MaterialMaster::count(),
            'prs' => PurchaseRequisition::count(),
            'pr_items' => PrItem::count(),
            'audit_logs' => MasterDataAuditLog::count(),
        ];
    }
}
