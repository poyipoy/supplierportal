<?php

namespace Tests\Feature;

use App\Events\ExportProgressUpdated;
use App\Exports\InspectionsExport;
use App\Exports\LocalInvoicesExport;
use App\Jobs\ProcessExportJob;
use App\Models\ExportJob;
use App\Models\LocalInvoice;
use App\Models\Period;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\QcInspection;
use App\Models\User;
use App\Services\ExportProgressService;
use App\Support\Export\ExportOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AdvancedExportPhaseFiveBTest extends TestCase
{
    use RefreshDatabase;

    public static function endpoints(): array
    {
        return [
            ['qc', 'qc.inspections', 'qc.export.inspections', 'po_number', []],
            ['finance', 'finance.local-invoices', 'finance.master-invoices.export', 'submission', []],
            ['accounting', 'accounting.local-invoices', 'accounting.reports.export', 'submission', ['report' => 'payments']],
            ['finance', 'accounting.local-invoices', 'accounting.reports.export', 'submission', ['report' => 'register']],
            ['admin', 'finance.local-invoices', 'finance.master-invoices.export', 'submission', []],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_dispatch_definitions_presets_and_double_validation(string $role, string $key, string $route, string $required, array $filters): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create(['role' => $role]));
        $this->getJson(route('exports.definitions.show', $key))->assertOk()->assertJsonCount($role === 'qc' ? 8 : 16, 'columns');
        $this->postJson(route($route), [...$filters, 'options' => ['columns' => [$required], 'format' => 'csv']])->assertAccepted();
        $job = ExportJob::sole();
        $this->assertSame(explode('.', $key)[0], $job->export_options['audience']);
        if ($role !== 'qc') {
            $this->assertSame(($filters['report'] ?? null) === 'payments', $job->export_args[2]);
        }
        foreach ([[], ['internal_notes', $required], [$required, $required]] as $columns) {
            $this->postJson(route($route), [...$filters, 'options' => ['columns' => $columns]])->assertUnprocessable();
        }
        $this->postJson(route($route), [...$filters, 'options' => ['columns' => [$required], 'audience' => 'supplier']])->assertUnprocessable();
        $preset = $this->postJson(route('export-presets.store'), ['name' => 'Five B', 'export_key' => $key, 'columns' => [$required], 'format' => 'csv', 'filters' => $filters])->assertCreated()->json();
        $this->assertSame($filters, $preset['filters']);
        $this->actingAs(User::factory()->create(['role' => $role]))->putJson(route('export-presets.update', $preset['id']), ['name' => 'Stolen', 'columns' => [$required], 'format' => 'xlsx'])->assertForbidden();
    }

    public function test_routes_and_definitions_reject_wrong_roles_and_raw_supplier_ids(): void
    {
        Queue::fake();
        $supplier = User::factory()->create(['role' => 'supplier']);
        $this->actingAs($supplier);
        foreach (self::endpoints() as [, $key, $route, $required, $filters]) {
            $this->getJson(route('exports.definitions.show', $key))->assertNotFound();
            $this->postJson(route($route), [...$filters, 'options' => ['columns' => [$required]]])->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['role' => 'finance']))->postJson(route('finance.master-invoices.export'), ['supplier' => (string) $supplier->id, 'options' => ['columns' => ['submission']]])->assertUnprocessable();
        $this->assertSame(0, ExportJob::count());
    }

    public function test_qc_business_dates_match_history_and_selected_relations_are_loaded(): void
    {
        [$qc, $inspection] = $this->inspection();
        $export = new InspectionsExport('2026-08-16', '2026-08-16', 'ok');
        $export->applyOptions(new ExportOptions(['material', 'po_number', 'inspection_date'], 'xlsx', 'qc'));
        $this->assertArrayNotHasKey('inspection.purchaseOrder.supplier', $export->query()->getEagerLoads());
        $row = $export->query()->firstOrFail();
        $this->assertSame(["'=Steel", 'PO-5B', '16/08/2026 01:30'], $export->map($row));
        $restored = unserialize(serialize($export));
        $this->assertSame($export->map($row), $restored->map($restored->query()->firstOrFail()));
        $this->assertSame("'=Steel", (new InspectionsExport)->map($row)[2]);
        $this->actingAs($qc)->getJson(route('qc.inspections.data-history', ['start_date' => '2026-08-16', 'end_date' => '2026-08-16', 'status' => 'ok']))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(route('qc.inspections.data-history', ['start_date' => '2026-08-17']))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_local_invoice_subset_is_string_safe_and_payments_filter_matches_existing_query(): void
    {
        Queue::fake();
        $finance = User::factory()->create(['role' => 'finance']);
        $invoice = $this->invoice();
        $this->invoice('WAITING_PHYSICAL_DOCUMENT', 'OTHER');
        $export = new LocalInvoicesExport($finance->id, [], true);
        $export->applyOptions(new ExportOptions(['invoice_amount', 'submission', 'invoice', 'due_date'], 'xlsx', 'finance'));
        $this->assertSame([], $export->query()->getEagerLoads());
        $this->assertSame(1, $export->progressTotalRows());
        $this->assertSame(['100000.25', 'LSI-5B-ONE', "'@Invoice", '2026-09-15'], $export->map($export->query()->firstOrFail()));
        $restored = unserialize(serialize($export));
        $this->assertSame($export->map($invoice), $restored->map($restored->query()->firstOrFail()));
        $this->actingAs($finance)->get(route('finance.master-invoices.export', ['q' => 'ONE']))->assertRedirect(route('exports.index'));
        $this->assertSame(['q' => 'ONE'], ExportJob::sole()->export_args[1]);
    }

    public function test_worker_reauthorizes_stored_audience_and_actor(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create(['role' => 'qc']))->postJson(route('qc.export.inspections'), ['options' => ['columns' => ['po_number']]])->assertAccepted();
        $job = ExportJob::sole();
        $job->forceFill(['export_options' => ['columns' => ['submission'], 'format' => 'xlsx', 'audience' => 'finance'], 'export_class' => LocalInvoicesExport::class, 'export_args' => [$job->user_id, [], false]])->save();
        $this->expectException(HttpException::class);
        (new ProcessExportJob($job->id))->handle(app(ExportProgressService::class));
    }

    public function test_modals_render_in_both_locales_and_accounting_report_has_valid_labels(): void
    {
        foreach (['en', 'id'] as $locale) {
            foreach (['qc' => 'qc.inspections.index', 'finance' => 'finance.master-invoices', 'accounting' => 'accounting.reports'] as $role => $route) {
                $actor = User::factory()->create(['role' => $role]);
                $actor->preference()->create([...config('user_preferences.defaults'), 'locale' => $locale]);
                $this->actingAs($actor)->get(route($route))->assertOk()->assertSee('data-advanced-export-form', false)->assertSee('aria-modal="true"', false)->assertDontSee('status.local_invoice.', false);
            }
        }
    }

    public function test_qc_and_invoice_csv_have_single_heading_and_bom_over_multiple_chunks(): void
    {
        config(['queue.default' => 'sync']);
        Storage::fake('private');
        Event::fake([ExportProgressUpdated::class]);
        [$qc, $inspection, $item] = $this->inspection();
        $finance = User::factory()->create(['role' => 'finance']);
        $invoice = $this->invoice();
        for ($i = 0; $i < 500; $i++) {
            $inspection->items()->create(['pr_item_id' => $item->id, 'status' => 'ok']);
            $copy = $invoice->replicate();
            $copy->submission_number = 'LSI-5B-'.$i;
            $copy->invoice_number = 'INV-5B-'.$i;
            $copy->save();
        }
        foreach ([[$qc, 'qc.export.inspections', ['po_number', 'material']], [$finance, 'finance.master-invoices.export', ['submission', 'invoice']]] as [$actor, $route, $keys]) {
            $this->actingAs($actor)->postJson(route($route), ['options' => ['columns' => $keys, 'format' => 'csv']])->assertAccepted();
            $job = ExportJob::where('user_id', $actor->id)->sole();
            $this->assertSame('completed', $job->status);
            $this->assertSame(501, $job->processed_rows);
            $contents = Storage::disk('private')->get($job->file_path);
            $this->assertSame(1, substr_count($contents, "\xEF\xBB\xBF"));
            $lines = preg_split('/\r?\n/', trim($contents));
            $this->assertCount(502, $lines);
            $this->assertSame(1, substr_count($contents, $lines[0]));
            $this->assertStringContainsString($actor->role === 'qc' ? "'=Steel" : "'@Invoice", $contents);
        }
    }

    private function inspection(): array
    {
        $qc = User::factory()->create(['role' => 'qc']);
        $purchasing = User::factory()->create(['role' => 'purchasing']);
        $supplier = User::factory()->create(['role' => 'supplier']);
        $period = Period::create(['name' => 'Five B', 'year' => 2026, 'month' => 8, 'created_by' => $purchasing->id, 'status' => 'open']);
        $pr = PurchaseRequisition::create(['period_id' => $period->id, 'created_by' => $purchasing->id, 'status' => 'bidding']);
        $item = $pr->items()->create(['hs_code' => '7209', 'material_name' => '=Steel', 'quantity' => 1, 'weight_needed' => 1, 'shape' => 'Flat']);
        $po = PurchaseOrder::create(['supplier_id' => $supplier->id, 'created_by' => $purchasing->id, 'po_number' => 'PO-5B', 'currency' => 'USD', 'status' => 'active']);
        $inspection = QcInspection::create(['po_id' => $po->id, 'inspected_by' => $qc->id, 'inspected_at' => '2026-08-15 18:30:00', 'status' => 'ok']);
        $inspection->items()->create(['pr_item_id' => $item->id, 'status' => 'ok']);

        return [$qc, $inspection, $item];
    }

    private function invoice(string $status = 'READY_TO_PAY', string $suffix = 'ONE'): LocalInvoice
    {
        $supplier = User::factory()->create(['role' => 'supplier']);

        return LocalInvoice::create(['supplier_id' => $supplier->id, 'submission_number' => 'LSI-5B-'.$suffix, 'invoice_number' => '@Invoice', 'invoice_date' => '2026-08-15', 'submitted_at' => '2026-08-15 18:30:00', 'po_number' => 'LOCAL-5B', 'currency' => 'IDR', 'invoice_amount' => '100000.25', 'tax_amount' => '11000.03', 'status' => $status, 'due_date' => '2026-09-15']);
    }
}
