<?php

namespace Tests\Feature;

use App\Exports\QuotationsExport;
use App\Models\ExportJob;
use App\Models\Period;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Export\ExportOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdvancedExportUiTest extends TestCase
{
    use RefreshDatabase;

    public static function audiences(): array
    {
        return ['purchasing-en' => ['purchasing', 'en'], 'purchasing-id' => ['purchasing', 'id'], 'supplier-en' => ['supplier', 'en'], 'supplier-id' => ['supplier', 'id']];
    }

    #[DataProvider('audiences')]
    public function test_po_modal_renders_accessible_structure_calendar_and_localized_copy(string $role, string $locale): void
    {
        $actor = User::factory()->create(['role' => $role]);
        $actor->preference()->create([...config('user_preferences.defaults'), 'locale' => $locale]);
        $this->actingAs($actor)->get(route($role.'.purchase-orders.index'))->assertOk()
            ->assertSeeText($locale === 'en' ? 'Advanced Export' : 'Ekspor Lanjutan')
            ->assertSee('data-advanced-export-form', false)->assertSee('data-export-quick', false)
            ->assertSee('aria-modal="true"', false)->assertSee('aria-labelledby="advanced-export-'.$role.'-po-title"', false)
            ->assertSee('data-adasi-date-picker', false)->assertSee('advanced-export-'.$role.'-po-start_date', false)
            ->assertSee('ui-export-designer-modal', false)
            ->assertSee('role="listbox"', false)->assertSee('aria-orientation="horizontal"', false)
            ->assertSee('data-column-scroll', false)->assertSee('data-column-empty-rows', false)
            ->assertSee('data-column-menu', false)->assertSee('data-column-unhide', false)
            ->assertSee('data-column-command="move-left"', false)->assertSee('data-column-command="move-right"', false)
            ->assertDontSee('data-column-up', false)->assertDontSee('data-column-check', false);
    }

    #[DataProvider('audiences')]
    public function test_modal_catalog_preset_and_post_request_produce_202_with_selected_options(string $role, string $locale): void
    {
        Queue::fake();
        $actor = User::factory()->create(['role' => $role]);
        $actor->preference()->create([...config('user_preferences.defaults'), 'locale' => $locale]);
        // 10 original columns + 2 opt-in logistics columns (Mission 04-W, Q7).
        $this->actingAs($actor)->getJson(route('exports.definitions.show', $role.'.po'))->assertOk()->assertJsonCount(12, 'columns');
        $preset = $this->postJson(route('export-presets.store'), ['export_key' => $role.'.po', 'name' => 'UI preset', 'columns' => ['remark', 'po_number'], 'format' => 'csv', 'filters' => ['status' => 'active'], 'is_default' => true])->assertCreated()->json();
        $this->getJson(route('export-presets.index', ['export_key' => $role.'.po']))->assertOk()->assertJsonPath('data.0.is_default', true);
        $this->postJson(route($role.'.export.purchase-orders'), [...$preset['filters'], 'options' => ['columns' => $preset['columns'], 'format' => $preset['format']]])->assertAccepted();
        $job = ExportJob::sole();
        $this->assertSame(['remark', 'po_number'], $job->export_options['columns']);
        $this->assertSame($role, $job->export_options['audience']);
        $this->assertSame('csv', $job->format);
    }

    public function test_shipment_modal_unifies_search_with_accessible_hint_and_preserves_legacy_preset_compatibility(): void
    {
        Queue::fake();
        $actor = User::factory()->create(['role' => 'purchasing']);

        foreach (['en', 'id'] as $locale) {
            $actor->preference()->updateOrCreate(['user_id' => $actor->id], [...config('user_preferences.defaults'), 'locale' => $locale]);

            $expectedPlaceholder = __('exports.advanced.filters.placeholders.shipment_search', [], $locale);
            $expectedHint = __('exports.advanced.filters.hints.shipment_search', [], $locale);

            $this->actingAs($actor)->get(route('purchasing.shipments.index'))
                ->assertOk()
                ->assertSee('data-filter-name="search"', false)
                ->assertDontSee('data-filter-name="shipment_number"', false)
                ->assertSee('placeholder="'.e($expectedPlaceholder).'"', false)
                ->assertSee('aria-describedby="advanced-export-purchasing-shipments-search-hint"', false)
                ->assertSee('id="advanced-export-purchasing-shipments-search-hint"', false)
                ->assertSeeText($expectedHint);

            $definition = $this->actingAs($actor)->getJson(route('exports.definitions.show', 'purchasing.shipments'))
                ->assertOk()
                ->json('filters');

            $filterNames = array_column($definition, 'name');
            $this->assertContains('search', $filterNames);
            $this->assertNotContains('shipment_number', $filterNames);

            $searchFilter = collect($definition)->firstWhere('name', 'search');
            $this->assertSame($expectedPlaceholder, $searchFilter['placeholder'] ?? null);
            $this->assertSame($expectedHint, $searchFilter['hint'] ?? null);
        }

        // Legacy preset compatibility: storing and presenting a preset with shipment_number
        $this->actingAs($actor)->postJson(route('export-presets.store'), [
            'export_key' => 'purchasing.shipments',
            'name' => 'Legacy Shipment Filter',
            'columns' => ['shipment_number'],
            'format' => 'xlsx',
            'filters' => ['shipment_number' => 'SHP/2026/001'],
        ])->assertCreated();

        $presented = $this->actingAs($actor)->getJson(route('export-presets.index', ['export_key' => 'purchasing.shipments']))
            ->assertOk()
            ->json('data.0.filters');

        $this->assertSame('SHP/2026/001', $presented['search'] ?? null);
        $this->assertArrayNotHasKey('shipment_number', $presented);

        // Direct export with legacy shipment_number filter still succeeds
        $this->actingAs($actor)->postJson(route('purchasing.export.shipments'), [
            'shipment_number' => 'SHP/2026/001',
            'options' => ['columns' => ['shipment_number'], 'format' => 'csv'],
        ])->assertAccepted();
    }

    public function test_po_and_quotation_modals_unify_search_with_audience_hints_relative_presets_and_legacy_compatibility(): void
    {
        Queue::fake();

        $cases = [
            ['purchasing', 'purchasing.po', 'purchasing.purchase-orders.index', 'purchasing.export.purchase-orders', 'po_number', 'PO/2026/001', 'po_search_purchasing'],
            ['supplier', 'supplier.po', 'supplier.purchase-orders.index', 'supplier.export.purchase-orders', 'po_number', 'PO/2026/002', 'po_search_supplier'],
            ['purchasing', 'purchasing.quotations', 'purchasing.quotations.index', 'purchasing.export.quotations', 'pr_number', 'REQ/2026/001', 'quotation_search_purchasing'],
            ['supplier', 'supplier.quotations', 'supplier.quotations.index', 'supplier.export.quotations', 'pr_number', 'REQ/2026/002', 'quotation_search_supplier'],
        ];

        foreach ($cases as [$role, $exportKey, $indexRoute, $exportRoute, $legacyKey, $legacyValue, $copyKey]) {
            $actor = User::factory()->create(['role' => $role]);
            $uid = 'advanced-export-'.str_replace('.', '-', $exportKey);

            foreach (['en', 'id'] as $locale) {
                $actor->preference()->updateOrCreate(['user_id' => $actor->id], [...config('user_preferences.defaults'), 'locale' => $locale]);

                $expectedPlaceholder = __('exports.advanced.filters.placeholders.'.$copyKey, [], $locale);
                $expectedHint = __('exports.advanced.filters.hints.'.$copyKey, [], $locale);

                $page = $this->actingAs($actor)->get(route($indexRoute))
                    ->assertOk()
                    ->assertSee('data-filter-name="search"', false)
                    ->assertDontSee('data-filter-name="'.$legacyKey.'"', false)
                    ->assertSee('placeholder="'.e($expectedPlaceholder).'"', false)
                    ->assertSee('aria-describedby="'.$uid.'-search-hint"', false)
                    ->assertSeeText($expectedHint);

                if (str_ends_with($exportKey, '.po')) {
                    $page->assertSee('data-export-relative-panel', false)
                        ->assertSee('aria-controls="'.$uid.'-relative-panel"', false)
                        ->assertSee('data-export-days-preset="7"', false)
                        ->assertSee('data-export-days-preset="30"', false)
                        ->assertSee('data-export-days-preset="90"', false)
                        ->assertSeeText(__('exports.advanced.relative_hint', [], $locale));
                }

                $definition = $this->actingAs($actor)->getJson(route('exports.definitions.show', $exportKey))
                    ->assertOk()
                    ->json('filters');

                $filterNames = array_column($definition, 'name');
                $this->assertContains('search', $filterNames);
                $this->assertNotContains($legacyKey, $filterNames);
                $searchFilter = collect($definition)->firstWhere('name', 'search');
                $this->assertSame($expectedPlaceholder, $searchFilter['placeholder'] ?? null);
                $this->assertSame($expectedHint, $searchFilter['hint'] ?? null);
            }

            // Legacy preset storing po_number / pr_number is accepted and presented under unified search.
            $requiredCol = $legacyKey;
            $this->actingAs($actor)->postJson(route('export-presets.store'), [
                'export_key' => $exportKey,
                'name' => 'Legacy '.$exportKey,
                'columns' => [$requiredCol],
                'format' => 'xlsx',
                'filters' => [$legacyKey => $legacyValue],
            ])->assertCreated();

            $presented = $this->actingAs($actor)->getJson(route('export-presets.index', ['export_key' => $exportKey]))
                ->assertOk()
                ->json('data.0.filters');

            $this->assertSame($legacyValue, $presented['search'] ?? null);
            $this->assertArrayNotHasKey($legacyKey, $presented);

            // Direct export with legacy filter param still works.
            $this->actingAs($actor)->postJson(route($exportRoute), [
                $legacyKey => $legacyValue,
                'options' => ['columns' => [$requiredCol], 'format' => 'csv'],
            ])->assertAccepted();
        }
    }

    public function test_requisitions_and_local_invoice_modals_render_search_placeholder_and_accessible_hint(): void
    {
        $purchasing = User::factory()->create(['role' => 'purchasing']);
        $finance = User::factory()->create(['role' => 'finance']);

        foreach (['en', 'id'] as $locale) {
            $purchasing->preference()->updateOrCreate(['user_id' => $purchasing->id], [...config('user_preferences.defaults'), 'locale' => $locale]);
            $this->actingAs($purchasing)->get(route('purchasing.requisitions.index'))
                ->assertOk()
                ->assertSee('placeholder="'.e(__('exports.advanced.filters.placeholders.pr_search', [], $locale)).'"', false)
                ->assertSee('aria-describedby="advanced-export-purchasing-pr-search-hint"', false)
                ->assertSeeText(__('exports.advanced.filters.hints.pr_search', [], $locale));

            $finance->preference()->updateOrCreate(['user_id' => $finance->id], [...config('user_preferences.defaults'), 'locale' => $locale]);
            $this->actingAs($finance)->get(route('finance.master-invoices'))
                ->assertOk()
                ->assertSee('placeholder="'.e(__('exports.advanced.filters.placeholders.local_invoice_search', [], $locale)).'"', false)
                ->assertSee('aria-describedby="advanced-export-finance-local-invoices-q-hint"', false)
                ->assertSeeText(__('exports.advanced.filters.hints.local_invoice_search', [], $locale));
        }
    }

    public function test_quotation_unified_search_matches_pr_period_material_and_purchasing_only_supplier(): void
    {
        $purchasing = User::factory()->create(['role' => 'purchasing']);
        $supplierA = User::factory()->create(['role' => 'supplier', 'name' => 'Alpha Metals User']);
        Supplier::create(['user_id' => $supplierA->id, 'company_name' => 'PT Alpha Baja Sejahtera']);
        $supplierB = User::factory()->create(['role' => 'supplier', 'name' => 'Beta Forge User']);
        Supplier::create(['user_id' => $supplierB->id, 'company_name' => 'PT Beta Industri']);

        $period = Period::create(['name' => 'Q4 Special Batch', 'month' => 11, 'year' => 2026, 'status' => 'open', 'created_by' => $purchasing->id]);
        $pr = PurchaseRequisition::create(['period_id' => $period->id, 'created_by' => $purchasing->id, 'pr_number' => 'REQ/11/2026/777', 'status' => 'bidding']);
        $itemA = $pr->items()->create(['hs_code' => '7209', 'material_name' => 'JIS G3141 SPCC-SD', 'quantity' => 5, 'shape' => 'Flat', 'weight_needed' => 50]);
        $itemB = $pr->items()->create(['hs_code' => '7210', 'material_name' => 'SUS304 Stainless', 'quantity' => 5, 'shape' => 'Flat', 'weight_needed' => 50]);

        $quoteA = Quotation::create(['pr_id' => $pr->id, 'supplier_id' => $supplierA->id, 'currency' => 'USD', 'status' => Quotation::STATUS_SUBMITTED, 'submitted_at' => '2026-11-10 08:00:00']);
        $quoteA->items()->create(['pr_item_id' => $itemA->id, 'price_per_kg' => 10, 'amount' => 500, 'is_available' => true]);

        $quoteB = Quotation::create(['pr_id' => $pr->id, 'supplier_id' => $supplierB->id, 'currency' => 'USD', 'status' => Quotation::STATUS_SUBMITTED, 'submitted_at' => '2026-11-10 08:00:00']);
        $quoteB->items()->create(['pr_item_id' => $itemB->id, 'price_per_kg' => 12, 'amount' => 600, 'is_available' => true]);

        // Purchasing matches by PR number, period name, material name, and supplier company name.
        foreach (['REQ/11/2026/777' => 2, 'Q4 Special Batch' => 2, 'SPCC-SD' => 1, 'Alpha Baja' => 1, 'Beta Forge' => 1] as $term => $expectedCount) {
            $export = new QuotationsExport(['search' => $term]);
            $export->applyOptions(new ExportOptions(['pr_number'], 'xlsx', 'purchasing'));
            $this->assertSame($expectedCount, $export->progressTotalRows(), "Purchasing quotation search '{$term}'");
        }

        // Supplier matches own PR / period / material, and cannot match foreign supplier or search supplier name.
        $ownMaterial = new QuotationsExport(['search' => 'SPCC-SD'], $supplierA->id, true);
        $ownMaterial->applyOptions(new ExportOptions(['pr_number'], 'xlsx', 'supplier'));
        $this->assertSame(1, $ownMaterial->progressTotalRows());

        $foreignSupplierTerm = new QuotationsExport(['search' => 'Beta Industri'], $supplierA->id, true);
        $foreignSupplierTerm->applyOptions(new ExportOptions(['pr_number'], 'xlsx', 'supplier'));
        $this->assertSame(0, $foreignSupplierTerm->progressTotalRows());
    }

    public function test_advanced_export_forms_declare_managed_submit_to_prevent_double_spinners(): void
    {
        $purchasing = User::factory()->create(['role' => 'purchasing']);

        $poContent = $this->actingAs($purchasing)
            ->get(route('purchasing.purchase-orders.index'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<form[^>]*data-advanced-export-form[^>]*data-managed-submit|<form[^>]*data-managed-submit[^>]*data-advanced-export-form/',
            $poContent,
            'Advanced Export modal form must declare data-managed-submit so global submit guard never injects a duplicate or stuck spinner'
        );

        $historicalBlade = file_get_contents(resource_path('views/supplier/price-history/historical.blade.php'));
        $this->assertIsString($historicalBlade);
        $this->assertMatchesRegularExpression(
            '/<form[^>]*data-format-export-form[^>]*data-managed-submit|<form[^>]*data-managed-submit[^>]*data-format-export-form/',
            $historicalBlade,
            'Format export form on historical price page must declare data-managed-submit'
        );
    }

    public function test_modals_render_searchable_comboboxes_for_search_supplier_and_period_filters(): void
    {
        $purchasing = User::factory()->create(['role' => 'purchasing']);
        $supplier = User::factory()->create(['role' => 'supplier']);
        $finance = User::factory()->create(['role' => 'finance']);

        $poHtml = $this->actingAs($purchasing)
            ->get(route('purchasing.purchase-orders.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="advanced-export-purchasing-po-search-trigger"', $poHtml);
        $this->assertStringContainsString('id="advanced-export-purchasing-po-supplier_id-trigger"', $poHtml);
        $this->assertStringContainsString('preloadOnOpen: true', $poHtml);
        $this->assertStringContainsString('exportFilterContext: true', $poHtml);
        $this->assertStringContainsString('search-suggestions?export_key=purchasing.po', $poHtml);

        $supplierPoHtml = $this->actingAs($supplier)
            ->get(route('supplier.purchase-orders.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="advanced-export-supplier-po-search-trigger"', $supplierPoHtml);
        $this->assertStringNotContainsString('advanced-export-supplier-po-supplier_id-trigger', $supplierPoHtml);
        $this->assertStringContainsString('search-suggestions?export_key=supplier.po', $supplierPoHtml);

        $quotationsHtml = $this->actingAs($purchasing)
            ->get(route('purchasing.quotations.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="advanced-export-purchasing-quotations-search-trigger"', $quotationsHtml);
        $this->assertStringContainsString('id="advanced-export-purchasing-quotations-supplier_id-trigger"', $quotationsHtml);
        $this->assertStringContainsString('id="advanced-export-purchasing-quotations-period_id-trigger"', $quotationsHtml);

        $prHtml = $this->actingAs($purchasing)
            ->get(route('purchasing.requisitions.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="advanced-export-purchasing-pr-search-trigger"', $prHtml);
        $this->assertStringContainsString('id="advanced-export-purchasing-pr-period_id-trigger"', $prHtml);

        $financeHtml = $this->actingAs($finance)
            ->get(route('finance.master-invoices'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="advanced-export-finance-local-invoices-q-trigger"', $financeHtml);
        $this->assertStringContainsString('id="advanced-export-finance-local-invoices-supplier-trigger"', $financeHtml);
    }
}
