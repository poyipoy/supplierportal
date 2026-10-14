<?php

namespace Tests\Feature\LocalInvoice;

use App\Models\LocalFinanceAuditLog;
use App\Models\LocalGoodsReceipt;
use App\Models\LocalProcurementImport;
use App\Models\LocalPurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierScope;
use App\Models\User;
use App\Services\LocalInvoice\LocalGrImportService;
use App\Services\LocalInvoice\LocalPoImportService;
use App\Services\LocalInvoice\LocalProcurementImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CancelledLocalProcurementImportTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private User $supplier;

    private LocalPurchaseOrder $po;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        Queue::fake();
        $this->actor = User::factory()->create(['role' => 'finance', 'is_active' => true]);
        $this->supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        SupplierScope::create(['supplier_id' => $this->supplier->id, 'scope' => 'local']);
        Supplier::create(['user_id' => $this->supplier->id, 'company_name' => 'PT Restore Import', 'category' => 'Parts']);
        $this->po = LocalPurchaseOrder::create([
            'po_number' => 'RESTORE-PO', 'supplier_id' => $this->supplier->id, 'po_date' => '2026-10-01',
            'total_amount' => '1000.00', 'currency' => 'IDR', 'status' => 'OPEN', 'source' => 'IMPORT',
        ]);
    }

    public static function cancelledCases(): array
    {
        $cases = [];
        foreach (['PO', 'GR'] as $kind) {
            foreach ([false, true] as $different) {
                foreach (['en', 'id'] as $locale) {
                    $cases[$kind.' '.($different ? 'different' : 'identical').' '.$locale] = [$kind, $different, $locale];
                }
            }
        }

        return $cases;
    }

    public static function kinds(): array
    {
        return [['PO'], ['GR']];
    }

    #[DataProvider('cancelledCases')]
    public function test_cancelled_identity_is_a_restore_error_even_if_contents_differ(string $kind, bool $different, string $locale): void
    {
        app()->setLocale($locale);
        $existing = $this->existing($kind, 'CANCELLED');
        $before = $existing->fresh()->getAttributes();
        $row = $this->row($kind);
        $row[$kind === 'PO' ? 'po_amount' : 'qty'] = $different ? '2000.00' : $row[$kind === 'PO' ? 'po_amount' : 'qty'];
        $result = $this->validator($kind)->validate([$row]);

        $this->assertFalse($result['success']);
        $this->assertSame(0, $result['summary']['existing_'.strtolower($kind)]);
        $error = collect($result['errors'])->firstWhere('column', strtolower($kind).'_number');
        $this->assertNotNull($error);
        $this->assertSame(7, $error['row']);
        $this->assertSame($this->cancelledMessage($kind, 7), $error['message']);
        $this->assertStringContainsString($kind === 'PO' ? 'RESTORE-PO' : 'RESTORE-GR', $error['message']);
        $this->assertStringContainsString('7', $error['message']);
        $this->assertSame($before, $existing->fresh()->getAttributes());
    }

    #[DataProvider('kinds')]
    public function test_cancelled_row_blocks_the_entire_synchronous_import(string $kind): void
    {
        $existing = $this->existing($kind, 'CANCELLED');
        $rows = [$this->row($kind, true), $this->row($kind)];
        try {
            $this->validator($kind)->import($this->actor, $rows);
            $this->fail('Cancelled identity must block every write in this file.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString($this->cancelledMessage($kind, 7), implode(' ', $exception->errors()['import_file']));
        }
        $this->assertSame('CANCELLED', $existing->fresh()->status);
        $this->assertDatabaseMissing($kind === 'PO' ? 'local_purchase_orders' : 'local_goods_receipts', [strtolower($kind).'_number' => 'NEW-'.$kind]);
        $this->assertSame(0, LocalFinanceAuditLog::count());
    }

    #[DataProvider('kinds')]
    public function test_identical_active_identity_still_skips_existing_and_imports_new_rows(string $kind): void
    {
        $this->existing($kind, $kind === 'PO' ? 'OPEN' : 'AVAILABLE');
        $result = $this->validator($kind)->import($this->actor, [$this->row($kind), $this->row($kind, true)]);
        $this->assertSame(1, $result['existing'.ucfirst(strtolower($kind))]);
        $this->assertSame(1, $result['new'.ucfirst(strtolower($kind))]);
    }

    public function test_cancelled_parent_is_distinguished_from_closed_parent_for_gr(): void
    {
        $this->po->update(['status' => 'CANCELLED']);
        $result = app(LocalGrImportService::class)->validate([$this->row('GR')]);
        $this->assertFalse($result['success']);
        $this->assertSame(__('local_procurement.import.gr_cancelled_po', ['po' => 'RESTORE-PO', 'row' => 7]), $result['errors'][0]['message']);
        $this->assertStringContainsString('RESTORE-PO', $result['errors'][0]['message']);
        $this->po->update(['status' => 'CLOSED']);
        $closed = app(LocalGrImportService::class)->validate([$this->row('GR')]);
        $this->assertSame(__('local_procurement.import.gr_closed_po', ['po' => 'RESTORE-PO']), $closed['errors'][0]['message']);
    }

    public function test_cancelled_gr_and_parent_both_report_their_restore_requirements(): void
    {
        $this->existing('GR', 'CANCELLED');
        $this->po->update(['status' => 'CANCELLED']);
        $result = app(LocalGrImportService::class)->validate([$this->row('GR')]);
        $this->assertFalse($result['success']);
        $messages = array_column($result['errors'], 'message');
        $this->assertContains($this->cancelledMessage('GR', 7), $messages);
        $this->assertContains(__('local_procurement.import.gr_cancelled_po', ['po' => 'RESTORE-PO', 'row' => 7]), $messages);
    }

    #[DataProvider('kinds')]
    public function test_cancelled_identity_blocks_http_preview_and_confirmation(string $kind): void
    {
        $existing = $this->existing($kind, 'CANCELLED');
        $response = $this->actingAs($this->actor)->postJson(route('finance.local-procurement.import.'.strtolower($kind).'.preview'), ['import_file' => $this->workbook($kind)]);
        $response->assertStatus(202);
        $import = LocalProcurementImport::firstOrFail();
        app(LocalProcurementImportService::class)->preview($import->id);
        $this->getJson($response->json('status_url'))->assertOk()->assertJsonPath('status', 'VALIDATION_FAILED');
        $this->assertGreaterThan(0, $import->fresh()->summary['invalid']);
        $this->assertSame(0, $import->fresh()->summary['existing_'.strtolower($kind)]);
        $status = $this->getJson($response->json('status_url'))->assertOk();
        $errors = $this->getJson($status->json('errors_url'))->assertOk()->json('data');
        $this->assertContains($this->cancelledMessage($kind, 3), array_column($errors, 'message'));
        $this->postJson(route('finance.local-procurement.import.'.strtolower($kind).'.confirm'), ['token' => $response->json('token')])->assertStatus(409);
        $this->assertSame('CANCELLED', $existing->fresh()->status);
        $this->assertDatabaseMissing($kind === 'PO' ? 'local_purchase_orders' : 'local_goods_receipts', [strtolower($kind).'_number' => 'NEW-'.$kind]);
    }

    #[DataProvider('kinds')]
    public function test_cancellation_after_preview_blocks_worker_and_rolls_back_new_rows(string $kind): void
    {
        $existing = $this->existing($kind, $kind === 'PO' ? 'OPEN' : 'AVAILABLE');
        $service = app(LocalProcurementImportService::class);
        [$import, $token] = $service->start($this->actor, $this->workbook($kind), $kind);
        $service->preview($import->id);
        $this->assertSame('READY', $import->fresh()->status);
        $existing->update(['status' => 'CANCELLED']);
        $auditCount = LocalFinanceAuditLog::count();
        $service->confirm($this->actor, $token, $kind);
        $service->commit($import->id);
        $this->assertSame('VALIDATION_FAILED', $import->fresh()->status);
        $this->assertStringContainsString($this->cancelledMessage($kind, 3), $import->fresh()->failure);
        $this->assertSame('CANCELLED', $existing->fresh()->status);
        $this->assertDatabaseMissing($kind === 'PO' ? 'local_purchase_orders' : 'local_goods_receipts', [strtolower($kind).'_number' => 'NEW-'.$kind]);
        $this->assertSame($auditCount, LocalFinanceAuditLog::count());
    }

    private function validator(string $kind): LocalPoImportService|LocalGrImportService
    {
        return app($kind === 'PO' ? LocalPoImportService::class : LocalGrImportService::class);
    }

    private function existing(string $kind, string $status): LocalPurchaseOrder|LocalGoodsReceipt
    {
        if ($kind === 'PO') {
            $this->po->update(['status' => $status]);

            return $this->po;
        }

        return LocalGoodsReceipt::create([
            'local_purchase_order_id' => $this->po->id, 'gr_number' => 'RESTORE-GR', 'gr_date' => '2026-10-01',
            'qty' => '1', 'uom' => 'pcs', 'status' => $status, 'source' => 'IMPORT',
        ]);
    }

    private function row(string $kind, bool $new = false): array
    {
        if ($kind === 'PO') {
            return ['_row' => $new ? 2 : 7, 'po_number' => $new ? 'NEW-PO' : 'RESTORE-PO', 'supplier_name' => 'PT Restore Import', 'po_date' => '2026-10-01', 'po_amount' => '1000.00'];
        }

        return ['_row' => $new ? 2 : 7, 'gr_number' => $new ? 'NEW-GR' : 'RESTORE-GR', 'po_number' => 'RESTORE-PO', 'gr_date' => '2026-10-01', 'qty' => '1', 'uom' => 'pcs'];
    }

    private function cancelledMessage(string $kind, int $row): string
    {
        return __('local_procurement.import.'.strtolower($kind).'_cancelled', [strtolower($kind) => $kind === 'PO' ? 'RESTORE-PO' : 'RESTORE-GR', 'row' => $row]);
    }

    private function workbook(string $kind): UploadedFile
    {
        $path = Storage::disk('private')->path('restore-import-'.uniqid().'.xlsx');
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(array_fill(0, 21, '')));
        foreach ([true, false] as $new) {
            $values = $this->row($kind, $new);
            $row = array_fill(0, 21, null);
            if ($kind === 'PO') {
                $row[4] = $values['po_number'];
                $row[6] = $values['supplier_name'];
                $row[9] = $values['po_date'];
                $row[10] = $values['po_amount'];
            } else {
                $row[9] = $values['gr_number'];
                $row[11] = $values['po_number'];
                $row[15] = $values['qty'];
                $row[16] = $values['uom'];
                $row[19] = $values['gr_date'];
            }
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();

        return new UploadedFile($path, 'restore-import.xlsx', null, null, true);
    }
}
