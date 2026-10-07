<?php

namespace Tests\Feature;

use App\Events\ExportProgressUpdated;
use App\Exports\InspectionsExport;
use App\Exports\PaymentBatchDrpExport;
use App\Exports\PurchaseOrdersExport;
use App\Exports\QuotationsExport;
use App\Exports\SupplierPriceHistoryExport;
use App\Jobs\ProcessExportJob;
use App\Models\ExportJob;
use App\Models\Period;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\QcInspection;
use App\Models\Quotation;
use App\Models\User;
use App\Services\ExportProgressService;
use App\Support\Export\ExportOptions;
use App\Support\ExportDispatcher;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Excel as Writer;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AdvancedExportPhaseSixTest extends TestCase
{
    use RefreshDatabase;

    private User $supplier;

    private User $purchasing;

    private PurchaseRequisition $pr;

    private PurchaseOrder $po;

    private QcInspection $inspection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->supplier = User::factory()->create(['role' => 'supplier']);
        $this->purchasing = User::factory()->create(['role' => 'purchasing']);
        $period = Period::create(['name' => 'History', 'year' => 2026, 'month' => 8, 'created_by' => $this->purchasing->id, 'status' => 'open']);
        $this->pr = PurchaseRequisition::create(['period_id' => $period->id, 'created_by' => $this->purchasing->id, 'pr_number' => 'REQ-HISTORY', 'status' => 'bidding']);
        $item = $this->pr->items()->create(['hs_code' => '7209', 'material_name' => 'History Steel', 'shape' => 'Flat', 'quantity' => 1, 'weight_needed' => 1]);
        $quote = Quotation::create(['supplier_id' => $this->supplier->id, 'pr_id' => $this->pr->id, 'currency' => 'USD', 'status' => 'submitted', 'submitted_at' => '2026-08-15 18:30:00']);
        $quote->items()->create(['pr_item_id' => $item->id, 'price_per_kg' => 2.5, 'amount' => 2.5, 'is_available' => true]);
        $this->po = PurchaseOrder::create(['supplier_id' => $this->supplier->id, 'created_by' => $this->purchasing->id, 'po_number' => 'PO-HISTORY', 'currency' => 'USD', 'status' => 'active']);
        $this->po->forceFill(['created_at' => '2026-08-15 18:30:00'])->save();
        $this->po->quotations()->attach($quote->id);
        $this->inspection = QcInspection::create(['po_id' => $this->po->id, 'inspected_by' => User::factory()->create(['role' => 'qc'])->id, 'status' => 'ok', 'inspected_at' => '2026-08-15 18:30:00']);
        $this->inspection->items()->create(['pr_item_id' => $item->id, 'status' => 'ok']);
    }

    public static function goldenCases(): array
    {
        return [['en', 'monthly'], ['id', 'monthly'], ['en', 'yearly'], ['id', 'yearly']];
    }

    #[DataProvider('goldenCases')]
    public function test_price_history_golden_and_warmed_serialization(string $locale, string $view): void
    {
        app()->setLocale($locale);
        Carbon::setLocale($locale);
        try {
            $export = new SupplierPriceHistoryExport($this->supplier->id, $view, 'History Steel', null, [], 'USD');
            $export->setExportLocale($locale);
            $headings = $view === 'monthly'
                ? ($locale === 'en' ? ['PR Number', 'PO Date', 'Status', 'Price/Kg', 'Currency', '% Change'] : ['Nomor PR', 'Tanggal PO', 'Status', 'Harga/Kg', 'Mata Uang', '% Perubahan'])
                : ($locale === 'en' ? ['Year', 'Average Price/Kg', 'Lowest Price/Kg', 'Highest Price/Kg', 'Currency', '% Change'] : ['Tahun', 'Harga Rata-rata/Kg', 'Harga Terendah/Kg', 'Harga Tertinggi/Kg', 'Mata Uang', '% Perubahan']);
            $row = $view === 'monthly' ? ['REQ-HISTORY', $locale === 'en' ? '16 Aug 2026' : '16 Agt 2026', $locale === 'en' ? 'Submitted' : 'Diajukan', 2.5, 'USD', '-'] : ['2026', 2.5, 2.5, 2.5, 'USD', '-'];
            $this->assertSame($headings, $export->headings());
            $this->assertSame([$row], $export->collection()->all());
            $widths = $export->columnWidths();
            $export->applyOptions(new ExportOptions([], 'xlsx', 'supplier'));
            $this->assertSame([$row], $export->collection()->all());
            $this->assertSame($widths, $export->columnWidths());
            $this->assertSame(1, $export->progressTotalRows());
            $export->applyOptions(new ExportOptions([], 'csv', 'supplier'));
            $serialized = serialize($export);
            $this->assertStringNotContainsString('cachedRows', $serialized);
            $restored = unserialize($serialized);
            $this->assertSame($locale, $restored->preferredLocale());
            $this->assertSame([], $restored->columnWidths());
            $this->assertTrue($restored->getCsvSettings()['use_bom']);
            $this->assertSame([$row], $restored->collection()->all());
        } finally {
            Carbon::setLocale('en');
        }
    }

    public function test_format_only_api_preserves_owner_arguments_and_rejects_columns_presets_and_spoofing(): void
    {
        Queue::fake();
        $this->actingAs($this->supplier);
        $this->getJson(route('exports.definitions.show', 'supplier.price-history'))->assertOk()->assertJsonPath('supports_columns', false)->assertJsonPath('supports_presets', false)->assertJsonCount(0, 'columns');
        $filters = ['material_name' => 'History Steel', 'range' => 'all', 'currency' => 'USD'];
        $this->postJson(route('supplier.price-history.export'), [...$filters, 'supplier_id' => $this->purchasing->id, 'options' => ['format' => 'csv']])->assertAccepted();
        $job = ExportJob::sole();
        $this->assertSame($this->supplier->id, $job->export_args[0]);
        $this->assertSame('supplier', $job->export_options['audience']);
        $this->assertSame([], $job->export_options['columns']);
        $this->assertStringEndsWith('.csv', $job->file_name);
        foreach ([['columns' => ['pr_number']], ['audience' => 'purchasing'], ['format' => 'pdf']] as $options) {
            $this->postJson(route('supplier.price-history.export'), [...$filters, 'options' => $options])->assertUnprocessable();
        }
        $this->getJson(route('export-presets.index', ['export_key' => 'supplier.price-history']))->assertNotFound();
        $this->postJson(route('export-presets.store'), ['export_key' => 'supplier.price-history', 'columns' => ['pr_number'], 'format' => 'csv', 'name' => 'Unsupported'])->assertNotFound();
        $this->get(route('supplier.price-history.historical', $filters))->assertOk()->assertSee('data-format-export-form', false)->assertDontSee('data-export-columns', false);
        $this->actingAs($this->purchasing)->postJson(route('supplier.price-history.export'), $filters)->assertForbidden();
    }

    public static function prefixes(): array
    {
        return [['='], ['+'], ['-'], ['@']];
    }

    #[DataProvider('prefixes')]
    public function test_qc_and_history_formula_payloads_are_text_in_default_xlsx_and_csv(string $prefix): void
    {
        $payload = $prefix.'DANGEROUS';
        $this->pr->update(['pr_number' => $payload]);
        $this->pr->items()->firstOrFail()->update(['material_name' => $payload]);
        foreach ([new InspectionsExport, new SupplierPriceHistoryExport($this->supplier->id, 'monthly', $payload, null, [], 'USD')] as $export) {
            $cell = $export instanceof InspectionsExport ? 'C2' : 'A2';
            $path = tempnam(sys_get_temp_dir(), 'export-safe-');
            try {
                file_put_contents($path, Excel::raw($export, Writer::XLSX));
                $book = IOFactory::load($path);
                $this->assertSame("'".$payload, $book->getActiveSheet()->getCell($cell)->getValue());
                $this->assertSame('s', $book->getActiveSheet()->getCell($cell)->getDataType());
                $book->disconnectWorksheets();
                $export->applyOptions(new ExportOptions($export instanceof InspectionsExport ? ['po_number', 'material'] : [], 'csv', $export instanceof InspectionsExport ? 'qc' : 'supplier'));
                $csv = Excel::raw($export, Writer::CSV);
                $this->assertSame(1, substr_count($csv, "\xEF\xBB\xBF"));
                $this->assertStringContainsString("'".$payload, $csv);
            } finally {
                @unlink($path);
            }
        }
    }

    public function test_csv_price_history_pipeline_keeps_one_heading_across_collection_chunks_and_foreign_rows_out(): void
    {
        config(['queue.default' => 'sync']);
        Storage::fake('private');
        Event::fake([ExportProgressUpdated::class]);
        $quote = $this->po->quotations()->sole();
        $quoteItem = $quote->items()->sole();
        $prItem = $this->pr->items()->sole();
        for ($i = 0; $i < 1000; $i++) {
            $item = $prItem->replicate();
            $item->save();
            $copy = $quoteItem->replicate();
            $copy->pr_item_id = $item->id;
            $copy->save();
        }
        $foreign = $this->po->replicate();
        $foreign->supplier_id = User::factory()->create(['role' => 'supplier'])->id;
        $foreign->po_number = 'FOREIGN-PO';
        $foreign->save();
        $foreignQuote = $quote->replicate();
        $foreignQuote->supplier_id = $foreign->supplier_id;
        $foreignQuote->save();
        $foreignItem = $quoteItem->replicate();
        $foreignItem->quotation_id = $foreignQuote->id;
        $foreignItem->save();
        $foreign->quotations()->attach($foreignQuote->id);
        $this->actingAs($this->supplier)->postJson(route('supplier.price-history.export'), ['material_name' => 'History Steel', 'range' => 'all', 'currency' => 'USD', 'options' => ['format' => 'csv']])->assertAccepted();
        $job = ExportJob::sole();
        $this->assertSame('completed', $job->status);
        $this->assertSame(1001, $job->total_rows);
        $csv = Storage::disk('private')->get($job->file_path);
        $this->assertSame(1, substr_count($csv, "\xEF\xBB\xBF"));
        $this->assertSame(1, substr_count($csv, 'PR Number'));
        $this->assertCount(1002, preg_split('/\r?\n/', trim($csv)));
    }

    public function test_legacy_and_advanced_share_five_slots_other_users_and_terminal_jobs_do_not_block(): void
    {
        Queue::fake();
        $this->actingAs($this->purchasing);
        ExportDispatcher::dispatch('Fixed', PaymentBatchDrpExport::class, [[]], 'fixed.xlsx');
        for ($i = 0; $i < 4; $i++) {
            ExportDispatcher::dispatch('Legacy', PurchaseOrdersExport::class, [], 'legacy.xlsx');
        }
        ExportJob::where('export_class', PurchaseOrdersExport::class)->firstOrFail()->update(['status' => 'processing']);
        ExportDispatcher::dispatch('Advanced', QuotationsExport::class, [], 'advanced.xlsx', new ExportOptions(['pr_number']));
        try {
            ExportDispatcher::dispatch('Blocked', PurchaseOrdersExport::class, [], 'blocked.xlsx');
            $this->fail('Legacy requests must share the active list limit.');
        } catch (ValidationException) {
        }
        $this->assertSame(6, ExportJob::count());
        foreach (['cancelled', 'failed', 'completed'] as $terminal) {
            ExportJob::where('export_class', PurchaseOrdersExport::class)->whereIn('status', ['queued', 'processing'])->firstOrFail()->update(['status' => $terminal]);
            ExportDispatcher::dispatch('Slot released', PurchaseOrdersExport::class, [], 'released.xlsx');
        }
        $this->actingAs(User::factory()->create(['role' => 'purchasing']));
        ExportDispatcher::dispatch('Other user', PurchaseOrdersExport::class, [], 'other.xlsx');
        $this->assertSame(10, ExportJob::count());
    }

    public function test_legacy_row_limit_rejects_before_dispatch_and_worker_rechecks_growth(): void
    {
        Queue::fake();
        $this->actingAs($this->purchasing);
        config(['exports.max_rows' => 0]);
        $this->getJson(route('purchasing.export.purchase-orders'))->assertUnprocessable();
        $this->assertSame(0, ExportJob::count());
        config(['exports.max_rows' => 1]);
        $job = ExportDispatcher::dispatch('Legacy', PurchaseOrdersExport::class, [], 'legacy.xlsx');
        $copy = $this->po->replicate();
        $copy->po_number = 'GROWTH';
        $copy->save();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Export row limit exceeded after dispatch.');
        (new ProcessExportJob($job->id))->handle(app(ExportProgressService::class));
    }

    public function test_security_exception_preserves_safe_legacy_whitespace_and_empty_text(): void
    {
        foreach (['  Safe text  ', '', '  =Unsafe  '] as $value) {
            $this->pr->update(['pr_number' => $value]);
            $this->pr->items()->firstOrFail()->update(['material_name' => $value]);
            $expected = $value === '  =Unsafe  ' ? "'".$value : $value;
            $qc = new InspectionsExport;
            $this->assertSame($expected, $qc->map($qc->query()->firstOrFail())[2]);
            $history = new SupplierPriceHistoryExport($this->supplier->id, 'monthly', $value, null, [], 'USD');
            $this->assertSame($expected, $history->collection()->first()[0]);
        }
    }
}
