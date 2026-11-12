<?php

namespace Tests\Feature\Purchasing;

use App\Exports\PrFormExport;
use App\Models\MaterialMaster;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrFormExportTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasingUser;

    private User $supplierUser;

    private MaterialMaster $materialMaster;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasingUser = User::factory()->create([
            'role' => 'purchasing',
            'is_active' => true,
        ]);

        $this->supplierUser = User::factory()->create([
            'role' => 'supplier',
            'is_active' => true,
        ]);

        $this->materialMaster = MaterialMaster::create([
            'material_code' => 'SKD11',
            'normalized_code' => 'SKD11',
            'hs_category' => 'alloy_steel',
            'density_profile' => MaterialMaster::DENSITY_STEEL,
            'is_active' => true,
        ]);
    }

    public function test_guest_cannot_export_pr_form(): void
    {
        $response = $this->post(route('purchasing.requisitions.export-form'), [
            'items' => [
                ['material_name' => 'SKD11', 'quantity' => 5],
            ],
        ]);

        $response->assertRedirect(route('login'));
    }

    public function test_supplier_role_cannot_export_purchasing_pr_form(): void
    {
        $response = $this->actingAs($this->supplierUser)
            ->post(route('purchasing.requisitions.export-form'), [
                'items' => [
                    ['material_name' => 'SKD11', 'quantity' => 5],
                ],
            ]);

        $response->assertForbidden();
    }

    public function test_purchasing_export_requires_at_least_one_non_empty_item(): void
    {
        $response = $this->actingAs($this->purchasingUser)
            ->postJson(route('purchasing.requisitions.export-form'), [
                'items' => [
                    ['material_name' => '', 'quantity' => null],
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['items']);
    }

    public function test_purchasing_user_can_export_entered_materials(): void
    {
        $payload = [
            'pr_number' => 'PR/2026/XI/0042',
            'items' => [
                [
                    'material_master_id' => $this->materialMaster->id,
                    'material_name' => 'SKD11',
                    'shape' => 'Round',
                    'quantity' => 10,
                    'd_outer' => 50,
                    'length' => 1200,
                    'remark' => 'Custom length for testing',
                ],
            ],
        ];

        $response = $this->actingAs($this->purchasingUser)
            ->post(route('purchasing.requisitions.export-form'), $payload);

        $response->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString(
            'template_import_pr_PR_2026_XI_0042.xlsx',
            (string) $response->headers->get('Content-Disposition')
        );
    }

    public function test_export_resolves_material_name_from_master_id_when_name_missing(): void
    {
        $export = new PrFormExport([
            [
                'material_master_id' => $this->materialMaster->id,
                'material_name' => null,
                'shape' => 'Round',
                'quantity' => 3,
                'd_outer' => 25,
                'length' => 6000,
            ],
        ]);

        $rows = $export->array();
        $this->assertCount(1, $rows);
        $this->assertSame('SKD11', $rows[0][0]); // material_name
        $this->assertSame('Round', $rows[0][1]); // shape
        $this->assertSame(25.0, $rows[0][4]);    // d_outer
        $this->assertSame(6000.0, $rows[0][6]);  // length
        $this->assertSame(3, $rows[0][7]);       // quantity
    }

    public function test_export_sanitizes_formula_injection_attempts(): void
    {
        $export = new PrFormExport([
            [
                'material_name' => '=SUM(1,2)',
                'shape' => 'Round',
                'quantity' => 1,
                'remark' => '@malicious_payload',
            ],
        ]);

        $rows = $export->array();
        $this->assertCount(1, $rows);
        $this->assertSame("'=SUM(1,2)", $rows[0][0]);
        $this->assertSame("'@malicious_payload", $rows[0][8]);
    }
}
