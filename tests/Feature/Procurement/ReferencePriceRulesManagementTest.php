<?php

namespace Tests\Feature\Procurement;

use App\Models\ReferencePriceImport;
use App\Models\ReferencePriceRule;
use App\Models\User;
use Database\Seeders\ReferencePriceRulesInitialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ReferencePriceRulesManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $purchasing;

    private User $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);
        $this->purchasing = User::factory()->create([
            'role' => 'purchasing',
            'is_active' => true,
        ]);
        $this->supplier = User::factory()->create([
            'role' => 'supplier',
            'is_active' => true,
        ]);

        $this->seed(ReferencePriceRulesInitialSeeder::class);
    }

    public function test_purchasing_and_admin_can_view_rules_page(): void
    {
        $this->actingAs($this->purchasing)
            ->get(route('purchasing.master-data.reference-prices.index'))
            ->assertOk()
            ->assertSee('1-15')
            ->assertSee('601-700');

        $this->actingAs($this->admin)
            ->get(route('admin.reference-prices.index'))
            ->assertOk()
            ->assertSee('1-15')
            ->assertSee('601-700');
    }

    public function test_supplier_is_denied_access(): void
    {
        $this->actingAs($this->supplier)
            ->get(route('purchasing.master-data.reference-prices.index'))
            ->assertForbidden();

        $this->actingAs($this->supplier)
            ->get(route('admin.reference-prices.index'))
            ->assertForbidden();
    }

    public function test_server_tabs_request_returns_partial_json(): void
    {
        $response = $this->actingAs($this->purchasing)
            ->get(route('purchasing.master-data.reference-prices.index', ['tab' => 'rules']), [
                'X-Adasi-Server-Tabs' => '1',
                'Accept' => 'application/json',
            ]);

        $response->assertOk()
            ->assertJsonStructure(['html', 'nav']);
    }

    public function test_import_preview_with_valid_fixture_returns_preview_data(): void
    {
        $path = base_path('tests/Fixtures/Kategori Range.xlsx');
        $file = new UploadedFile($path, 'Kategori Range.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $response = $this->actingAs($this->purchasing)
            ->post(route('purchasing.master-data.reference-prices.rules.import-preview'), [
                'file' => $file,
                'import_mode' => ReferencePriceImport::MODE_REPLACE,
                'sheet_name' => 'Kategori',
            ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('preview.valid', true);

        $this->assertDatabaseHas('reference_price_imports', [
            'source_file_name' => 'Kategori Range.xlsx',
            'import_mode' => ReferencePriceImport::MODE_REPLACE,
            'status' => ReferencePriceImport::STATUS_PREVIEWED,
            'parsed_count' => 11,
        ]);
    }

    public function test_import_confirm_updates_rules_directly(): void
    {
        $path = base_path('tests/Fixtures/Kategori Range.xlsx');
        $file = new UploadedFile($path, 'Kategori Range.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $previewResp = $this->actingAs($this->purchasing)
            ->post(route('purchasing.master-data.reference-prices.rules.import-preview'), [
                'file' => $file,
                'import_mode' => ReferencePriceImport::MODE_REPLACE,
                'sheet_name' => 'Kategori',
            ], ['Accept' => 'application/json']);

        $importId = $previewResp->json('import_id');

        $confirmResp = $this->actingAs($this->purchasing)
            ->post(route('purchasing.master-data.reference-prices.rules.import-confirm'), [
                'import_id' => $importId,
            ], ['Accept' => 'application/json']);

        $confirmResp->assertOk()
            ->assertJsonPath('success', true);

        $this->assertEquals(11, ReferencePriceRule::count());
        $this->assertDatabaseHas('reference_price_rules', [
            'category_key' => '1-15',
            'status' => ReferencePriceRule::STATUS_ACTIVE,
        ]);
    }

    public function test_manual_category_addition_saves_rule_directly(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('admin.reference-prices.rules.category.save'), [
                'category_key' => '701-800',
                'category_name' => '701-800',
                'dimension_min' => 701,
                'dimension_max' => 800,
            ]);

        $response->assertRedirect(route('admin.reference-prices.index', ['tab' => 'rules']));

        $this->assertDatabaseHas('reference_price_rules', [
            'category_key' => '701-800',
            'dimension_min' => 701,
            'dimension_max' => 800,
            'status' => ReferencePriceRule::STATUS_ACTIVE,
        ]);
        $this->assertEquals(12, ReferencePriceRule::count());
    }

    public function test_toggle_category_status_updates_rule_directly(): void
    {
        $firstRule = ReferencePriceRule::firstOrFail();

        $response = $this->actingAs($this->purchasing)
            ->patch(route('purchasing.master-data.reference-prices.rules.category.toggle', $firstRule));

        $response->assertRedirect(route('purchasing.master-data.reference-prices.index', ['tab' => 'rules']));

        $this->assertEquals(ReferencePriceRule::STATUS_INACTIVE, $firstRule->fresh()->status);
    }
}
