<?php

namespace Tests\Feature\SupplierAudit;

use App\Models\SupplierAudit;
use App\Models\SupplierAuditCriterion;
use App\Models\SupplierAuditSection;
use App\Models\SupplierAuditTemplate;
use App\Support\StatusHelper;
use Database\Seeders\SupplierAuditTemplateSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

class SupplierAuditTemplateAndModelTest extends SupplierAuditTestCase
{
    public static function activeStatuses(): array
    {
        return array_map(fn ($status) => [$status], SupplierAudit::ACTIVE_STATUSES);
    }

    #[DataProvider('activeStatuses')]
    public function test_database_rejects_a_second_active_audit_even_without_assignment_service(string $status): void
    {
        $audit = $this->assign($this->supplier());
        $copy = $audit->getAttributes();
        unset($copy['id'], $copy['active_supplier_id']);
        $copy['status'] = $status;
        $this->expectException(QueryException::class);
        DB::table('supplier_audits')->insert($copy);
    }

    public function test_terminal_audits_do_not_block_new_assignments_and_history_remains_available(): void
    {
        $supplier = $this->supplier();
        $first = $this->assign($supplier);
        $first->update(['status' => SupplierAudit::STATUS_CANCELLED]);
        $second = $this->assign($supplier);
        $second->update(['status' => SupplierAudit::STATUS_RESULT_PUBLISHED]);
        $third = $this->assign($supplier);

        $this->assertSame(3, SupplierAudit::where('supplier_id', $supplier->id)->count());
        $this->assertNull($first->fresh()->active_supplier_id);
        $this->assertNull($second->fresh()->active_supplier_id);
        $this->assertSame((int) $supplier->id, (int) $third->fresh()->active_supplier_id);
    }

    public function test_reactivating_a_terminal_audit_cannot_create_two_active_audits(): void
    {
        $supplier = $this->supplier();
        $first = $this->assign($supplier);
        $first->update(['status' => SupplierAudit::STATUS_CANCELLED]);
        $this->assign($supplier);
        $this->expectException(QueryException::class);
        $first->update(['status' => SupplierAudit::STATUS_DRAFT]);
    }

    public function test_seeder_creates_the_approved_checklist_and_is_idempotent(): void
    {
        $this->seed(SupplierAuditTemplateSeeder::class);

        $this->assertSame(1, SupplierAuditTemplate::count());
        $template = SupplierAuditTemplate::activeFor();
        $this->assertNotNull($template);
        $this->assertSame(SupplierAuditTemplate::CODE_ISO, $template->code);
        $this->assertSame(1, $template->version);

        $this->assertSame(16, $template->sections()->where('level', 1)->count());
        $this->assertSame(4, $template->sections()->where('level', 2)->count());
        $this->assertSame(121, $template->criteria()->count());
        $this->assertSame(17, SupplierAuditSection::has('criteria')->count());
        $this->assertSame(
            ['2.1' => '2', '6.1' => '6', '6.2' => '6', '12.1' => '12'],
            SupplierAuditSection::where('level', 2)->with('parent')->orderBy('sort_order')->get()->mapWithKeys(fn ($s) => [$s->code => $s->parent->code])->all(),
        );
        $this->assertSame([1, 2, 3, 4], SupplierAuditSection::where('level', 1)->distinct()->orderBy('sheet_number')->pluck('sheet_number')->all());
        $this->assertSame(1, SupplierAuditSection::where('code', '4')->value('sheet_number'));
        $this->assertSame(4, SupplierAuditSection::where('code', '13')->value('sheet_number'));
    }

    public function test_seeded_text_uses_the_approved_corrections(): void
    {
        $this->assertSame('Peraturan Perundangan dan Persyaratan LK3SR Lainnya', SupplierAuditSection::where('code', '3')->value('title'));
        $this->assertSame('Kebijakan LK3', SupplierAuditSection::where('code', '1')->value('title'));
        $this->assertSame(2, SupplierAuditSection::where('title', 'Penerapan dan Operasi')->count());

        $texts = SupplierAuditCriterion::pluck('text')->implode("\n");
        $this->assertStringContainsString('akan diperbaiki dan disesuaikan', $texts);
        $this->assertStringContainsString('secara kontinu', $texts);
        $this->assertStringContainsString('tidak mempekerjakan karyawan di bawah umur', $texts);
        foreach (['dieprbaiki', 'dokomunikasikan', 'penympanan', 'metholologi', 'QLKSR ', 'upd ating', 'costumer'] as $typo) {
            $this->assertStringNotContainsString($typo, $texts);
        }
    }

    public function test_status_helper_maps_supplier_audit_statuses(): void
    {
        $this->assertSame('info', StatusHelper::supplierAuditTone('ASSIGNED'));
        $this->assertSame('warning', StatusHelper::supplierAuditTone('REVISION_REQUESTED'));
        $this->assertSame('success', StatusHelper::supplierAuditTone('RESULT_PUBLISHED'));
        $this->assertSame('neutral', StatusHelper::supplierAuditTone('CANCELLED'));
        app()->setLocale('id');
        $this->assertSame('Hasil Terbit', StatusHelper::supplierAuditLabel('RESULT_PUBLISHED'));
    }

    public function test_late_label_follows_business_calendar_boundary(): void
    {
        $supplier = $this->supplier();
        Carbon::setTestNow(Carbon::parse('2026-10-10 03:00:00', 'UTC'));
        $audit = $this->assign($supplier, '2026-10-20');

        // 2026-10-20 23:59:59 WIB = 16:59:59 UTC: masih hari deadline.
        Carbon::setTestNow(Carbon::parse('2026-10-20 16:59:59', 'UTC'));
        $this->assertFalse($audit->fresh()->isLate());
        $this->assertSame(0, SupplierAudit::late()->count());

        // 2026-10-21 00:00 WIB = 17:00 UTC: sudah lewat.
        Carbon::setTestNow(Carbon::parse('2026-10-20 17:00:00', 'UTC'));
        $this->assertTrue($audit->fresh()->isLate());
        $this->assertSame(1, SupplierAudit::late()->count());

        $this->submitComplete($audit, $supplier);
        $this->assertFalse($audit->fresh()->isLate());
        $this->assertSame(0, SupplierAudit::late()->count());

        Carbon::setTestNow();
    }

    public function test_audit_without_deadline_is_never_late(): void
    {
        $audit = $this->assign($this->supplier());
        Carbon::setTestNow(Carbon::parse('2030-01-01 00:00:00', 'UTC'));
        $this->assertFalse($audit->fresh()->isLate());
        Carbon::setTestNow();
    }

    public function test_database_check_constraint_rejects_score_on_no_answer(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('CHECK constraint only applies to MySQL/MariaDB.');
        }

        $audit = $this->assign($this->supplier());
        $answer = $this->answerFor($audit, $this->firstCriterionId($audit));

        $this->expectException(QueryException::class);
        $answer->forceFill(['answer' => 'NO', 'score' => 3])->save();
    }
}
