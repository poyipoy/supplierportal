<?php

namespace Tests\Feature\LocalInvoice;

use App\Exceptions\ImportBudgetExceeded;
use App\Jobs\ProcessLocalProcurementImport;
use App\Models\LocalFinanceAuditLog;
use App\Models\LocalGoodsReceipt;
use App\Models\LocalProcurementImport;
use App\Models\LocalPurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierScope;
use App\Models\User;
use App\Services\LocalInvoice\LocalProcurementImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

/**
 * A confirm transaction that runs past `commit_seconds` must roll back every
 * master and audit row, end FAILED once, and not be retried. Other failures
 * keep the queue's normal retry behavior.
 */
class LocalProcurementCommitBudgetTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        $this->actor = User::factory()->create(['role' => 'finance', 'is_active' => true]);
        $supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        SupplierScope::create(['supplier_id' => $supplier->id, 'scope' => 'local']);
        Supplier::create(['user_id' => $supplier->id, 'company_name' => 'PT Budget', 'category' => 'Parts', 'is_pkp' => false, 'payment_term_days' => 30]);
        LocalPurchaseOrder::create(['po_number' => 'BUDGET-PARENT', 'supplier_id' => $supplier->id, 'po_date' => '2026-10-01',
            'total_amount' => '1000000.00', 'currency' => 'IDR', 'status' => 'OPEN', 'source' => 'MANUAL']);
    }

    public function test_budget_overrun_rolls_back_and_marks_failed(): void
    {
        foreach (['PO', 'GR'] as $kind) {
            Queue::fake();
            config(['local_procurement_imports.commit_seconds' => 60]);
            $service = app(LocalProcurementImportService::class);
            [$import, $token] = $service->start($this->actor, $this->workbook($kind, 3), $kind);
            $service->preview($import->id);
            $this->assertSame('READY', $import->fresh()->status, $import->fresh()->failure ?? '');
            $service->confirm($this->actor, $token, $kind);
            // PO: the first check runs after the first write batch.
            // GR: after the parent PO has been locked.
            config(['local_procurement_imports.commit_seconds' => -1]);

            try {
                $service->commit($import->id);
                $this->fail('Expected the commit budget to be exceeded');
            } catch (ImportBudgetExceeded) {
            }

            $import->refresh();
            $this->assertSame('FAILED', $import->status);
            $this->assertSame(__('local_procurement.large_import.budget_exceeded'), $import->failure);
            $this->assertNotNull($import->finished_at);
            $this->assertNotContains($import->status, LocalProcurementImport::ACTIVE);
        }
        $this->assertSame(1, LocalPurchaseOrder::count());
        $this->assertSame(0, LocalGoodsReceipt::count());
        $this->assertSame(0, LocalFinanceAuditLog::whereIn('action', ['po_created', 'gr_created', 'po_import_confirmed', 'gr_import_confirmed'])->count());

        // The failed imports no longer hold an active slot.
        config(['local_procurement_imports.active_per_user' => 1]);
        [$next] = app(LocalProcurementImportService::class)->start($this->actor, $this->workbook('GR', 1), 'GR');
        $this->assertSame('QUEUED', $next->status);
    }

    public function test_budget_overrun_on_the_database_queue_fails_once_without_retry(): void
    {
        config(['queue.default' => 'database']);
        $service = app(LocalProcurementImportService::class);
        [$import, $token] = $service->start($this->actor, $this->workbook('GR', 3), 'GR');
        $this->work();
        $this->assertSame('READY', $import->fresh()->status, $import->fresh()->failure ?? '');
        $service->confirm($this->actor, $token, 'GR');
        config(['local_procurement_imports.commit_seconds' => -1]);

        $this->work();

        $import->refresh();
        $this->assertSame('FAILED', $import->status);
        $this->assertSame(__('local_procurement.large_import.budget_exceeded'), $import->failure);
        $this->assertSame(0, DB::table('jobs')->where('queue', 'imports')->count(), 'The delivery must not be released for a retry.');
        $this->assertSame(1, DB::table('failed_jobs')->count());
        $this->assertSame(0, LocalGoodsReceipt::count());
        $this->assertSame(0, LocalFinanceAuditLog::where('action', 'gr_created')->count());
    }

    public function test_other_commit_failures_keep_the_retry_path(): void
    {
        Queue::fake();
        $service = app(LocalProcurementImportService::class);
        [$import, $token] = $service->start($this->actor, $this->workbook('GR', 3), 'GR');
        $service->preview($import->id);
        $service->confirm($this->actor, $token, 'GR');
        $job = Queue::pushed(ProcessLocalProcurementImport::class, fn ($job) => $job->stage === 'confirm')->first();
        DB::listen(function ($query) {
            if (str_contains($query->sql, 'insert into `local_goods_receipts`')) {
                throw new \RuntimeException('Injected transient failure');
            }
        });

        try {
            $job->handle($service);
            $this->fail('A non-budget failure must reach the queue so it can be retried');
        } catch (\RuntimeException $exception) {
            $this->assertNotInstanceOf(ImportBudgetExceeded::class, $exception);
            $this->assertSame('Injected transient failure', $exception->getMessage());
        }

        $this->assertSame('IMPORTING', $import->fresh()->status);
        $this->assertSame(0, LocalGoodsReceipt::count());
    }

    public function test_budget_handling_never_overwrites_a_completed_import(): void
    {
        Queue::fake();
        $service = app(LocalProcurementImportService::class);
        [$import, $token] = $service->start($this->actor, $this->workbook('GR', 3), 'GR');
        $service->preview($import->id);
        $service->confirm($this->actor, $token, 'GR');
        $service->commit($import->id);
        $this->assertSame('COMPLETED', $import->fresh()->status);

        config(['local_procurement_imports.commit_seconds' => -1]);
        $service->commit($import->id);

        $this->assertSame('COMPLETED', $import->fresh()->status);
        $this->assertSame(3, LocalGoodsReceipt::count());
    }

    private function work(): void
    {
        Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'imports', '--once' => true, '--tries' => 3, '--sleep' => 0]);
    }

    private function workbook(string $kind, int $count): UploadedFile
    {
        $path = Storage::disk('private')->path('budget-'.uniqid().'.xlsx');
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(array_fill(0, 21, '')));
        for ($i = 0; $i < $count; $i++) {
            $row = array_fill(0, 21, null);
            if ($kind === 'PO') {
                $row[4] = 'BUDGET-PO-'.$i;
                $row[6] = 'PT Budget';
                $row[9] = '2026-10-01';
                $row[10] = '100.00';
            } else {
                $row[8] = 'Budget material';
                $row[9] = 'BUDGET-GR-'.uniqid().'-'.$i;
                $row[11] = 'BUDGET-PARENT';
                $row[15] = 1;
                $row[16] = 'pcs';
                $row[19] = '2026-10-01';
            }
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();

        return new UploadedFile($path, 'budget.xlsx', null, null, true);
    }
}
