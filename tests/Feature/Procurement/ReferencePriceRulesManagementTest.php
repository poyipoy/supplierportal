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

    public function test_edit_category_by_rule_id_updates_rule_and_preserves_untouched_rows_metadata(): void
    {
        $target = ReferencePriceRule::where('category_key', '601-700')->firstOrFail();
        $untouched = ReferencePriceRule::where('category_key', '1-15')->firstOrFail();
        $untouched->update([
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->purchasing)
            ->post(route('purchasing.master-data.reference-prices.rules.category.save'), [
                'form_mode' => 'edit',
                'rule_id' => $target->id,
                'category_key' => '601-720',
                'category_name' => 'Heavy Plate 601-720',
                'dimension_min' => 601,
                'dimension_max' => 720,
            ]);

        $response->assertRedirect(route('purchasing.master-data.reference-prices.index', ['tab' => 'rules']))
            ->assertSessionHas('success');

        $target->refresh();
        $untouched->refresh();

        $this->assertSame('601-720', $target->category_key);
        $this->assertSame('Heavy Plate 601-720', $target->category_name);
        $this->assertSame(601, $target->dimension_min);
        $this->assertSame(720, $target->dimension_max);
        $this->assertSame($this->purchasing->id, $target->updated_by);
        $this->assertSame($this->admin->id, $untouched->created_by);
        $this->assertSame($this->admin->id, $untouched->updated_by);
    }

    public function test_add_category_mode_rejects_duplicate_category_key_without_overwriting(): void
    {
        $existing = ReferencePriceRule::where('category_key', '601-700')->firstOrFail();

        $response = $this->actingAs($this->purchasing)
            ->from(route('purchasing.master-data.reference-prices.index', ['tab' => 'rules']))
            ->post(route('purchasing.master-data.reference-prices.rules.category.save'), [
                'form_mode' => 'create',
                'category_key' => '601-700',
                'category_name' => 'Duplicate Override Attempt',
                'dimension_min' => 701,
                'dimension_max' => 800,
            ]);

        $response->assertRedirect(route('purchasing.master-data.reference-prices.index', ['tab' => 'rules']))
            ->assertSessionHas('error');

        $existing->refresh();
        $this->assertSame('601-700', $existing->category_name);
        $this->assertSame(601, $existing->dimension_min);
        $this->assertSame(700, $existing->dimension_max);
        $this->assertSame('create', session()->getOldInput('form_mode'));
        $this->assertSame('601-700', session()->getOldInput('category_key'));
    }

    public function test_edit_and_add_overlapping_ranges_redirect_to_rules_tab_with_error_and_input(): void
    {
        $first = ReferencePriceRule::where('category_key', '1-15')->firstOrFail();

        $editResponse = $this->actingAs($this->purchasing)
            ->post(route('purchasing.master-data.reference-prices.rules.category.save'), [
                'form_mode' => 'edit',
                'rule_id' => $first->id,
                'category_key' => '1-15',
                'category_name' => '1-20 Overlap',
                'dimension_min' => 1,
                'dimension_max' => 20,
            ]);

        $editResponse->assertRedirect(route('purchasing.master-data.reference-prices.index', ['tab' => 'rules']))
            ->assertSessionHas('error');

        $this->assertSame(15, $first->fresh()->dimension_max);
        $this->assertSame('edit', session()->getOldInput('form_mode'));
        $this->assertSame('20', (string) session()->getOldInput('dimension_max'));

        $addResponse = $this->actingAs($this->purchasing)
            ->post(route('purchasing.master-data.reference-prices.rules.category.save'), [
                'form_mode' => 'create',
                'category_key' => '650-750',
                'category_name' => '650-750',
                'dimension_min' => 650,
                'dimension_max' => 750,
            ]);

        $addResponse->assertRedirect(route('purchasing.master-data.reference-prices.index', ['tab' => 'rules']))
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('reference_price_rules', ['category_key' => '650-750']);
        $this->assertSame('create', session()->getOldInput('form_mode'));
        $this->assertSame('650-750', session()->getOldInput('category_key'));
    }

    public function test_toggle_inactive_to_active_rejects_overlap_and_allows_non_overlapping_reactivation(): void
    {
        $first = ReferencePriceRule::where('category_key', '1-15')->firstOrFail();
        $second = ReferencePriceRule::where('category_key', '16-50')->firstOrFail();

        $this->actingAs($this->purchasing)
            ->patch(route('purchasing.master-data.reference-prices.rules.category.toggle', $second))
            ->assertRedirect(route('purchasing.master-data.reference-prices.index', ['tab' => 'rules']))
            ->assertSessionHas('success');
        $this->assertSame(ReferencePriceRule::STATUS_INACTIVE, $second->fresh()->status);

        $this->actingAs($this->purchasing)
            ->post(route('purchasing.master-data.reference-prices.rules.category.save'), [
                'form_mode' => 'edit',
                'rule_id' => $first->id,
                'category_key' => '1-15',
                'category_name' => '1-20',
                'dimension_min' => 1,
                'dimension_max' => 20,
            ])
            ->assertRedirect(route('purchasing.master-data.reference-prices.index', ['tab' => 'rules']))
            ->assertSessionHas('success');

        $this->actingAs($this->purchasing)
            ->patch(route('purchasing.master-data.reference-prices.rules.category.toggle', $second))
            ->assertRedirect(route('purchasing.master-data.reference-prices.index', ['tab' => 'rules']))
            ->assertSessionHas('error');
        $this->assertSame(ReferencePriceRule::STATUS_INACTIVE, $second->fresh()->status);

        $this->actingAs($this->purchasing)
            ->post(route('purchasing.master-data.reference-prices.rules.category.save'), [
                'form_mode' => 'edit',
                'rule_id' => $second->id,
                'category_key' => '21-50',
                'category_name' => '21-50',
                'dimension_min' => 21,
                'dimension_max' => 50,
            ])
            ->assertRedirect(route('purchasing.master-data.reference-prices.index', ['tab' => 'rules']))
            ->assertSessionHas('success');

        $this->actingAs($this->purchasing)
            ->patch(route('purchasing.master-data.reference-prices.rules.category.toggle', $second))
            ->assertRedirect(route('purchasing.master-data.reference-prices.index', ['tab' => 'rules']))
            ->assertSessionHas('success');
        $this->assertSame(ReferencePriceRule::STATUS_ACTIVE, $second->fresh()->status);
    }
}
