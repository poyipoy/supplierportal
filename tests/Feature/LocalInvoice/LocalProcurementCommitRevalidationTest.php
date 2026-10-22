<?php

namespace Tests\Feature\LocalInvoice;

use App\Models\LocalFinanceAuditLog;
use App\Models\LocalProcurementImport;
use App\Models\LocalPurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierScope;
use App\Models\User;
use App\Services\LocalInvoice\LocalProcurementImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

/**
 * Characterizes what the PO confirm stage rejects when master data changes
 * between preview and confirm. Every case must leave zero new POs and zero
 * creation audits, end VALIDATION_FAILED, and release the active slot.
 */
class LocalProcurementCommitRevalidationTest extends TestCase
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
        $this->supplier = $this->localSupplier('PT Revalidation');
    }

    public function test_supplier_renamed_after_preview_is_rejected_at_commit(): void
    {
        [$import, $token] = $this->readyPoImport(2);
        Supplier::where('user_id', $this->supplier->id)->update(['company_name' => 'PT Renamed']);

        $this->confirmAndCommit($import, $token);

        $this->assertRejected($import, __('local_procurement.import.supplier_exact', ['name' => 'PT Revalidation']));
    }

    public function test_second_supplier_with_the_same_name_after_preview_is_rejected_as_ambiguous(): void
    {
        [$import, $token] = $this->readyPoImport(2);
        $this->localSupplier('PT Revalidation');

        $this->confirmAndCommit($import, $token);

        $this->assertRejected($import, __('local_procurement.import.supplier_ambiguous', ['name' => 'PT Revalidation']));
    }

    public function test_supplier_losing_local_scope_or_active_account_after_preview_is_rejected(): void
    {
        foreach ([
            fn () => SupplierScope::where('supplier_id', $this->supplier->id)->delete(),
            fn () => $this->supplier->forceFill(['account_status' => User::ACCOUNT_STATUS_PENDING])->save(),
            fn () => $this->supplier->update(['is_active' => false]),
        ] as $index => $mutation) {
            $this->supplier = $this->localSupplier('PT Revalidation '.$index);
            [$import, $token] = $this->readyPoImport(1, 'PT Revalidation '.$index, 'REVAL-PO-SCOPE-'.$index.'-');
            $mutation();

            $this->confirmAndCommit($import, $token);

            $this->assertRejected($import, __('local_procurement.import.supplier_exact', ['name' => 'PT Revalidation '.$index]));
        }
    }

    public function test_conflicting_po_registered_after_preview_is_rejected(): void
    {
        [$import, $token] = $this->readyPoImport(2);
        $this->registerPo('REVAL-PO-1', ['total_amount' => '999.00']);

        $this->confirmAndCommit($import, $token);

        $this->assertRejected($import, __('local_procurement.import.po_existing_conflict', ['po' => 'REVAL-PO-1']), existing: 1);
    }

    public function test_cancelled_po_registered_after_preview_is_rejected(): void
    {
        [$import, $token] = $this->readyPoImport(2);
        $this->registerPo('REVAL-PO-0', ['status' => LocalPurchaseOrder::STATUS_CANCELLED]);

        $this->confirmAndCommit($import, $token);

        $this->assertRejected($import, __('local_procurement.import.po_cancelled', ['po' => 'REVAL-PO-0', 'row' => 2]), existing: 1);
    }

    public function test_different_pr_registered_after_preview_is_rejected(): void
    {
        [$import, $token] = $this->readyPoImport(2, pr: 'PR-FILE');
        $this->registerPo('REVAL-PO-1', ['pr_number' => 'PR-OTHER']);

        $this->confirmAndCommit($import, $token);

        $this->assertRejected($import, __('local_procurement.pr_import.conflict', ['po' => 'REVAL-PO-1']), existing: 1);
    }

    public function test_every_conflict_in_a_batch_is_reported_together(): void
    {
        [$import, $token] = $this->readyPoImport(3, pr: 'PR-FILE');
        $this->registerPo('REVAL-PO-0', ['status' => LocalPurchaseOrder::STATUS_CANCELLED]);
        $this->registerPo('REVAL-PO-1', ['total_amount' => '999.00']);
        $this->registerPo('REVAL-PO-2', ['pr_number' => 'PR-OTHER']);

        $this->confirmAndCommit($import, $token);

        foreach ([
            __('local_procurement.import.po_cancelled', ['po' => 'REVAL-PO-0', 'row' => 2]),
            __('local_procurement.import.po_existing_conflict', ['po' => 'REVAL-PO-1']),
            __('local_procurement.pr_import.conflict', ['po' => 'REVAL-PO-2']),
        ] as $message) {
            $this->assertRejected($import, $message, existing: 3);
        }
    }

    public function test_identical_po_registered_after_preview_is_skipped_and_new_rows_are_created(): void
    {
        [$import, $token] = $this->readyPoImport(2);
        $this->registerPo('REVAL-PO-1');

        $this->confirmAndCommit($import, $token);

        $import->refresh();
        $this->assertSame('COMPLETED', $import->status, $import->failure ?? '');
        $this->assertSame(1, $import->summary['new_po']);
        $this->assertSame(1, $import->summary['existing_po']);
        $this->assertSame(1, LocalFinanceAuditLog::where('action', 'po_created')->count());
    }

    public function test_supplier_name_is_resolved_again_at_commit(): void
    {
        // Characterizes existing behavior: the supplier is matched by the name in
        // the file at commit time, not by the identity resolved during preview.
        [$import, $token] = $this->readyPoImport(1);
        $replacement = $this->localSupplier('PT Interim');
        Supplier::where('user_id', $this->supplier->id)->update(['company_name' => 'PT Previous']);
        Supplier::where('user_id', $replacement->id)->update(['company_name' => 'PT Revalidation']);

        $this->confirmAndCommit($import, $token);

        $this->assertSame('COMPLETED', $import->fresh()->status, $import->fresh()->failure ?? '');
        $this->assertSame($replacement->id, LocalPurchaseOrder::where('po_number', 'REVAL-PO-0')->value('supplier_id'));
    }

    public function test_po_confirm_issues_at_most_three_po_lookups_per_batch(): void
    {
        [$import, $token] = $this->readyPoImport(1001);
        $lookups = 0;
        DB::listen(function ($query) use (&$lookups) {
            if (str_starts_with($query->sql, 'select') && str_contains($query->sql, 'from `local_purchase_orders`')) {
                $lookups++;
            }
        });

        $this->confirmAndCommit($import, $token);

        $this->assertSame('COMPLETED', $import->fresh()->status, $import->fresh()->failure ?? '');
        $this->assertSame(1001, LocalFinanceAuditLog::where('action', 'po_created')->count());
        // Three 500-row batches: global lock sweep, locked batch map, post-insert reload.
        $this->assertLessThanOrEqual(9, $lookups);
    }

    public function test_po_confirm_locks_supplier_users_before_registered_pos(): void
    {
        // InvoiceSubmissionService locks users(supplier) and then the PO. The PO
        // import must take the same order, or the two deadlock.
        $this->registerPo('REVAL-PO-0');
        [$import, $token] = $this->readyPoImport(2);
        $locks = [];
        DB::listen(function ($query) use (&$locks) {
            if (str_contains($query->sql, 'from `users`') && str_contains($query->sql, 'lock in share mode')
                && in_array($this->supplier->id, $query->bindings, false)) {
                $locks[] = 'supplier-user';
            } elseif (str_contains($query->sql, 'from `local_purchase_orders`') && str_contains($query->sql, 'for update')) {
                $locks[] = 'po';
            }
        });

        $this->confirmAndCommit($import, $token);

        $this->assertSame('COMPLETED', $import->fresh()->status, $import->fresh()->failure ?? '');
        $this->assertContains('po', $locks);
        $this->assertSame('supplier-user', $locks[0]);
    }

    public function test_confirm_reads_staged_records_by_bounded_primary_key_range(): void
    {
        [$import, $token] = $this->readyPoImport(1001);
        $reads = [];
        DB::listen(function ($query) use (&$reads) {
            if (str_starts_with($query->sql, 'select *') && str_contains($query->sql, 'from `local_procurement_import_records`')) {
                $reads[] = $query->sql;
            }
        });

        $this->confirmAndCommit($import, $token);

        $this->assertSame('COMPLETED', $import->fresh()->status, $import->fresh()->failure ?? '');
        $this->assertSame(1001, LocalPurchaseOrder::where('po_number', 'like', 'REVAL-PO-%')->count());
        $this->assertCount(3, $reads);
        foreach ($reads as $sql) {
            $this->assertStringContainsString('force index (PRIMARY)', $sql);
            $this->assertStringContainsString('`id` <= ?', $sql);
        }
    }

    private function confirmAndCommit(LocalProcurementImport $import, string $token): void
    {
        $service = app(LocalProcurementImportService::class);
        $service->confirm($this->actor, $token, 'PO');
        $service->commit($import->id);
    }

    private function assertRejected(LocalProcurementImport $import, string $message, int $existing = 0): void
    {
        $import->refresh();
        $this->assertSame('VALIDATION_FAILED', $import->status);
        $this->assertStringContainsString($message, (string) $import->failure);
        $this->assertNotContains($import->status, LocalProcurementImport::ACTIVE);
        $this->assertSame($existing, LocalPurchaseOrder::where('po_number', 'like', 'REVAL-PO-%')->count());
        $this->assertSame(0, LocalFinanceAuditLog::whereIn('action', ['po_created', 'po_pr_filled', 'po_import_confirmed'])->count());
    }

    private function readyPoImport(int $count, string $supplierName = 'PT Revalidation', string $prefix = 'REVAL-PO-', ?string $pr = null): array
    {
        $service = app(LocalProcurementImportService::class);
        [$import, $token] = $service->start($this->actor, $this->workbook($count, $supplierName, $prefix, $pr), 'PO');
        $service->preview($import->id);
        $this->assertSame('READY', $import->fresh()->status, $import->fresh()->failure ?? '');

        return [$import, $token];
    }

    private function registerPo(string $number, array $overrides = []): LocalPurchaseOrder
    {
        return LocalPurchaseOrder::create(array_merge(['po_number' => $number, 'supplier_id' => $this->supplier->id,
            'po_date' => '2026-10-01', 'total_amount' => '100.00', 'currency' => 'IDR', 'status' => 'OPEN', 'source' => 'MANUAL'], $overrides));
    }

    private function localSupplier(string $name): User
    {
        $supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        SupplierScope::create(['supplier_id' => $supplier->id, 'scope' => 'local']);
        Supplier::create(['user_id' => $supplier->id, 'company_name' => $name, 'category' => 'Parts', 'is_pkp' => false, 'payment_term_days' => 30]);

        return $supplier;
    }

    private function workbook(int $count, string $supplierName, string $prefix, ?string $pr): UploadedFile
    {
        $path = Storage::disk('private')->path('revalidation-'.uniqid().'.xlsx');
        $writer = new Writer;
        $writer->openToFile($path);
        $header = array_fill(0, 21, '');
        $header[16] = 'PR';
        $writer->addRow(Row::fromValues($header));
        for ($i = 0; $i < $count; $i++) {
            $row = array_fill(0, 21, null);
            $row[4] = $prefix.$i;
            $row[6] = $supplierName;
            $row[9] = '2026-10-01';
            $row[10] = '100.00';
            $row[16] = $pr;
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();

        return new UploadedFile($path, 'revalidation.xlsx', null, null, true);
    }
}
