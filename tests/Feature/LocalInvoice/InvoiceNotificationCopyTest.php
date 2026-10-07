<?php

namespace Tests\Feature\LocalInvoice;

use App\Models\LocalInvoice;
use App\Models\LocalInvoiceStatusHistory;
use App\Models\Supplier;
use App\Models\User;
use App\Services\LocalInvoice\InvoiceNotificationService;
use App\Support\NotificationCategory;
use App\Support\NotificationDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InvoiceNotificationCopyTest extends TestCase
{
    use RefreshDatabase;

    private User $supplierUser;

    private User $financeUser;

    private LocalInvoice $invoice;

    private InvoiceNotificationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supplierUser = User::factory()->create([
            'role' => 'supplier',
            'is_active' => true,
            'account_status' => 'ACTIVE',
        ]);
        $this->supplierUser->supplierScopes()->create(['scope' => 'local']);

        Supplier::create([
            'user_id' => $this->supplierUser->id,
            'company_name' => 'PT Local Vendor',
        ]);

        $this->financeUser = User::factory()->create([
            'role' => 'finance',
            'is_active' => true,
            'account_status' => 'ACTIVE',
        ]);

        $this->invoice = LocalInvoice::create([
            'supplier_id' => $this->supplierUser->id,
            'submission_number' => 'SUB-2026-0001',
            'submitted_at' => now(),
            'invoice_number' => 'INV-2026-0001',
            'invoice_date' => '2026-10-01',
            'po_number' => 'PO-LOCAL-001',
            'invoice_amount' => '5000000.00',
            'tax_amount' => '550000.00',
            'status' => LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT,
            'scheduled_physical_delivery_date' => '2026-10-07',
            'revision_number' => 1,
            'payment_term_days_snapshot' => 30,
        ]);

        $this->service = app(InvoiceNotificationService::class);
    }

    public function test_registered_local_invoice_events_use_recipient_language_title(): void
    {
        $events = [
            'submitted',
            'resubmitted',
            'cancelled',
            'physical_received',
            'approved',
            'revision_requested',
            'rejected',
            'partial_payment',
            'paid',
            'overpaid',
            'refund_settled',
        ];

        foreach ($events as $index => $event) {
            $history = LocalInvoiceStatusHistory::create([
                'local_invoice_id' => $this->invoice->id,
                'from_status' => LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT,
                'to_status' => LocalInvoice::STATUS_UNDER_VERIFICATION,
                'actor_id' => $this->financeUser->id,
                'event' => $event,
                'notes' => 'Test event '.$event,
            ]);

            $this->service->send($this->invoice, $history);

            $expectedTitle = trans("notifications.invoice.events.{$event}.title", [], 'en');
            $this->assertNotEmpty($expectedTitle, "Registry label for local_invoice_{$event} must exist.");

            // Supplier or Finance should have received notification with expected title
            $recipient = in_array($event, ['submitted', 'resubmitted'], true)
                ? $this->financeUser
                : $this->supplierUser;

            $notification = $recipient->notifications()
                ->where('data->event', 'local_invoice.'.$event)
                ->where('data->event_key', 'local-invoice:'.$history->id)
                ->first();

            $this->assertNotNull($notification, "Notification for event {$event} was not found.");
            $this->assertSame($expectedTitle, $notification->data['title'], "Title mismatch for event {$event}");
        }
    }

    public function test_unregistered_events_use_localized_event_dictionary_title(): void
    {
        $unregisteredMap = [
            'delivery_missed' => 'Batas pengiriman berkas terlewat',
            'expired' => 'Invoice kedaluwarsa',
            'rescheduled' => 'Jadwal pengiriman berkas diubah',
            'physical_verified' => 'Dokumen fisik terverifikasi',
            'payment_scheduled' => 'Jadwal bayar ditentukan',
            'completed' => 'Pembayaran selesai',
        ];

        foreach (array_keys($unregisteredMap) as $event) {
            $expectedTitle = trans("notifications.invoice.events.{$event}.title", [], 'en');
            $history = LocalInvoiceStatusHistory::create([
                'local_invoice_id' => $this->invoice->id,
                'from_status' => LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT,
                'to_status' => LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT,
                'actor_id' => $this->financeUser->id,
                'event' => $event,
                'notes' => 'Test event '.$event,
            ]);

            $this->service->send($this->invoice, $history);

            $notification = $this->supplierUser->notifications()
                ->where('data->event', 'local_invoice.'.$event)
                ->where('data->event_key', 'local-invoice:'.$history->id)
                ->first();

            $this->assertNotNull($notification, "Notification for unregistered event {$event} was not found.");
            $this->assertSame($expectedTitle, $notification->data['title'], "Title mismatch for unregistered event {$event}");
        }
    }

    public function test_unknown_event_falls_back_to_generic_english_title(): void
    {
        $history = LocalInvoiceStatusHistory::create([
            'local_invoice_id' => $this->invoice->id,
            'from_status' => LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT,
            'to_status' => LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT,
            'actor_id' => $this->financeUser->id,
            'event' => 'custom_unknown_action',
            'notes' => 'Testing fallback',
        ]);

        $this->service->send($this->invoice, $history);

        $notification = $this->supplierUser->notifications()
            ->where('data->event', 'local_invoice.custom_unknown_action')
            ->where('data->event_key', 'local-invoice:'.$history->id)
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame(trans('notifications.invoice.updated.title', [], 'en'), $notification->data['title']);
    }

    public function test_internal_events_render_event_copy_without_parsing_persisted_audit_notes(): void
    {
        // 1. Without notes: fallback to 'Menunggu berkas fisik.'
        $historyWithoutNotes = LocalInvoiceStatusHistory::create([
            'local_invoice_id' => $this->invoice->id,
            'from_status' => null,
            'to_status' => LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT,
            'actor_id' => $this->supplierUser->id,
            'event' => 'submitted',
            'notes' => null,
        ]);

        $this->service->send($this->invoice, $historyWithoutNotes);

        $financeNotification = $this->financeUser->notifications()
            ->where('data->event_key', 'local-invoice:'.$historyWithoutNotes->id)
            ->first();

        $this->assertNotNull($financeNotification);
        $expected = trans('notifications.invoice.events.submitted.message', ['submission' => 'SUB-2026-0001', 'invoice' => 'INV-2026-0001'], 'en');
        $this->assertSame($expected, $financeNotification->data['message']);

        // 2. With notes: use notes
        $historyWithNotes = LocalInvoiceStatusHistory::create([
            'local_invoice_id' => $this->invoice->id,
            'from_status' => null,
            'to_status' => LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT,
            'actor_id' => $this->supplierUser->id,
            'event' => 'submitted',
            'notes' => 'Dokumen lengkap dikirim via kurir.',
        ]);

        $this->service->send($this->invoice, $historyWithNotes, ['raw_notes' => $historyWithNotes->notes]);

        $financeNotification2 = $this->financeUser->notifications()
            ->where('data->event_key', 'local-invoice:'.$historyWithNotes->id)
            ->first();

        $this->assertNotNull($financeNotification2);
        $this->assertSame($expected, $financeNotification2->data['message']);
        $this->assertSame('Dokumen lengkap dikirim via kurir.', $historyWithNotes->fresh()->notes);
    }

    public function test_supplier_copy_for_submitted_resubmitted_shares_resolved_title(): void
    {
        foreach (['submitted', 'resubmitted'] as $event) {
            $history = LocalInvoiceStatusHistory::create([
                'local_invoice_id' => $this->invoice->id,
                'from_status' => null,
                'to_status' => LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT,
                'actor_id' => $this->supplierUser->id,
                'event' => $event,
                'notes' => null,
            ]);

            $this->service->send($this->invoice, $history);

            $expectedTitle = trans("notifications.invoice.events.{$event}.title", [], 'en');

            $financeNotification = $this->financeUser->notifications()
                ->where('data->event_key', 'local-invoice:'.$history->id)
                ->first();
            $supplierNotification = $this->supplierUser->notifications()
                ->where('data->event_key', 'local-invoice:'.$history->id)
                ->first();

            $this->assertNotNull($financeNotification);
            $this->assertNotNull($supplierNotification);

            $this->assertSame($expectedTitle, $financeNotification->data['title']);
            $this->assertSame($expectedTitle, $supplierNotification->data['title']);
            $this->assertSame($financeNotification->data['title'], $supplierNotification->data['title']);

            // Supplier message uses invoice number
            $this->assertSame(trans('notifications.invoice.confirmation.message', ['submission' => 'SUB-2026-0001', 'invoice' => 'INV-2026-0001'], 'en'), $supplierNotification->data['message']);
        }
    }

    public function test_notification_identity_metadata_and_deduplication_are_preserved(): void
    {
        $history = LocalInvoiceStatusHistory::create([
            'local_invoice_id' => $this->invoice->id,
            'from_status' => LocalInvoice::STATUS_UNDER_VERIFICATION,
            'to_status' => LocalInvoice::STATUS_READY_TO_PAY,
            'actor_id' => $this->financeUser->id,
            'event' => 'approved',
            'notes' => 'Approved to pay',
        ]);

        // First call
        $this->service->send($this->invoice, $history);

        $notification = $this->supplierUser->notifications()
            ->where('data->event_key', 'local-invoice:'.$history->id)
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame('local_invoice.approved', $notification->data['event']);
        $this->assertSame('local-invoice:'.$history->id, $notification->data['event_key']);
        $this->assertSame('receipt', $notification->data['icon']);
        $this->assertSame(NotificationCategory::INVOICE, $notification->data['category']);
        $this->assertSame(NotificationDomain::LOCAL, $notification->data['domain']);
        $this->assertSame($this->invoice->id, $notification->data['local_invoice_id']);
        $this->assertSame(route('local-supplier.invoices.show', $this->invoice, absolute: false), $notification->data['url']);

        $countBefore = $this->supplierUser->notifications()->count();

        // Second call (idempotent / deduplicated)
        $this->service->send($this->invoice, $history);

        $countAfter = $this->supplierUser->notifications()->count();
        $this->assertSame($countBefore, $countAfter, 'Duplicate send must not create duplicate notification rows.');
    }

    public function test_send_physical_delivery_reminder_is_localized_without_changing_identity(): void
    {
        $this->service->sendPhysicalDeliveryReminder($this->invoice);

        $notification = $this->supplierUser->notifications()
            ->where('data->event', 'local_invoice.physical_delivery_reminder')
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame(trans('notifications.invoice.events.physical_delivery_reminder.title', [], 'en'), $notification->data['title']);
        $this->assertSame(trans('notifications.invoice.events.physical_delivery_reminder.message', ['submission' => 'SUB-2026-0001', 'invoice' => 'INV-2026-0001'], 'en'), $notification->data['message']);
        $this->assertSame('receipt', $notification->data['icon']);
        $this->assertSame(NotificationCategory::INVOICE, $notification->data['category']);
        $this->assertSame(NotificationDomain::LOCAL, $notification->data['domain']);
    }

    public function test_off_preference_for_local_invoice_submitted_blocks_delivery(): void
    {
        $preference = $this->financeUser->preference()->create(config('user_preferences.defaults', []));
        DB::table('user_preferences')->where('id', $preference->id)->update([
            'notification_preferences' => json_encode(['local_invoice_submitted' => false], JSON_THROW_ON_ERROR),
        ]);

        $history = LocalInvoiceStatusHistory::create([
            'local_invoice_id' => $this->invoice->id,
            'from_status' => null,
            'to_status' => LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT,
            'actor_id' => $this->supplierUser->id,
            'event' => 'submitted',
            'notes' => null,
        ]);

        $this->service->send($this->invoice, $history);

        // Finance user should NOT have received notification
        $financeNotification = $this->financeUser->notifications()
            ->where('data->event_key', 'local-invoice:'.$history->id)
            ->first();

        $this->assertNull($financeNotification, 'Off preference must block notification delivery.');

        // Supplier user has default ON, so supplier should still receive supplier copy
        $supplierNotification = $this->supplierUser->notifications()
            ->where('data->event_key', 'local-invoice:'.$history->id)
            ->first();

        $this->assertNotNull($supplierNotification, 'Supplier with default ON should still receive notification.');
    }

    public function test_structured_recipient_copy_keeps_user_notes_literal_and_audit_history_unchanged(): void
    {
        $this->supplierUser->preference()->create([
            ...config('user_preferences.defaults'), 'locale' => 'id',
        ]);
        $raw = 'notifications.invoice.updated.title <b>Supplier remark</b>';
        $history = LocalInvoiceStatusHistory::create([
            'local_invoice_id' => $this->invoice->id,
            'from_status' => LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT,
            'to_status' => LocalInvoice::STATUS_NEED_REVISION,
            'actor_id' => $this->financeUser->id,
            'event' => 'revision_requested',
            'notes' => $raw,
        ]);

        app()->setLocale('en');
        $this->service->send($this->invoice, $history, ['reason' => $raw]);
        $notification = $this->supplierUser->notifications()
            ->where('data->event_key', 'local-invoice:'.$history->id)->firstOrFail();

        $this->assertSame(trans('notifications.invoice.events.revision_requested.title', [], 'id'), $notification->data['title']);
        $this->assertSame(trans('notifications.invoice.events.revision_requested.message', [
            'submission' => $this->invoice->submission_number,
            'invoice' => $this->invoice->invoice_number,
            'reason' => $raw,
        ], 'id'), $notification->data['message']);
        $this->assertSame($raw, $history->fresh()->notes);
        $this->assertSame('en', app()->getLocale());
    }
}
