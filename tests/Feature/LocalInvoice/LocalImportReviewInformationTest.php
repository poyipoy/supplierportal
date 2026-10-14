<?php

namespace Tests\Feature\LocalInvoice;

use App\Models\LocalProcurementImport;
use App\Models\LocalPurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\LocalInvoice\LocalGrImportService;
use App\Services\LocalInvoice\LocalProcurementImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class LocalImportReviewInformationTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private User $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        Queue::fake();
        $this->actor = User::factory()->create(['role' => 'finance']);
        $this->supplier = User::factory()->create();
        $this->supplier->supplierScopes()->create(['scope' => 'local']);
        Supplier::create(['user_id' => $this->supplier->id, 'company_name' => 'PT Review', 'category' => 'Parts', 'is_pkp' => false, 'payment_term_days' => 30]);
        LocalPurchaseOrder::create(['po_number' => 'REVIEW-PO', 'supplier_id' => $this->supplier->id,
            'po_date' => '2026-10-01', 'total_amount' => '1000.00', 'currency' => 'IDR', 'status' => 'OPEN']);
    }

    public function test_new_record_count_excludes_conflicts_and_missing_pos_and_errors_have_role_aware_actions(): void
    {
        [$import] = app(LocalProcurementImportService::class)->start($this->actor, $this->workbook(), 'GR');
        app(LocalProcurementImportService::class)->preview($import->id);
        $this->actingAs($this->actor);
        $status = $this->getJson(route('finance.local-procurement.imports.status', $import))->assertOk();
        $status->assertJsonPath('status', 'VALIDATION_FAILED')->assertJsonPath('preview.summary.new_gr', 1)
            ->assertJsonPath('preview.summary.invalid', 1);
        $errors = $this->getJson($status->json('errors_url'))->assertOk()->json('data');
        $missing = collect($errors)->firstWhere('code', 'PO_NOT_FOUND');
        $this->assertNotNull($missing);
        $this->assertSame('MISSING-PO', $missing['context']['po_number']);
        $this->assertSame(route('finance.local-procurement.index', ['q' => 'MISSING-PO']), $missing['action']['url']);
        $this->assertSame(__('local_procurement.large_import.open_po'), $missing['action']['label']);
        $this->assertStringNotContainsString('imports/uploads', $status->getContent());
        $status->assertJsonMissingPath('owner_job_key')->assertJsonMissingPath('token_hash')->assertJsonMissingPath('attachments');
        $this->actor->update(['role' => 'purchasing']);
        $this->actingAs($this->actor->fresh());
        $purchasingErrors = $this->getJson(route('purchasing.local-procurement.imports.errors', $import))->assertOk()->json('data');
        $this->assertSame(route('purchasing.local-procurement.index', ['q' => 'MISSING-PO']), collect($purchasingErrors)->firstWhere('code', 'PO_NOT_FOUND')['action']['url']);
    }

    public function test_multiple_errors_on_one_source_row_count_as_one_problem_row(): void
    {
        $result = app(LocalGrImportService::class)->validate([['_row' => 3, 'gr_number' => 'BAD-GR',
            'po_number' => 'REVIEW-PO', 'gr_date' => 'bad-date', 'qty' => '0', 'uom' => 'wrong']]);
        $this->assertGreaterThan(1, count($result['errors']));
        $this->assertSame(1, $result['summary']['invalid']);
        $this->assertSame(0, $result['summary']['new_gr']);
    }

    public function test_active_entries_cannot_be_hidden_by_many_recoverable_entries(): void
    {
        [$active] = app(LocalProcurementImportService::class)->start($this->actor, $this->workbook(), 'GR');
        foreach (range(1, 105) as $index) {
            LocalProcurementImport::create(['user_id' => $this->actor->id, 'kind' => 'GR', 'locale' => 'en',
                'status' => 'VALIDATION_FAILED', 'token_hash' => hash('sha256', 'review-'.$index), 'checksum' => str_repeat('a', 64),
                'original_filename' => 'failed-'.$index.'.xlsx', 'expires_at' => now()->addHour()]);
        }
        $this->actingAs($this->actor)->getJson(route('finance.local-procurement.imports.index', ['active' => 1, 'include_recoverable' => 1]))
            ->assertOk()->assertJsonCount(100)->assertJsonFragment(['id' => $active->hash]);
    }

    public function test_error_remediation_queries_stay_bounded_as_the_error_page_grows(): void
    {
        $import = LocalProcurementImport::create(['user_id' => $this->actor->id, 'kind' => 'GR', 'locale' => 'en', 'status' => 'VALIDATION_FAILED',
            'token_hash' => hash('sha256', 'query-fixture'), 'checksum' => str_repeat('a', 64), 'original_filename' => 'queries.xlsx', 'attempt' => 1, 'expires_at' => now()->addHour()]);
        $record = fn ($row) => ['import_id' => $import->id, 'attempt' => 1, 'kind' => 'GR', 'document_key' => 'gr-'.$row, 'source_row' => $row, 'source_count' => 1,
            'values' => json_encode(['action' => 'CONFLICT']), 'errors' => json_encode([['row' => $row, 'column' => 'po_number', 'message' => 'Fixture error',
                'code' => 'PO_CANCELLED', 'context' => ['po_number' => 'REVIEW-PO']]])];
        DB::table('local_procurement_import_records')->insert($record(2));
        $this->actingAs($this->actor);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson(route('finance.local-procurement.imports.errors', $import))->assertOk();
        $small = count(DB::getQueryLog());
        DB::table('local_procurement_import_records')->insert(array_map($record, range(3, 151)));
        DB::flushQueryLog();
        $response = $this->getJson(route('finance.local-procurement.imports.errors', $import))->assertOk()->assertJsonCount(100, 'data');
        $large = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual($small + 2, $large, 'Lookup must batch references rather than query once per error.');
        $this->assertLessThan(131072, strlen($response->getContent()));
        $this->assertStringContainsString('/finance/local-procurement/', $response->json('data.99.action.url'));
    }

    private function workbook(): UploadedFile
    {
        $path = Storage::disk('private')->path('review-'.uniqid().'.xlsx');
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(array_fill(0, 21, '')));
        foreach (['REVIEW-PO', 'MISSING-PO'] as $index => $po) {
            $row = array_fill(0, 21, null);
            $row[9] = 'REVIEW-GR-'.$index;
            $row[11] = $po;
            $row[15] = '1';
            $row[16] = 'pcs';
            $row[19] = '2026-10-01';
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();

        return new UploadedFile($path, 'review.xlsx', null, null, true);
    }
}
