<?php

namespace Tests\Feature\SupplierAudit;

use App\Models\SupplierAudit;
use App\Models\SupplierAuditStatusHistory;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Services\SupplierAudit\SupplierAuditReviewService;
use App\Support\BusinessTime;
use Illuminate\Support\Facades\Notification;

class SupplierAuditAccessAndAssignmentTest extends SupplierAuditTestCase
{
    public function test_local_supplier_without_assignment_sees_empty_state_and_menu(): void
    {
        $supplier = $this->supplier();

        $this->actingAs($supplier)
            ->get(route('local-supplier.supplier-audits.index'))
            ->assertOk()
            ->assertSee(__('supplier_audit.empty.no_access'))
            ->assertSee(route('local-supplier.supplier-audits.index'), false);
    }

    public function test_history_remains_visible_next_to_empty_state(): void
    {
        $supplier = $this->supplier();
        $audit = $this->assign($supplier);
        app(SupplierAuditReviewService::class)->cancel($this->purchasing, $audit, 'Periode diganti');

        $this->actingAs($supplier)->get(route('local-supplier.supplier-audits.index'))
            ->assertOk()
            ->assertSee(__('supplier_audit.empty.no_access'))
            ->assertSee($audit->period_label)
            ->assertSee(route('local-supplier.supplier-audits.show', $audit), false);
    }

    public function test_import_only_supplier_is_forbidden(): void
    {
        $importOnly = $this->supplier(['import']);
        $audit = $this->assign($this->supplier());

        $this->actingAs($importOnly)->get(route('local-supplier.supplier-audits.index'))->assertForbidden();
        $this->actingAs($importOnly)->get(route('local-supplier.supplier-audits.show', $audit))->assertForbidden();
    }

    public function test_supplier_cannot_view_or_update_another_suppliers_audit(): void
    {
        $owner = $this->supplier();
        $other = $this->supplier();
        $audit = $this->assign($owner);

        $this->actingAs($other)->get(route('local-supplier.supplier-audits.show', $audit))->assertForbidden();
        $this->actingAs($other)->get(route('local-supplier.supplier-audits.edit', $audit))->assertForbidden();
        $this->actingAs($other)->putJson(route('local-supplier.supplier-audits.update', $audit), [
            'action' => 'draft',
            'answers' => [$this->firstCriterionId($audit) => ['answer' => 'NO']],
        ])->assertForbidden();
        $this->assertNull($this->answerFor($audit, $this->firstCriterionId($audit))->answer);
    }

    public function test_raw_integer_and_invalid_hash_return_not_found(): void
    {
        $owner = $this->supplier();
        $audit = $this->assign($owner);

        $this->actingAs($owner)->get('/local-supplier/supplier-audits/'.$audit->id)->assertNotFound();
        $this->actingAs($owner)->get('/local-supplier/supplier-audits/invalid-hash')->assertNotFound();
        $this->actingAs($this->purchasing)->get('/purchasing/supplier-audits/'.$audit->id)->assertNotFound();
        $this->actingAs($this->purchasing)->get(route('purchasing.supplier-audits.show', $audit))->assertOk();
    }

    public function test_purchasing_pages_reject_other_roles(): void
    {
        $finance = User::factory()->create(['role' => 'finance']);
        $supplier = $this->supplier();

        $this->actingAs($finance)->get(route('purchasing.supplier-audits.index'))->assertForbidden();
        $this->actingAs($supplier)->get(route('purchasing.supplier-audits.create'))->assertForbidden();
    }

    public function test_purchasing_assigns_multiple_suppliers_with_snapshots_and_reports_rejections(): void
    {
        $a = $this->supplier();
        $b = $this->supplier();
        $busy = $this->supplier();
        $this->assign($busy);
        $pending = $this->supplier(['local'], ['account_status' => User::ACCOUNT_STATUS_PENDING]);
        $importOnly = $this->supplier(['import']);

        $this->actingAs($this->purchasing)->get(route('purchasing.supplier-audits.create'))
            ->assertOk()
            ->assertSee('Vendor '.$a->id)
            ->assertDontSee('Vendor '.$pending->id)
            ->assertDontSee('Vendor '.$importOnly->id);

        $response = $this->actingAs($this->purchasing)->postJson(route('purchasing.supplier-audits.store'), [
            'supplier_ids' => [$a->hash, $b->hash, $busy->hash, $pending->hash, $importOnly->hash],
            'period_label' => '2027 Semester I',
            'due_date' => BusinessTime::today()->addDays(10)->toDateString(),
        ]);

        $response->assertOk()->assertJson(['redirect' => route('purchasing.supplier-audits.index')]);
        $this->assertSame(1, SupplierAudit::where('supplier_id', $a->id)->count());
        $this->assertSame(1, SupplierAudit::where('supplier_id', $b->id)->count());
        $this->assertSame(1, SupplierAudit::where('supplier_id', $busy->id)->count(), 'D7: tidak ada audit kedua');
        $this->assertSame(0, SupplierAudit::where('supplier_id', $pending->id)->count());

        $audit = SupplierAudit::where('supplier_id', $a->id)->first();
        $this->assertSame(121, $audit->answers()->count());
        $this->assertSame(SupplierAudit::STATUS_ASSIGNED, $audit->status);
        $this->assertSame('2.1', $audit->answers()->where('parent_section_code_snapshot', '2')->value('section_code_snapshot'));
        $this->assertSame(SupplierAuditStatusHistory::EVENT_ASSIGNED, $audit->statusHistories()->value('event'));

        $warning = session('warning');
        $this->assertStringContainsString('Vendor '.$busy->id, $warning);
        $this->assertStringContainsString('Vendor '.$pending->id, $warning);

        Notification::assertSentTo($a, SystemNotification::class, fn ($n) => $n->event() === 'supplier_audit.assigned');
    }

    public function test_assignment_fails_when_every_supplier_is_rejected(): void
    {
        $busy = $this->supplier();
        $this->assign($busy);

        $this->actingAs($this->purchasing)->postJson(route('purchasing.supplier-audits.store'), [
            'supplier_ids' => [$busy->hash],
            'period_label' => '2027 Semester I',
        ])->assertUnprocessable()->assertJsonValidationErrors('supplier_ids');
    }

    public function test_new_audit_allowed_after_previous_is_final(): void
    {
        $supplier = $this->supplier();
        $audit = $this->assign($supplier);
        app(SupplierAuditReviewService::class)->cancel($this->purchasing, $audit, 'Diganti');

        $this->assign($supplier, null, '2027 Semester I');
        $this->assertSame(2, SupplierAudit::where('supplier_id', $supplier->id)->count());
        $this->assertSame(1, SupplierAudit::where('supplier_id', $supplier->id)->active()->count());
    }

    public function test_assignment_validation_rejects_raw_ids_and_past_deadline(): void
    {
        $supplier = $this->supplier();

        $this->actingAs($this->purchasing)->postJson(route('purchasing.supplier-audits.store'), [
            'supplier_ids' => [(string) $supplier->id],
            'period_label' => '2027 Semester I',
        ])->assertUnprocessable()->assertJsonValidationErrors('supplier_ids');

        $this->actingAs($this->purchasing)->postJson(route('purchasing.supplier-audits.store'), [
            'supplier_ids' => [$supplier->hash],
            'period_label' => '2027 Semester I',
            'due_date' => BusinessTime::today()->subDay()->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors('due_date');

        $this->assertSame(0, SupplierAudit::count());
    }

    public function test_purchasing_index_filters_and_shows_late_badge(): void
    {
        $late = $this->supplier();
        $lateAudit = $this->assign($late, BusinessTime::today()->subDays(2)->toDateString());
        $onTime = $this->supplier();
        $onTimeAudit = $this->assign($onTime, BusinessTime::today()->addDays(5)->toDateString());

        $this->actingAs($this->purchasing)->get(route('purchasing.supplier-audits.index', ['queue' => 'late']))
            ->assertOk()
            ->assertSee(route('purchasing.supplier-audits.show', $lateAudit), false)
            ->assertDontSee(route('purchasing.supplier-audits.show', $onTimeAudit), false)
            ->assertSee(__('supplier_audit.labels.late'));

        $this->actingAs($this->purchasing)->get(route('purchasing.supplier-audits.index', ['queue' => 'all', 'supplier' => $onTime->hash]))
            ->assertOk()
            ->assertSee(route('purchasing.supplier-audits.show', $onTimeAudit), false)
            ->assertDontSee(route('purchasing.supplier-audits.show', $lateAudit), false);
    }
}
