<?php

namespace Tests\Feature\SupplierAudit;

use App\Exports\SupplierAuditExport;
use App\Jobs\ProcessExportJob;
use App\Models\ExportJob;
use App\Models\SupplierAudit;
use App\Models\User;
use App\Services\SupplierAudit\SupplierAuditAnswerService;
use App\Services\SupplierAudit\SupplierAuditReviewService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use RuntimeException;

class SupplierAuditExportTest extends SupplierAuditTestCase
{
    public function test_export_is_only_available_for_submitted_or_published_audits(): void
    {
        Queue::fake();
        $supplier = $this->supplier();
        $audit = $this->assign($supplier);
        $url = route('purchasing.export.supplier-audits.detail', $audit);

        $this->actingAs($this->purchasing)->getJson($url)->assertForbidden();

        $this->submitComplete($audit, $supplier);
        app(SupplierAuditReviewService::class)->requestRevision($this->purchasing, $audit->fresh(), 'Ulang');
        $this->actingAs($this->purchasing)->getJson($url)->assertForbidden();

        $cancelled = $this->assign($this->supplier());
        app(SupplierAuditReviewService::class)->cancel($this->purchasing, $cancelled, 'Batal');
        $this->actingAs($this->purchasing)->getJson(route('purchasing.export.supplier-audits.detail', $cancelled))->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_submitted_audit_export_is_queued_for_requesting_purchasing_user(): void
    {
        Queue::fake();
        $supplier = $this->supplier();
        $audit = $this->submitComplete($this->assign($supplier), $supplier);

        $this->actingAs($this->purchasing)->getJson(route('purchasing.export.supplier-audits.detail', $audit))
            ->assertStatus(202)
            ->assertJsonStructure(['message', 'export_job_id', 'status_url']);

        $job = ExportJob::latest('id')->first();
        $this->assertSame($this->purchasing->id, (int) $job->user_id);
        $this->assertSame(SupplierAuditExport::class, $job->export_class);
        $this->assertStringEndsWith('.xlsx', $job->file_name);
        Queue::assertPushed(ProcessExportJob::class);

        $this->actingAs($supplier)->getJson(route('purchasing.export.supplier-audits.detail', $audit))->assertForbidden();
    }

    public function test_workbook_mirrors_template_layout_with_four_sheets(): void
    {
        $supplier = $this->supplier();
        $audit = $this->assign($supplier);
        $answers = $this->completeAnswers($audit, 'YES', 3);
        $ids = array_keys($answers);
        $answers[$ids[1]] = ['answer' => 'NO', 'score' => null];
        app(SupplierAuditAnswerService::class)->save($supplier, $audit, $answers, true);

        $spreadsheet = $this->generate($audit->fresh());

        $this->assertSame(['1', '2', '3', '4'], $spreadsheet->getSheetNames());

        $first = $spreadsheet->getSheet(0);
        $this->assertStringContainsString('FORM CHECKLIST AUDIT SUPPLIER & VENDOR', (string) $first->getCell('A1')->getValue());
        $vendorLine = (string) $first->getCell('A5')->getValue();
        $this->assertStringContainsString('Vendor '.$supplier->id, $vendorLine);
        $this->assertStringContainsString('2026 Semester II', $vendorLine);
        $this->assertStringNotContainsString('#REF', $vendorLine);
        $this->assertSame(__('supplier_audit.export.columns.point'), $first->getCell('G6')->getValue());

        // Baris data pertama: judul bagian 1, lalu kriteria 1.1 (Ya, score 3) dan 1.2 (Tidak).
        $this->assertSame('1', (string) $first->getCell('A7')->getValue());
        $this->assertSame('Kebijakan LK3', $first->getCell('B7')->getValue());
        $this->assertSame(1, (int) $first->getCell('B8')->getValue());
        $this->assertSame('✓', $first->getCell('D8')->getValue());
        $this->assertSame('', (string) $first->getCell('E8')->getValue());
        $this->assertSame(3, (int) $first->getCell('F8')->getValue());
        $this->assertNull($first->getCell('G8')->getValue());
        $this->assertNull($first->getCell('H8')->getValue());
        $this->assertSame('✓', $first->getCell('E9')->getValue());
        $this->assertNull($first->getCell('F9')->getValue());

        // Sub Total kelompok 1 (11 kriteria: baris 8–18) ada di baris 19, sama seperti template.
        $this->assertSame(__('supplier_audit.export.sub_total'), $first->getCell('A19')->getValue());
        $this->assertSame('=SUM(G8:G18)', $first->getCell('G19')->getValue());

        $subTotals = 0;
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $value = $sheet->getCell('G'.$row->getRowIndex())->getValue();
                if (is_string($value) && str_starts_with($value, '=SUM(')) {
                    $subTotals++;
                    $this->assertStringNotContainsString('!', $value, 'SUM must not reference other sheets');
                }
            }
        }
        $this->assertSame(17, $subTotals);

        $second = $spreadsheet->getSheet(1);
        $this->assertStringContainsString('Vendor '.$supplier->id, (string) $second->getCell('A1')->getValue());
        $this->assertSame(__('supplier_audit.export.columns.criterion'), $second->getCell('B2')->getValue());
        $this->assertSame('5', (string) $second->getCell('A3')->getValue());
    }

    public function test_worker_rejects_non_purchasing_actor(): void
    {
        $supplier = $this->supplier();
        $audit = $this->submitComplete($this->assign($supplier), $supplier);
        $finance = User::factory()->create(['role' => 'finance']);

        $this->expectException(RuntimeException::class);
        (new SupplierAuditExport($finance->id, $audit->id))->generateWorkbook('exports/test.xlsx', 'private');
    }

    private function generate(SupplierAudit $audit): Spreadsheet
    {
        (new SupplierAuditExport($this->purchasing->id, $audit->id))->generateWorkbook('exports/audit.xlsx', 'private');
        $path = tempnam(sys_get_temp_dir(), 'audit_test_');
        file_put_contents($path, Storage::disk('private')->get('exports/audit.xlsx'));

        try {
            return IOFactory::load($path);
        } finally {
            @unlink($path);
        }
    }
}
