<?php

namespace Tests\Feature\Architecture;

use App\Models\ExchangeRate;
use App\Models\Period;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\User;
use App\Services\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

class DataTablesSingleSearchContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_datatables_pages_with_filter_bar_search_omit_built_in_search_box_and_never_clobber_d_search(): void
    {
        $viewsRoot = resource_path('views');
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($viewsRoot));

        $auditedPages = [];
        $missingDomErrors = [];
        $forbiddenDomF = [];
        $searchClobberViolations = [];

        foreach ($iterator as $file) {
            if ($file->isDir() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $content = file_get_contents($file->getPathname()) ?: '';
            $hasDataTables = (bool) preg_match("/@section\(\s*'uses-datatables'\s*,\s*true\s*\)/", $content);
            $hasFilterBarSearch = str_contains($content, '<x-ui.filter-bar') && str_contains($content, '<x-slot:search>');

            if (! $hasDataTables || ! $hasFilterBarSearch) {
                continue;
            }

            $normalizedPath = str_replace('\\', '/', $file->getPathname());
            $normalizedBase = rtrim(str_replace('\\', '/', base_path()), '/').'/';
            $relativePath = str_starts_with($normalizedPath, $normalizedBase)
                ? substr($normalizedPath, strlen($normalizedBase))
                : ltrim($normalizedPath, '/');
            $auditedPages[] = $relativePath;

            $combinedScriptSource = $this->expandStaticIncludes($content, $viewsRoot);

            if (preg_match('/\b(?:d|data)\.search\s*=/', $combinedScriptSource)) {
                $searchClobberViolations[] = $relativePath;
            }

            $initCount = 0;
            $offset = 0;
            while (preg_match('/\.DataTable\s*\(\s*\{/s', $combinedScriptSource, $match, PREG_OFFSET_CAPTURE, $offset)) {
                $initCount++;
                $braceStart = $match[0][1] + strlen($match[0][0]) - 1;
                $optionsBlock = $this->extractBalancedBraceBlock($combinedScriptSource, $braceStart);
                $offset = $braceStart + strlen($optionsBlock);

                if (! preg_match('/\bdom\s*:\s*[\'"]([^\'"]+)[\'"]/', $optionsBlock, $domMatch)) {
                    $missingDomErrors[] = $relativePath.' (#'.$initCount.')';

                    continue;
                }

                if (str_contains($domMatch[1], 'f')) {
                    $forbiddenDomF[] = $relativePath.' (dom: '.$domMatch[1].')';
                }
            }

            $this->assertGreaterThan(0, $initCount, "Expected at least one .DataTable({...}) call in [$relativePath]");
        }

        foreach ([
            'resources/views/admin/auth-audit-logs/index.blade.php',
            'resources/views/admin/material-hs-code/index.blade.php',
            'resources/views/admin/users/index.blade.php',
            'resources/views/purchasing/po/index.blade.php',
            'resources/views/purchasing/shipments/index.blade.php',
            'resources/views/supplier/quotations/period.blade.php',
            'resources/views/supplier/shipments/index.blade.php',
        ] as $expectedPage) {
            $this->assertContains($expectedPage, $auditedPages);
        }

        $this->assertSame([], $missingDomErrors, 'DataTables pages using Filter Bar search must define explicit dom without built-in search');
        $this->assertSame([], $forbiddenDomF, "DataTables dom must omit 'f' when x-ui.filter-bar renders the search box");
        $this->assertSame([], $searchClobberViolations, 'DataTables ajax.data must not overwrite d.search object with a plain string');

        $css = file_get_contents(resource_path('css/app.css')) ?: '';
        $this->assertMatchesRegularExpression(
            '/\.ui-data-table\s+\.dataTables_wrapper\s+table\.dataTable[^{]*\{[^}]*margin-top:\s*0\s*!important;[^}]*margin-bottom:\s*0\s*!important;/s',
            $css,
            'DataTables table.dataTable must override both margin-top and margin-bottom to 0 !important to prevent excess top gap above column headers'
        );
    }

    public function test_purchasing_po_unified_search_hydrates_controls_and_filters_by_po_supplier_and_pr(): void
    {
        $purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $supplierAlpha = User::factory()->create(['role' => 'supplier', 'is_active' => true, 'name' => 'Alpha Steel Corp']);
        $supplierBeta = User::factory()->create(['role' => 'supplier', 'is_active' => true, 'name' => 'Beta Alloys Ltd']);

        $period = Period::create([
            'name' => 'Nov Benchmark',
            'month' => 11,
            'year' => 2026,
            'status' => 'open',
            'created_by' => $purchasing->id,
        ]);
        $rate = ExchangeRate::create([
            'currency' => 'USD',
            'rate_to_idr' => 16000,
            'valid_from' => now()->subDay(),
            'created_by' => $purchasing->id,
        ]);

        $prAlpha = PurchaseRequisition::create([
            'period_id' => $period->id,
            'created_by' => $purchasing->id,
            'pr_number' => 'REQ/11/2026/011',
            'status' => 'completed',
        ]);
        $prItemAlpha = $prAlpha->items()->create([
            'hs_code' => '7209.16.00',
            'material_name' => 'Coil Alpha',
            'quantity' => 1,
            'shape' => 'Flat',
            'thickness' => 2.0,
            'width' => 1000,
            'length' => 2000,
            'weight_needed' => 50,
        ]);
        $quotAlpha = Quotation::create([
            'pr_id' => $prAlpha->id,
            'supplier_id' => $supplierAlpha->id,
            'exchange_rate_id' => $rate->id,
            'currency' => 'USD',
            'status' => 'accepted',
            'submitted_at' => now(),
        ]);
        $quotAlpha->items()->create([
            'pr_item_id' => $prItemAlpha->id,
            'price_per_kg' => 3.0,
            'amount' => 150.0,
        ]);

        $poAlpha = PurchaseOrder::create([
            'supplier_id' => $supplierAlpha->id,
            'currency' => 'USD',
            'exchange_rate_id' => $rate->id,
            'po_number' => 'PO/11/2026/011',
            'status' => 'active',
            'created_by' => $purchasing->id,
            'estimated_arrival' => now()->addDays(15),
            'notes' => 'Alpha priority batch',
        ]);
        $poAlpha->quotations()->attach($quotAlpha->id);

        PurchaseOrder::create([
            'supplier_id' => $supplierBeta->id,
            'currency' => 'USD',
            'exchange_rate_id' => $rate->id,
            'po_number' => 'PO/11/2026/099',
            'status' => 'completed',
            'created_by' => $purchasing->id,
            'estimated_arrival' => now()->addDays(20),
            'notes' => 'Beta regular',
        ]);

        $ssrResponse = $this->actingAs($purchasing)
            ->get(route('purchasing.purchase-orders.index', ['search' => 'Alpha Steel', 'status' => 'active']))
            ->assertOk();

        $ssrHtml = $ssrResponse->getContent();
        $this->assertStringContainsString("dom: 'rtip'", $ssrHtml);
        $this->assertStringContainsString('value="Alpha Steel"', $ssrHtml);
        $this->assertStringContainsString(__('purchasing.copy.search_po_supplier_or_pr'), $ssrHtml);
        $this->assertStringContainsString('"search":"#filter_po_number"', $ssrHtml);

        $columns = $this->makeDataTableColumns([
            ['po_number_display', 'po_number'],
            ['supplier_name', 'supplier_name'],
            ['period_name', 'period_name'],
            ['pr_reference', 'pr_reference'],
            ['remark_display', 'remark_display'],
        ]);

        foreach (['Alpha Steel', 'REQ/11/2026/011', 'PO/11/2026/011'] as $keyword) {
            $ajaxResponse = $this->actingAs($purchasing)
                ->getJson(route('purchasing.purchase-orders.index').'?'.http_build_query([
                    'draw' => 1,
                    'start' => 0,
                    'length' => 25,
                    'columns' => $columns,
                    'search' => ['value' => $keyword, 'regex' => false],
                ]), ['X-Requested-With' => 'XMLHttpRequest'])
                ->assertOk()
                ->assertJsonPath('recordsFiltered', 1);

            $this->assertSame('PO/11/2026/011', $ajaxResponse->json('data.0.po_number_display'));
        }
    }

    public function test_purchasing_and_supplier_shipments_handle_datatables_search_value_without_virtual_column_errors(): void
    {
        $purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $supplierA = User::factory()->create(['role' => 'supplier', 'is_active' => true, 'name' => 'Horizon Metals']);
        $supplierB = User::factory()->create(['role' => 'supplier', 'is_active' => true, 'name' => 'Zenith Alloys']);

        $shipmentService = app(ShipmentService::class);
        $shpA = $shipmentService->createDraft($supplierA, [
            'supplier_reference' => 'VESSEL-ALPHA-77',
            'notes' => 'Unique Horizon consignment',
            'shipment_date' => now()->toDateString(),
            'estimated_arrival_date' => now()->addDays(7)->toDateString(),
        ]);
        $shipmentService->createDraft($supplierB, [
            'supplier_reference' => 'VESSEL-BETA-88',
            'notes' => 'Separate Zenith consignment',
            'shipment_date' => now()->toDateString(),
            'estimated_arrival_date' => now()->addDays(9)->toDateString(),
        ]);

        foreach ([
            ['actor' => $purchasing, 'route' => 'purchasing.shipments.index', 'virtualCol' => 'consolidated_pos', 'keywords' => ['Horizon Metals', 'VESSEL-ALPHA-77', $shpA->shipment_number]],
            ['actor' => $supplierA, 'route' => 'supplier.shipments.index', 'virtualCol' => 'po_references', 'keywords' => ['VESSEL-ALPHA-77', $shpA->shipment_number]],
        ] as $case) {
            $ssrHtml = $this->actingAs($case['actor'])
                ->get(route($case['route'], ['search' => 'VESSEL-ALPHA-77']))
                ->assertOk()
                ->getContent();

            $this->assertStringContainsString("dom: 'rtip'", $ssrHtml);
            $this->assertStringNotContainsString("d.search = $('#filter_search').val()", $ssrHtml);

            $columns = $this->makeDataTableColumns([
                ['shipment_number_display', 'shipment_number'],
                [$case['virtualCol'], $case['virtualCol']],
            ]);

            foreach ($case['keywords'] as $keyword) {
                $response = $this->actingAs($case['actor'])
                    ->getJson(route($case['route']).'?'.http_build_query([
                        'draw' => 1,
                        'start' => 0,
                        'length' => 25,
                        'columns' => $columns,
                        'search' => ['value' => $keyword, 'regex' => false],
                    ]), ['X-Requested-With' => 'XMLHttpRequest'])
                    ->assertOk()
                    ->assertJsonPath('recordsFiltered', 1);

                $this->assertStringContainsString($shpA->shipment_number, $response->json('data.0.shipment_number_display'));
            }
        }
    }

    public function test_supplier_quotations_period_renders_single_search_and_filters_by_pr_number(): void
    {
        $purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $period = Period::create([
            'name' => 'Quotation Period Nov',
            'month' => 11,
            'year' => 2026,
            'status' => 'open',
            'created_by' => $purchasing->id,
        ]);

        PurchaseRequisition::create([
            'period_id' => $period->id,
            'created_by' => $purchasing->id,
            'pr_number' => 'REQ/11/2026/801',
            'status' => 'submitted',
        ]);
        PurchaseRequisition::create([
            'period_id' => $period->id,
            'created_by' => $purchasing->id,
            'pr_number' => 'REQ/11/2026/802',
            'status' => 'submitted',
        ]);

        $ssrHtml = $this->actingAs($supplier)
            ->get(route('supplier.quotations.period', $period->id).'?pr_number=REQ/11/2026/801')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString("dom: 'rtip'", $ssrHtml);
        $this->assertStringContainsString('value="REQ/11/2026/801"', $ssrHtml);

        $response = $this->actingAs($supplier)
            ->getJson(route('supplier.quotations.period', $period->id).'?draw=1&start=0&length=25&pr_number=REQ/11/2026/801', ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertJsonPath('recordsFiltered', 1);

        $this->assertSame('REQ/11/2026/801', $response->json('data.0.pr_number_display'));
    }

    private function expandStaticIncludes(string $content, string $viewsRoot): string
    {
        $combined = $content;
        if (preg_match_all('/@include\(\s*[\'"]([a-zA-Z0-9_.-]+)[\'"]\s*(?:,|\))/', $content, $matches)) {
            foreach ($matches[1] as $viewName) {
                $path = $viewsRoot.DIRECTORY_SEPARATOR.str_replace('.', DIRECTORY_SEPARATOR, $viewName).'.blade.php';
                if (is_file($path)) {
                    $combined .= "\n".(file_get_contents($path) ?: '');
                }
            }
        }

        return $combined;
    }

    private function extractBalancedBraceBlock(string $source, int $openBraceOffset): string
    {
        $depth = 0;
        $quote = null;
        $length = strlen($source);

        for ($i = $openBraceOffset; $i < $length; $i++) {
            $ch = $source[$i];
            if ($quote !== null) {
                if ($ch === $quote && $source[$i - 1] !== '\\') {
                    $quote = null;
                }

                continue;
            }
            if ($ch === '"' || $ch === "'") {
                $quote = $ch;

                continue;
            }
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $openBraceOffset, $i - $openBraceOffset + 1);
                }
            }
        }

        return substr($source, $openBraceOffset);
    }

    /**
     * @param  list<array{0: string, 1: string}>  $definitions
     * @return list<array<string, mixed>>
     */
    private function makeDataTableColumns(array $definitions): array
    {
        return array_map(static fn (array $pair): array => [
            'data' => $pair[0],
            'name' => $pair[1],
            'searchable' => 'true',
            'orderable' => 'false',
            'search' => ['value' => '', 'regex' => 'false'],
        ], $definitions);
    }
}
