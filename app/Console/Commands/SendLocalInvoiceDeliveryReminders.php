<?php

namespace App\Console\Commands;

use App\Models\LocalInvoice;
use App\Services\LocalInvoice\InvoiceNotificationService;
use Illuminate\Console\Command;

class SendLocalInvoiceDeliveryReminders extends Command
{
    protected $signature = 'local-invoices:send-delivery-reminders';

    protected $description = 'Send physical document delivery reminders to suppliers for upcoming Wednesday schedules';

    public function handle(InvoiceNotificationService $notificationService): int
    {
        // Find invoices waiting for physical documents with delivery date scheduled within next 3 days
        $targetDateStart = today();
        $targetDateEnd = today()->addDays(3);

        $invoices = LocalInvoice::where('status', LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT)
            ->whereNotNull('scheduled_physical_delivery_date')
            ->whereBetween('scheduled_physical_delivery_date', [$targetDateStart->toDateString(), $targetDateEnd->toDateString()])
            ->get();

        $this->info("Found {$invoices->count()} invoices with upcoming physical delivery schedules.");

        foreach ($invoices as $invoice) {
            $notificationService->sendPhysicalDeliveryReminder($invoice);
            $this->line("Sent delivery reminder for invoice [{$invoice->invoice_number}] to [{$invoice->supplier?->email}].");
        }

        return self::SUCCESS;
    }
}
