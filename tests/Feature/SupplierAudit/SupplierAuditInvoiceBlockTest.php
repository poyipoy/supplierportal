<?php

namespace Tests\Feature\SupplierAudit;

use App\Models\LocalInvoice;
use App\Models\SupplierAudit;
use App\Models\SupplierAuditStatusHistory;
use App\Notifications\SystemNotification;
use App\Services\LocalInvoice\InvoiceSubmissionService;
use App\Services\SupplierAudit\SupplierAuditInvoiceGate;
use App\Services\SupplierAudit\SupplierAuditReviewService;
use App\Support\BusinessTime;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class SupplierAuditInvoiceBlockTest extends SupplierAuditTestCase
{
    private function overdueAudit(): array
    {
        $supplier = $this->supplier();
        $audit = $this->assign($supplier, BusinessTime::today()->subDay()->toDateString());

        return [$supplier, $audit];
    }

    public function test_supplier_without_overdue_audit_can_open_invoice_form(): void
    {
        $supplier = $this->supplier();
        $this->assign($supplier, BusinessTime::today()->toDateString());

        $this->actingAs($supplier)->get(route('local-supplier.invoices.create'))->assertOk();
        $this->actingAs($supplier)->get(route('local-supplier.dashboard'))
            ->assertOk()
            ->assertDontSee('data-sidebar-disabled', false)
            ->assertDontSee('data-supplier-audit-invoice-block', false);
    }

    public function test_overdue_audit_blocks_new_invoice_entry_points(): void
    {
        [$supplier, $audit] = $this->overdueAudit();

        $this->actingAs($supplier)->get(route('local-supplier.invoices.create'))
            ->assertRedirect(route('local-supplier.supplier-audits.edit', $audit))
            ->assertSessionHas('warning');
        $this->actingAs($supplier)->getJson(route('local-supplier.purchase-orders.search', ['q' => 'PO']))->assertForbidden();

        $dashboard = $this->actingAs($supplier)->get(route('local-supplier.dashboard'))->assertOk();
        $dashboard->assertSee('data-sidebar-disabled', false)
            ->assertSee('data-supplier-audit-invoice-block', false)
            ->assertSee(route('local-supplier.supplier-audits.edit', $audit), false);
        $this->assertStringNotContainsString('href="'.route('local-supplier.invoices.create').'"', $dashboard->getContent());
    }

    public function test_submission_service_rejects_new_invoice_while_blocked(): void
    {
        [$supplier] = $this->overdueAudit();

        try {
            app(InvoiceSubmissionService::class)->submit($supplier, [], []);
            $this->fail('Blocked supplier must not submit a new invoice.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('supplier_audit', $exception->errors());
        }

        $this->assertSame(0, LocalInvoice::count());
    }

    public function test_resubmit_and_cancel_policies_are_not_affected(): void
    {
        [$supplier] = $this->overdueAudit();

        $this->assertNotNull(app(SupplierAuditInvoiceGate::class)->blockingAudit($supplier));
        $this->assertTrue(Gate::forUser($supplier)->allows('create', LocalInvoice::class), 'create() is shared by resubmit/cancel and stays open');
    }

    public function test_block_starts_after_the_deadline_day_in_business_time(): void
    {
        $supplier = $this->supplier();
        Carbon::setTestNow(Carbon::parse('2026-10-10 03:00:00', 'UTC'));
        $this->assign($supplier, '2026-10-20');

        Carbon::setTestNow(Carbon::parse('2026-10-20 16:59:59', 'UTC'));
        $this->assertNull((new SupplierAuditInvoiceGate)->blockingAudit($supplier));

        Carbon::setTestNow(Carbon::parse('2026-10-20 17:00:00', 'UTC'));
        $this->assertNotNull((new SupplierAuditInvoiceGate)->blockingAudit($supplier));

        Carbon::setTestNow();
    }

    public function test_block_lifts_after_submit_cancel_or_deadline_change(): void
    {
        [$supplier, $audit] = $this->overdueAudit();
        $this->submitComplete($audit, $supplier);
        $this->assertNull((new SupplierAuditInvoiceGate)->blockingAudit($supplier));

        [$second, $secondAudit] = $this->overdueAudit();
        app(SupplierAuditReviewService::class)->cancel($this->purchasing, $secondAudit, 'Batal');
        $this->assertNull((new SupplierAuditInvoiceGate)->blockingAudit($second));

        [$third, $thirdAudit] = $this->overdueAudit();
        $this->actingAs($this->purchasing)->postJson(route('purchasing.supplier-audits.deadline', $thirdAudit), [
            'due_date' => BusinessTime::today()->addDays(3)->toDateString(),
            'reason' => 'Perpanjangan',
        ])->assertOk();
        $this->assertNull((new SupplierAuditInvoiceGate)->blockingAudit($third));
        $this->assertSame(1, $thirdAudit->statusHistories()->where('event', SupplierAuditStatusHistory::EVENT_DEADLINE_CHANGED)->count());
        Notification::assertSentTo($third, SystemNotification::class, fn ($n) => $n->event() === 'supplier_audit.deadline_changed');

        [$fourth, $fourthAudit] = $this->overdueAudit();
        $this->actingAs($this->purchasing)->postJson(route('purchasing.supplier-audits.deadline', $fourthAudit), ['due_date' => ''])->assertOk();
        $this->assertNull($fourthAudit->fresh()->due_date);
        $this->assertNull((new SupplierAuditInvoiceGate)->blockingAudit($fourth));
    }

    public function test_revision_after_deadline_reblocks_unless_deadline_changes(): void
    {
        $supplier = $this->supplier();
        Carbon::setTestNow(BusinessTime::now()->subDays(5));
        $audit = $this->assign($supplier, BusinessTime::today()->addDay()->toDateString());
        $this->submitComplete($audit, $supplier);
        Carbon::setTestNow();

        app(SupplierAuditReviewService::class)->requestRevision($this->purchasing, $audit->fresh(), 'Ulang');

        $this->assertSame(SupplierAudit::STATUS_REVISION_REQUESTED, $audit->fresh()->status);
        $this->assertNotNull((new SupplierAuditInvoiceGate)->blockingAudit($supplier));
    }

    public function test_change_deadline_validation_and_authorization(): void
    {
        [$supplier, $audit] = $this->overdueAudit();

        $this->actingAs($this->purchasing)->postJson(route('purchasing.supplier-audits.deadline', $audit), [
            'due_date' => BusinessTime::today()->subDay()->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors('due_date');
        $this->actingAs($supplier)->postJson(route('purchasing.supplier-audits.deadline', $audit), [
            'due_date' => BusinessTime::today()->addDay()->toDateString(),
        ])->assertForbidden();
    }

    public function test_command_notifies_blocked_suppliers_once_per_deadline(): void
    {
        Notification::swap(new ChannelManager(app()));
        [$supplier, $audit] = $this->overdueAudit();
        $notBlocked = $this->supplier();
        $this->assign($notBlocked, BusinessTime::today()->toDateString());

        $this->artisan('supplier-audits:notify-invoice-blocked')->assertSuccessful();
        $this->artisan('supplier-audits:notify-invoice-blocked')->assertSuccessful();

        $blockedNotifications = fn (int $userId) => DatabaseNotification::where('notifiable_id', $userId)->get()
            ->filter(fn ($notification) => ($notification->data['event'] ?? null) === 'supplier_audit.invoice_blocked')
            ->count();

        $this->assertSame(1, $blockedNotifications($supplier->id));
        $this->assertSame(0, $blockedNotifications($notBlocked->id));

        app(SupplierAuditReviewService::class)->changeDeadline($this->purchasing, $audit, BusinessTime::today()->toDateString());
        Carbon::setTestNow(BusinessTime::now()->addDays(2));
        $this->artisan('supplier-audits:notify-invoice-blocked')->assertSuccessful();
        Carbon::setTestNow();

        $this->assertSame(2, $blockedNotifications($supplier->id));
    }
}
