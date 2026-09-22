<?php

namespace Tests\Feature;

use App\Models\LocalInvoice;
use App\Models\Supplier;
use App\Models\User;
use App\Notifications\LocalInvoice\PhysicalDeliveryReminderNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SendLocalInvoiceDeliveryRemindersTest extends TestCase
{
    use RefreshDatabase;

    private User $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->supplier = $this->createLocalSupplier();
    }

    private function createLocalSupplier(): User
    {
        $user = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $user->supplierScopes()->delete();
        $user->supplierScopes()->create(['scope' => 'local']);

        Supplier::create([
            'user_id' => $user->id,
            'company_name' => 'PT Vendor Lokal '.$user->id,
            'address' => 'Jakarta',
            'phone' => '021-123456',
            'npwp' => '12.345.678.9-012.000',
            'category' => 'Parts',
            'payment_term_days' => 30,
            'is_pkp' => true,
        ]);

        return $user;
    }

    private function createInvoice(User $supplier, array $overrides = []): LocalInvoice
    {
        return LocalInvoice::create(array_merge([
            'submission_number' => 'SUB-'.uniqid(),
            'supplier_id' => $supplier->id,
            'invoice_number' => 'INV-'.uniqid(),
            'invoice_date' => now()->toDateString(),
            'po_number' => 'PO-001',
            'currency' => 'IDR',
            'invoice_amount' => 5000000.00,
            'tax_amount' => 550000.00,
            'payment_term_days_snapshot' => 30,
            'status' => LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT,
            'submitted_at' => now(),
            'scheduled_physical_delivery_date' => today()->addDays(2),
        ], $overrides));
    }

    public function test_intended_reminder_timing_sends_notification_for_invoices_within_three_days(): void
    {
        $invoice = $this->createInvoice($this->supplier, [
            'scheduled_physical_delivery_date' => today()->addDays(2),
        ]);

        $this->artisan('local-invoices:send-delivery-reminders')
            ->expectsOutputToContain('Found 1 invoices with upcoming physical delivery schedules.')
            ->assertSuccessful();

        Notification::assertSentTo($this->supplier, PhysicalDeliveryReminderNotification::class, 1);

        $invoice->refresh();
        $this->assertNotNull($invoice->delivery_reminder_sent_at);
    }

    public function test_repeated_command_execution_does_not_send_duplicate_notification(): void
    {
        $invoice = $this->createInvoice($this->supplier, [
            'scheduled_physical_delivery_date' => today()->addDays(2),
        ]);

        // First execution
        $this->artisan('local-invoices:send-delivery-reminders')->assertSuccessful();
        Notification::assertSentTo($this->supplier, PhysicalDeliveryReminderNotification::class, 1);

        // Immediate repeated execution
        $this->artisan('local-invoices:send-delivery-reminders')
            ->expectsOutputToContain('Found 0 invoices with upcoming physical delivery schedules.')
            ->assertSuccessful();

        // Count must remain exactly 1
        Notification::assertSentTo($this->supplier, PhysicalDeliveryReminderNotification::class, 1);
    }

    public function test_repeated_scheduler_execution_across_days_within_window_does_not_duplicate(): void
    {
        $invoice = $this->createInvoice($this->supplier, [
            'scheduled_physical_delivery_date' => today()->addDays(2),
        ]);

        // Day 1
        $this->artisan('local-invoices:send-delivery-reminders')->assertSuccessful();
        Notification::assertSentTo($this->supplier, PhysicalDeliveryReminderNotification::class, 1);

        // Day 2 (delivery is now 1 day away, but already reminded)
        $this->travel(1)->days();
        $this->artisan('local-invoices:send-delivery-reminders')
            ->expectsOutputToContain('Found 0 invoices with upcoming physical delivery schedules.')
            ->assertSuccessful();
        Notification::assertSentTo($this->supplier, PhysicalDeliveryReminderNotification::class, 1);

        // Day 3 (delivery is today, but already reminded)
        $this->travel(1)->days();
        $this->artisan('local-invoices:send-delivery-reminders')
            ->expectsOutputToContain('Found 0 invoices with upcoming physical delivery schedules.')
            ->assertSuccessful();
        Notification::assertSentTo($this->supplier, PhysicalDeliveryReminderNotification::class, 1);
    }

    public function test_invoices_outside_the_reminder_window_are_not_reminded(): void
    {
        // Too far in future (> 3 days)
        $this->createInvoice($this->supplier, [
            'scheduled_physical_delivery_date' => today()->addDays(5),
        ]);

        // In the past
        $this->createInvoice($this->supplier, [
            'scheduled_physical_delivery_date' => today()->subDays(1),
        ]);

        // No scheduled date
        $this->createInvoice($this->supplier, [
            'scheduled_physical_delivery_date' => null,
        ]);

        // Not in WAITING_PHYSICAL_DOCUMENT status
        $this->createInvoice($this->supplier, [
            'status' => LocalInvoice::STATUS_UNDER_VERIFICATION,
            'scheduled_physical_delivery_date' => today()->addDays(1),
        ]);

        $this->artisan('local-invoices:send-delivery-reminders')
            ->expectsOutputToContain('Found 0 invoices with upcoming physical delivery schedules.')
            ->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_multiple_invoices_in_window_are_all_reminded_once(): void
    {
        $supplierB = $this->createLocalSupplier();

        $inv1 = $this->createInvoice($this->supplier, ['scheduled_physical_delivery_date' => today()->addDays(1)]);
        $inv2 = $this->createInvoice($this->supplier, ['scheduled_physical_delivery_date' => today()->addDays(2)]);
        $inv3 = $this->createInvoice($supplierB, ['scheduled_physical_delivery_date' => today()->addDays(3)]);

        $this->artisan('local-invoices:send-delivery-reminders')
            ->expectsOutputToContain('Found 3 invoices with upcoming physical delivery schedules.')
            ->assertSuccessful();

        Notification::assertSentTo($this->supplier, PhysicalDeliveryReminderNotification::class, 2);
        Notification::assertSentTo($supplierB, PhysicalDeliveryReminderNotification::class, 1);

        // Repeat execution
        $this->artisan('local-invoices:send-delivery-reminders')
            ->expectsOutputToContain('Found 0 invoices with upcoming physical delivery schedules.')
            ->assertSuccessful();

        Notification::assertSentTo($this->supplier, PhysicalDeliveryReminderNotification::class, 2);
        Notification::assertSentTo($supplierB, PhysicalDeliveryReminderNotification::class, 1);
    }

    public function test_rescheduled_invoice_receives_new_reminder_for_rescheduled_date(): void
    {
        $invoice = $this->createInvoice($this->supplier, [
            'scheduled_physical_delivery_date' => today()->addDays(1),
        ]);

        // First schedule reminded
        $this->artisan('local-invoices:send-delivery-reminders')->assertSuccessful();
        Notification::assertSentTo($this->supplier, PhysicalDeliveryReminderNotification::class, 1);

        // Invoice was missed and rescheduled to next week
        $invoice->update([
            'delivery_reminder_sent_at' => now(),
            'rescheduled_at' => now()->addMinutes(10),
            'scheduled_physical_delivery_date' => today()->addDays(7),
            'missed_delivery_count' => 1,
        ]);

        // Advance 5 days: rescheduled date is now 2 days away
        $this->travel(5)->days();

        $this->artisan('local-invoices:send-delivery-reminders')
            ->expectsOutputToContain('Found 1 invoices with upcoming physical delivery schedules.')
            ->assertSuccessful();

        // Receives exactly 1 additional reminder for the new schedule
        Notification::assertSentTo($this->supplier, PhysicalDeliveryReminderNotification::class, 2);
    }
}
