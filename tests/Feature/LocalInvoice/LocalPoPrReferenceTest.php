<?php

namespace Tests\Feature\LocalInvoice;

use App\Models\LocalFinanceAuditLog;
use App\Models\LocalGoodsReceipt;
use App\Models\LocalInvoice;
use App\Models\LocalProcurementImport;
use App\Models\LocalPurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\LocalInvoice\LocalProcurementImportService;
use App\Services\RegionalDisplayFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LocalPoPrReferenceTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private User $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        Queue::fake();
        $this->actor = User::factory()->create(['role' => 'finance', 'is_active' => true]);
        $this->supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $this->supplier->supplierScopes()->create(['scope' => 'local']);
        Supplier::create(['user_id' => $this->supplier->id, 'company_name' => 'PT PR Import Test', 'category' => 'Parts', 'is_pkp' => false, 'payment_term_days' => 30]);
    }

    public function test_new_purchase_orders_preserve_text_pr_and_allow_one_pr_on_multiple_pos(): void
    {
        [$import, $token] = $this->preview([
            $this->row('PR-PO-1', ' 000PR-01 '), $this->row('PR-PO-2', '000PR-01'),
        ]);
        $this->assertSame('READY', $import->fresh()->status);
        $this->save($import, $token);
        $this->assertDatabaseHas('local_purchase_orders', ['po_number' => 'PR-PO-1', 'pr_number' => '000PR-01', 'total_amount' => '1000.00']);
        $this->assertDatabaseHas('local_purchase_orders', ['po_number' => 'PR-PO-2', 'pr_number' => '000PR-01']);
        $this->assertSame(2, $import->fresh()->summary['newPo']);
    }

    public function test_repeated_po_rows_merge_empty_and_case_equivalent_pr_without_summing_amounts(): void
    {
        config(['local_procurement_imports.batch_size' => 2]);
        [$import, $token] = $this->preview([
            $this->row('PR-MERGE', ''), $this->row('PR-MERGE', ' PR-0007 '),
            $this->row('PR-MERGE', 'pr-0007'), $this->row('PR-MERGE', null),
        ]);
        $this->assertSame('READY', $import->fresh()->status);
        $this->assertSame(1, $import->fresh()->summary['new_po']);
        $this->assertSame(4, $import->fresh()->source_rows);
        $this->save($import, $token);
        $this->assertDatabaseCount('local_purchase_orders', 1);
        $this->assertDatabaseHas('local_purchase_orders', ['po_number' => 'PR-MERGE', 'pr_number' => 'PR-0007', 'total_amount' => '1000.00']);
    }

    public function test_different_pr_for_same_po_across_batches_blocks_the_entire_file(): void
    {
        config(['local_procurement_imports.batch_size' => 2]);
        [$import, $token] = $this->preview([
            $this->row('PR-CONFLICT', 'PR-A'), $this->row('PR-OTHER', 'PR-C'), $this->row('PR-CONFLICT', 'PR-B'),
        ]);
        $this->assertSame('VALIDATION_FAILED', $import->fresh()->status);
        $this->assertGreaterThan(0, $import->fresh()->summary['invalid']);
        $this->actingAs($this->actor)->postJson(route('finance.local-procurement.import.po.confirm'), ['token' => $token])->assertStatus(409);
        $this->assertDatabaseCount('local_purchase_orders', 0);
    }

    public function test_pr_only_import_fills_existing_empty_po_and_reports_actual_changes(): void
    {
        $po = $this->po('PR-EXISTING');
        $original = $po->only(['supplier_id', 'po_date', 'total_amount', 'description', 'status', 'source']);
        [$import, $token] = $this->preview([$this->row($po->po_number, 'PR-009')]);
        $this->assertSame('READY', $import->fresh()->status);
        $this->assertSame(0, $import->fresh()->summary['new_po']);
        $this->assertSame(1, $import->fresh()->summary['po_pr_filled']);
        $record = DB::table('local_procurement_import_records')->where('import_id', $import->id)->sole();
        $this->assertSame('FILL_PR', json_decode($record->values, true)['action']);
        $this->actingAs($this->actor)->getJson(route('finance.local-procurement.imports.status', $import))
            ->assertOk()->assertJsonPath('preview.summary.po_pr_filled', 1)->assertJsonPath('preview.rows.0.pr_number', 'PR-009');
        $this->save($import, $token);
        $this->assertSame('PR-009', $po->fresh()->pr_number);
        $this->assertEquals($original, $po->fresh()->only(array_keys($original)));
        $this->assertSame(1, $import->fresh()->summary['filledPoPr']);
        $this->assertSame(1, $import->fresh()->summary['po_pr_filled']);
        $this->assertSame(0, LocalFinanceAuditLog::where('action', 'po_created')->count());
    }

    public function test_existing_pr_cannot_be_replaced_and_empty_or_equivalent_input_is_a_no_op(): void
    {
        $po = $this->po('PR-IMMUTABLE', 'Pr-001');
        foreach (['PR-002', '', ' pr-001 '] as $input) {
            [$import] = $this->preview([$this->row($po->po_number, $input)]);
            $this->assertSame('VALIDATION_FAILED', $import->fresh()->status);
            $this->assertSame('Pr-001', $po->fresh()->pr_number);
            $this->assertSame(0, $import->fresh()->summary['po_pr_filled']);
            if ($input === 'PR-002') {
                $this->assertGreaterThan(0, $import->fresh()->summary['invalid']);
            } else {
                $this->assertSame(0, $import->fresh()->summary['invalid']);
            }
        }
    }

    public function test_commit_rechecks_pr_conflict_and_rolls_back_new_pos_in_the_same_file(): void
    {
        $po = $this->po('PR-RACE-EXISTING');
        [$import, $token] = $this->preview([$this->row('PR-NEW', 'PR-N'), $this->row($po->po_number, 'PR-A')]);
        $this->assertSame('READY', $import->fresh()->status);
        $po->update(['pr_number' => 'PR-B']);
        $service = app(LocalProcurementImportService::class);
        $service->confirm($this->actor, $token, 'PO');
        $service->commit($import->id);
        $this->assertSame('VALIDATION_FAILED', $import->fresh()->status);
        $this->assertDatabaseMissing('local_purchase_orders', ['po_number' => 'PR-NEW']);
        $this->assertSame('PR-B', $po->fresh()->pr_number);
        $this->assertSame(0, LocalFinanceAuditLog::where('action', 'po_created')->count());
    }

    public static function formats(): array
    {
        return [['xlsx'], ['csv'], ['xls']];
    }

    #[DataProvider('formats')]
    public function test_supported_formats_accept_optional_pr_and_legacy_sixteen_columns(string $format): void
    {
        [$new, $newToken] = $this->preview([$this->row('FORMAT-NEW-'.$format, '0000017')], $format);
        $this->assertSame('READY', $new->fresh()->status, $new->fresh()->failure ?? '');
        $this->save($new, $newToken);
        $this->assertDatabaseHas('local_purchase_orders', ['po_number' => 'FORMAT-NEW-'.$format, 'pr_number' => '0000017']);

        [$old, $oldToken] = $this->preview([$this->row('FORMAT-OLD-'.$format)], $format, false);
        $this->assertSame('READY', $old->fresh()->status, $old->fresh()->failure ?? '');
        $this->save($old, $oldToken);
        $this->assertDatabaseHas('local_purchase_orders', ['po_number' => 'FORMAT-OLD-'.$format, 'pr_number' => null]);
    }

    public static function invalidPrNumbers(): array
    {
        return [
            'over length' => [str_repeat('P', 101)],
            'comma list' => ['PR-1, PR-2'],
            'semicolon list' => ['PR-1;PR-2'],
            'newline list' => ["PR-1\nPR-2"],
            'control character' => ["PR-1\t"],
            'formula' => ['=1+1'],
        ];
    }

    #[DataProvider('invalidPrNumbers')]
    public function test_invalid_pr_blocks_other_valid_purchase_orders_in_the_file(string $pr): void
    {
        [$import] = $this->preview([$this->row('VALID-NEIGHBOR', 'PR-GOOD'), $this->row('INVALID-PR', $pr)]);
        $this->assertSame('VALIDATION_FAILED', $import->fresh()->status);
        $this->assertGreaterThan(0, $import->fresh()->summary['invalid']);
        $this->assertDatabaseCount('local_purchase_orders', 0);
        $this->assertDatabaseCount('local_finance_audit_logs', 1);
    }

    public function test_pr_exactly_one_hundred_characters_is_accepted(): void
    {
        $pr = str_repeat('P', 100);
        [$import, $token] = $this->preview([$this->row('MAX-LENGTH-PR', $pr)]);
        $this->assertSame('READY', $import->fresh()->status);
        $this->save($import, $token);
        $this->assertDatabaseHas('local_purchase_orders', ['po_number' => 'MAX-LENGTH-PR', 'pr_number' => $pr]);
    }

    public static function conflictingHeaders(): array
    {
        return [['po_date', '2026-10-02'], ['po_amount', '999.00'], ['supplier_name', 'Missing Vendor']];
    }

    #[DataProvider('conflictingHeaders')]
    public function test_repeated_po_header_conflicts_remain_blocking(string $field, string $value): void
    {
        [$import] = $this->preview([$this->row('HEADER-CONFLICT', 'PR-1'), $this->row('HEADER-CONFLICT', 'PR-1', [$field => $value])]);
        $this->assertSame('VALIDATION_FAILED', $import->fresh()->status);
        $this->assertDatabaseCount('local_purchase_orders', 0);
    }

    public function test_import_does_not_fill_pr_on_cancelled_existing_purchase_order(): void
    {
        $po = $this->po('CANCELLED-PR', null, ['status' => 'CANCELLED']);
        [$import] = $this->preview([$this->row($po->po_number, 'PR-NEW')]);
        $this->assertSame('VALIDATION_FAILED', $import->fresh()->status);
        $this->assertNull($po->fresh()->pr_number);
        $this->assertSame('CANCELLED', $po->fresh()->status);
    }

    public function test_empty_input_never_erases_existing_pr_when_another_po_is_new(): void
    {
        $po = $this->po('PR-PRESERVED', 'PR-KEEP');
        [$import, $token] = $this->preview([$this->row($po->po_number, ''), $this->row('OTHER-NEW', null)]);
        $this->assertSame('READY', $import->fresh()->status);
        $this->save($import, $token);
        $this->assertSame('PR-KEEP', $po->fresh()->pr_number);
        $this->assertSame(0, $import->fresh()->summary['filledPoPr']);
    }

    public function test_another_import_filling_same_pr_reconciles_actual_counts_without_duplicate_updates(): void
    {
        $po = $this->po('PR-OVERLAP');
        [$first, $firstToken] = $this->preview([$this->row($po->po_number, 'PR-SAME')]);
        [$second, $secondToken] = $this->preview([$this->row($po->po_number, 'pr-same'), $this->row('NEW-WINNER', 'PR-SAME')]);
        $this->assertSame('READY', $first->fresh()->status);
        $this->assertSame('READY', $second->fresh()->status);
        $this->save($first, $firstToken);
        $this->save($second, $secondToken);
        $this->assertSame('PR-SAME', $po->fresh()->pr_number);
        $this->assertSame(0, $second->fresh()->summary['filledPoPr']);
        $this->assertSame(0, $second->fresh()->summary['po_pr_filled']);
        $this->assertSame(1, $second->fresh()->summary['newPo']);
        $this->assertDatabaseCount('local_purchase_orders', 2);
    }

    public function test_legacy_staged_payload_without_pr_field_still_commits(): void
    {
        [$import, $token] = $this->preview([$this->row('LEGACY-STAGED')], 'xlsx', false);
        $this->assertSame('READY', $import->fresh()->status);
        foreach (DB::table('local_procurement_import_records')->where('import_id', $import->id)->get() as $record) {
            $values = json_decode($record->values, true);
            unset($values['pr_number']);
            DB::table('local_procurement_import_records')->where('id', $record->id)->update(['values' => json_encode($values, JSON_THROW_ON_ERROR)]);
        }
        $this->save($import, $token);
        $this->assertDatabaseHas('local_purchase_orders', ['po_number' => 'LEGACY-STAGED', 'pr_number' => null]);
    }

    public function test_later_batch_failure_rolls_back_pr_fill_and_new_po_with_audits(): void
    {
        config(['local_procurement_imports.batch_size' => 1]);
        $po = $this->po('A-EXISTING-FILL');
        [$import, $token] = $this->preview([$this->row($po->po_number, 'PR-ROLLBACK'), $this->row('Z-LATER-FAIL', 'PR-ROLLBACK')]);
        $this->assertSame('READY', $import->fresh()->status);
        $service = app(LocalProcurementImportService::class);
        $service->confirm($this->actor, $token, 'PO');
        DB::listen(function ($query) {
            if (str_contains($query->sql, 'insert into `local_purchase_orders`') && in_array('Z-LATER-FAIL', $query->bindings, true)) {
                throw new \RuntimeException('Injected later PO failure');
            }
        });
        try {
            $service->commit($import->id);
            $this->fail('Expected injected later PO failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected later PO failure', $exception->getMessage());
        }
        $this->assertNull($po->fresh()->pr_number);
        $this->assertDatabaseMissing('local_purchase_orders', ['po_number' => 'Z-LATER-FAIL']);
        $this->assertSame(0, LocalFinanceAuditLog::where('action', 'po_created')->count());
        $this->assertSame(1, LocalFinanceAuditLog::count());
    }

    public function test_supplier_pr_search_preserves_owner_status_and_available_gr_filters(): void
    {
        $own = $this->po('OWN-SEARCH', 'PR-SEARCH');
        $this->gr($own, 'OWN-GR');
        $other = User::factory()->create();
        $foreign = $this->po('FOREIGN-SEARCH', 'PR-SEARCH', ['supplier_id' => $other->id]);
        $this->gr($foreign, 'FOREIGN-GR');
        $closed = $this->po('CLOSED-SEARCH', 'PR-SEARCH', ['status' => 'CLOSED']);
        $this->gr($closed, 'CLOSED-GR');
        $this->po('NO-GR-SEARCH', 'PR-SEARCH');
        $cancelled = $this->po('CANCELLED-SEARCH', 'PR-SEARCH', ['status' => 'CANCELLED']);
        $this->gr($cancelled, 'CANCELLED-GR');

        $this->actingAs($this->supplier)->getJson(route('local-supplier.purchase-orders.search', ['q' => 'pr-search']))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.po_number', $own->po_number)->assertJsonPath('data.0.pr_number', 'PR-SEARCH');
        $this->get(route('local-supplier.purchase-orders.index', ['q' => 'pr-search', 'status' => 'OPEN']))
            ->assertOk()->assertViewHas('purchaseOrders', fn ($orders) => $orders->pluck('id')->sort()->values()->all() === collect([$own->id, LocalPurchaseOrder::where('po_number', 'NO-GR-SEARCH')->value('id')])->sort()->values()->all())
            ->assertSee('PR-SEARCH')->assertDontSee('FOREIGN-SEARCH');
        $this->get(route('local-supplier.purchase-orders.show', $foreign))->assertForbidden();
        $this->get(route('local-supplier.purchase-orders.show', $own))->assertOk()->assertSee('PR-SEARCH');
    }

    public static function operatorRoles(): array
    {
        return [['finance'], ['purchasing']];
    }

    #[DataProvider('operatorRoles')]
    public function test_operator_master_search_and_detail_show_pr_without_bypassing_status_filter(string $role): void
    {
        $own = $this->po('OPERATOR-OPEN', 'PR-MASTER');
        $this->po('OPERATOR-CLOSED', 'PR-MASTER', ['status' => 'CLOSED']);
        $actor = User::factory()->create(['role' => $role]);
        $this->actingAs($actor)->get(route($role.'.local-procurement.index', ['q' => 'pr-master', 'status' => 'OPEN']))
            ->assertOk()->assertViewHas('purchaseOrders', fn ($orders) => $orders->pluck('id')->all() === [$own->id])->assertSee('PR-MASTER');
        $this->get(route($role.'.local-procurement.show', $own))->assertOk()->assertSee('PR-MASTER');
    }

    public function test_new_and_revision_invoice_forms_display_pr_and_preserve_closed_current_po(): void
    {
        $open = $this->po('FORM-OPEN', 'PR-FORM-OPEN');
        $this->gr($open, 'FORM-OPEN-GR');
        $closed = $this->po('FORM-CLOSED', 'PR-FORM-CLOSED', ['status' => 'CLOSED']);
        $invoice = LocalInvoice::create(['submission_number' => 'SUB-PR-FORM', 'supplier_id' => $this->supplier->id, 'invoice_number' => 'PR-FORM-INVOICE', 'invoice_date' => '2026-10-01',
            'invoice_amount' => '100.00', 'tax_amount' => '0.00', 'ppn_scheme' => 'NON_PKP', 'local_purchase_order_id' => $closed->id,
            'po_number' => $closed->po_number, 'status' => 'NEED_REVISION', 'revision_number' => 1, 'payment_term_days_snapshot' => 30, 'submitted_at' => now()]);
        $this->gr($closed, 'FORM-CLOSED-GR', ['status' => 'RESERVED', 'current_invoice_id' => $invoice->id]);
        $this->actingAs($this->supplier)->get(route('local-supplier.invoices.create'))->assertOk()->assertSee('PR-FORM-OPEN')->assertDontSee('PR-FORM-CLOSED');
        $this->get(route('local-supplier.invoices.revision', $invoice))->assertOk()->assertSee('PR-FORM-CLOSED')
            ->assertViewHas('purchaseOrders', fn ($orders) => $orders->contains('id', $closed->id));
    }

    public function test_non_pr_header_with_populated_q_is_rejected_without_guessing_reference_a(): void
    {
        $file = $this->file([$this->row('WRONG-Q-HEADER', 'PR-UNKNOWN')], prHeader: 'Reference A');
        $service = app(LocalProcurementImportService::class);
        [$import] = $service->start($this->actor, $file, 'PO');
        $service->preview($import->id);
        $this->assertSame('VALIDATION_FAILED', $import->fresh()->status);
        $this->assertGreaterThan(0, $import->fresh()->summary['invalid']);
        $this->assertDatabaseCount('local_purchase_orders', 0);
    }

    public function test_actual_xlsx_formula_cell_in_pr_is_rejected_even_if_cached_result_is_a_number(): void
    {
        $path = Storage::disk('private')->path('formula-pr.xlsx');
        $workbook = new Spreadsheet;
        $sheet = $workbook->getActiveSheet();
        $sheet->setCellValue('Q1', 'PR');
        $sheet->setCellValue('E2', 'FORMULA-PR');
        $sheet->setCellValue('G2', 'PT PR Import Test');
        $sheet->setCellValue('J2', '2026-10-01');
        $sheet->setCellValueExplicit('K2', '1000.00', DataType::TYPE_STRING);
        $sheet->setCellValue('Q2', '=1+1');
        IOFactory::createWriter($workbook, 'Xlsx')->save($path);
        $workbook->disconnectWorksheets();
        $service = app(LocalProcurementImportService::class);
        [$import] = $service->start($this->actor, new UploadedFile($path, 'formula-pr.xlsx', null, null, true), 'PO');
        $service->preview($import->id);
        $this->assertSame('VALIDATION_FAILED', $import->fresh()->status);
        $this->assertGreaterThan(0, $import->fresh()->summary['invalid']);
        $this->assertDatabaseCount('local_purchase_orders', 0);
    }

    public function test_model_rejects_multiple_pr_values_and_does_not_erase_or_replace_a_saved_reference(): void
    {
        $po = $this->po('MODEL-PR', 'PR-ORIGINAL');
        foreach ([null, 'PR-REPLACEMENT', ['PR-A', 'PR-B']] as $value) {
            try {
                $po->fresh()->update(['pr_number' => $value]);
                $this->fail('Expected saved PR protection.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('pr_number', $exception->errors());
            }
            $this->assertSame('PR-ORIGINAL', $po->fresh()->pr_number);
        }
        $this->po('MODEL-SHARED', 'PR-ORIGINAL');
        $this->assertSame(2, LocalPurchaseOrder::where('pr_number', 'PR-ORIGINAL')->count());
    }

    public function test_migration_refuses_to_remove_populated_pr_column(): void
    {
        $this->po('ROLLBACK-GUARD', 'PR-GUARD');
        $migration = require database_path('migrations/2026_10_14_000001_add_pr_number_to_local_purchase_orders.php');
        try {
            $migration->down();
            $this->fail('Rollback must preserve populated PR references.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('PR references', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('local_purchase_orders', 'pr_number'));
        $this->assertDatabaseHas('local_purchase_orders', ['po_number' => 'ROLLBACK-GUARD', 'pr_number' => 'PR-GUARD']);
    }

    public function test_pr_is_escaped_on_detail_and_wildcard_search_is_literal(): void
    {
        $unsafe = $this->po('ESCAPED-PR', '<script>alert(1)</script>');
        $this->actingAs($this->supplier)->get(route('local-supplier.purchase-orders.show', $unsafe))
            ->assertOk()->assertSee('<script>alert(1)</script>')->assertDontSee('<script>alert(1)</script>', false);
        $literal = $this->po('LITERAL-WILDCARD', 'PR_%');
        $this->gr($literal, 'LITERAL-GR');
        $other = $this->po('WILDCARD-NEIGHBOR', 'PR-ANYTHING');
        $this->gr($other, 'WILDCARD-GR');
        $this->getJson(route('local-supplier.purchase-orders.search', ['q' => 'PR_%']))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.po_number', $literal->po_number);
    }

    public function test_zero_text_pr_is_displayed_and_searchable_instead_of_empty_placeholder(): void
    {
        [$import, $token] = $this->preview([$this->row('ZERO-TEXT-PR', '0')]);
        $this->assertSame('READY', $import->fresh()->status);
        $this->save($import, $token);
        $po = LocalPurchaseOrder::where('po_number', 'ZERO-TEXT-PR')->sole();
        $this->assertSame('0', $po->pr_number);
        $this->gr($po, 'ZERO-PR-GR');
        $this->actingAs($this->supplier)->getJson(route('local-supplier.purchase-orders.search', ['q' => '0']))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.pr_number', '0');
        $response = $this->get(route('local-supplier.purchase-orders.show', $po))->assertOk();
        $this->assertMatchesRegularExpression('/class="[^"]*tw-font-mono[^\"]*"[^>]*>\s*0\s*<\/span>/', $response->getContent());
        $this->get(route('local-supplier.invoices.create'))->assertOk()->assertSee(__('local_procurement.pr.label').': 0');
    }

    public function test_date_typed_q_header_is_a_validation_error_instead_of_reader_exception(): void
    {
        $path = Storage::disk('private')->path('date-header-pr.xlsx');
        $workbook = new Spreadsheet;
        $sheet = $workbook->getActiveSheet();
        $sheet->setCellValue('Q1', Date::PHPToExcel(new \DateTimeImmutable('2026-10-01')));
        $sheet->getStyle('Q1')->getNumberFormat()->setFormatCode('yyyy-mm-dd');
        $sheet->setCellValue('E2', 'DATE-HEADER-PR');
        $sheet->setCellValue('G2', 'PT PR Import Test');
        $sheet->setCellValue('J2', '2026-10-01');
        $sheet->setCellValueExplicit('K2', '1000.00', DataType::TYPE_STRING);
        $sheet->setCellValue('Q2', 'PR-GOOD');
        IOFactory::createWriter($workbook, 'Xlsx')->save($path);
        $workbook->disconnectWorksheets();
        $service = app(LocalProcurementImportService::class);
        [$import] = $service->start($this->actor, new UploadedFile($path, 'date-header-pr.xlsx', null, null, true), 'PO');
        $service->preview($import->id);
        $this->assertSame('VALIDATION_FAILED', $import->fresh()->status);
        $this->assertNull($import->fresh()->failure);
        $this->assertGreaterThan(0, $import->fresh()->summary['invalid']);
        $this->assertDatabaseCount('local_purchase_orders', 0);
    }

    public function test_pr_fill_audit_records_change_once_even_after_job_redelivery(): void
    {
        $po = $this->po('AUDIT-PR-FILL');
        [$import, $token] = $this->preview([$this->row($po->po_number, 'PR-AUDITED')]);
        $this->save($import, $token);
        $service = app(LocalProcurementImportService::class);
        $service->confirm($this->actor, $token, 'PO');
        $service->commit($import->id);
        $audits = LocalFinanceAuditLog::where('action', 'po_pr_filled')->where('auditable_type', LocalPurchaseOrder::class)->where('auditable_id', $po->id)->get();
        $this->assertCount(1, $audits);
        $this->assertSame($this->actor->id, $audits->sole()->actor_id);
        $this->assertNull($audits->sole()->before_values['pr_number']);
        $this->assertSame('PR-AUDITED', $audits->sole()->after_values['pr_number']);
        $this->assertSame(1, LocalFinanceAuditLog::where('action', 'po_import_confirmed')->count());
    }

    public function test_real_pr_views_render_in_both_locales_with_long_zero_and_empty_references(): void
    {
        config(['broadcasting.default' => 'null']);
        $longPr = 'PR-'.str_repeat('x', 97);
        $long = $this->po('UI-PR-LONG', $longPr);
        $zero = $this->po('UI-PR-ZERO', '0');
        $empty = $this->po('UI-PR-EMPTY');
        foreach ([$long, $zero, $empty] as $index => $po) {
            $this->gr($po, 'UI-PR-GR-'.$index);
        }
        $closed = $this->po('UI-PR-CLOSED', 'PR-CLOSED-00001', ['status' => 'CLOSED']);
        $invoice = LocalInvoice::create(['submission_number' => 'SUB-UI-PR', 'supplier_id' => $this->supplier->id, 'invoice_number' => 'UI-PR-INVOICE', 'invoice_date' => '2026-10-01',
            'invoice_amount' => '100.00', 'tax_amount' => '0.00', 'ppn_scheme' => 'NON_PKP', 'local_purchase_order_id' => $closed->id,
            'po_number' => $closed->po_number, 'status' => 'NEED_REVISION', 'revision_number' => 1, 'payment_term_days_snapshot' => 30, 'submitted_at' => now()]);
        $this->gr($closed, 'UI-PR-RESERVED', ['status' => 'RESERVED', 'current_invoice_id' => $invoice->id]);
        foreach (['en', 'id'] as $locale) {
            foreach (['finance' => $this->actor, 'supplier' => $this->supplier] as $role => $actor) {
                $actor->preference()->updateOrCreate([], [...config('user_preferences.defaults'), 'locale' => $locale, 'theme' => 'light']);
                $actor->unsetRelation('preference');
                $this->app->forgetInstance(RegionalDisplayFormatter::class);
                $this->actingAs($actor);
                $prefix = $role === 'supplier' ? 'local-supplier.purchase-orders' : 'finance.local-procurement';
                $pages = [
                    $role.'-index' => $this->get(route($prefix.'.index'))->assertOk()->assertSee($longPr)->assertSee(__('local_procurement.pr.empty')),
                    $role.'-detail' => $this->get(route($prefix.'.show', $long))->assertOk()->assertSee($longPr),
                ];
                if ($role === 'supplier') {
                    $pages['invoice-create'] = $this->get(route('local-supplier.invoices.create'))->assertOk()->assertSee($longPr);
                    $pages['invoice-revision'] = $this->get(route('local-supplier.invoices.revision', $invoice))->assertOk()->assertSee('PR-CLOSED-00001');
                }
                if (getenv('PO_PR_UI_FIXTURES') === '1') {
                    $root = storage_path('framework/testing/po-pr-ui');
                    File::ensureDirectoryExists($root);
                    foreach ($pages as $name => $response) {
                        $html = str_replace([url('/'), config('app.url')], '', $response->getContent());
                        File::put($root.'/'.$locale.'-'.$name.'.html', $html);
                    }
                }
            }
        }
    }

    private function gr(LocalPurchaseOrder $po, string $number, array $override = []): LocalGoodsReceipt
    {
        return LocalGoodsReceipt::create(array_merge(['local_purchase_order_id' => $po->id, 'gr_number' => $number, 'gr_date' => '2026-10-02', 'qty' => '1.0000', 'uom' => 'pcs', 'status' => 'AVAILABLE', 'source' => 'MANUAL', 'created_by' => $this->actor->id], $override));
    }

    private function preview(array $rows, string $format = 'xlsx', bool $withPrHeader = true): array
    {
        $service = app(LocalProcurementImportService::class);
        [$import, $token] = $service->start($this->actor, $this->file($rows, $format, $withPrHeader), 'PO');
        $service->preview($import->id);

        return [$import, $token];
    }

    private function save(LocalProcurementImport $import, string $token): void
    {
        $service = app(LocalProcurementImportService::class);
        $service->confirm($this->actor, $token, 'PO');
        $service->commit($import->id);
        $this->assertSame('COMPLETED', $import->fresh()->status, $import->fresh()->failure ?? '');
    }

    private function row(string $number, ?string $pr = null, array $override = []): array
    {
        return array_merge(['po_number' => $number, 'supplier_name' => 'PT PR Import Test', 'po_date' => '2026-10-01', 'po_amount' => '1000.00', 'pr_number' => $pr], $override);
    }

    private function po(string $number, ?string $pr = null, array $override = []): LocalPurchaseOrder
    {
        return LocalPurchaseOrder::create(array_merge(['po_number' => $number, 'pr_number' => $pr, 'supplier_id' => $this->supplier->id, 'po_date' => '2026-10-01', 'total_amount' => '1000.00', 'currency' => 'IDR', 'status' => 'OPEN', 'source' => 'MANUAL', 'description' => 'Preserved description'], $override));
    }

    private function file(array $rows, string $format = 'xlsx', bool $withPrHeader = true, ?string $prHeader = null): UploadedFile
    {
        $path = Storage::disk('private')->path('po-pr-'.uniqid().'.'.$format);
        $header = array_fill(0, $withPrHeader ? 17 : 16, '');
        $header[4] = 'Order';
        $header[6] = 'Supplier Name';
        $header[9] = 'Order Date';
        $header[10] = 'Order Amount';
        if ($withPrHeader) {
            $header[16] = $prHeader ?? 'PR';
        }
        $values = [$header];
        foreach ($rows as $row) {
            $cells = array_fill(0, count($header), '');
            foreach (['po_number' => 4, 'supplier_name' => 6, 'po_date' => 9, 'po_amount' => 10] as $field => $index) {
                $cells[$index] = $row[$field];
            }
            if ($withPrHeader) {
                $cells[16] = $row['pr_number'] ?? '';
            }
            $values[] = $cells;
        }
        if ($format === 'csv') {
            $stream = fopen($path, 'wb');
            foreach ($values as $cells) {
                fputcsv($stream, $cells, ',', '"', '');
            }
            fclose($stream);
        } elseif ($format === 'xls') {
            $workbook = new Spreadsheet;
            foreach ($values as $rowIndex => $cells) {
                foreach ($cells as $column => $value) {
                    $workbook->getActiveSheet()->setCellValueExplicit(Coordinate::stringFromColumnIndex($column + 1).($rowIndex + 1), (string) $value, DataType::TYPE_STRING);
                }
            }
            IOFactory::createWriter($workbook, 'Xls')->save($path);
            $workbook->disconnectWorksheets();
        } else {
            $writer = new Writer;
            $writer->openToFile($path);
            foreach ($values as $cells) {
                $writer->addRow(Row::fromValues($cells));
            }
            $writer->close();
        }

        return new UploadedFile($path, 'po-pr.'.$format, null, null, true);
    }
}
