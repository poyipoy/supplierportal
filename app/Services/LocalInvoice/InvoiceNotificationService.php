<?php

namespace App\Services\LocalInvoice;

use App\Models\LocalInvoice;
use App\Models\LocalInvoiceStatusHistory;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\NotificationCategory;
use App\Support\NotificationDomain;

class InvoiceNotificationService
{
    public function send(LocalInvoice $invoice, LocalInvoiceStatusHistory $history, array $copyData = []): void
    {
        if ($history->event === 'review_started') {
            return;
        }

        $internal = in_array($history->event, ['submitted', 'resubmitted'], true);
        $recipients = $internal
            ? User::whereIn('role', ['accounting', 'finance'])->where('is_active', true)->get()
            : collect([$invoice->supplier]);

        $title = $this->resolveTitle($history->event);
        $message = 'notifications.invoice.events.'.$history->event.'.message';
        if (! app('translator')->has($message, 'en')) {
            $message = 'notifications.invoice.updated.message';
        }
        $replace = array_merge([
            'submission' => $invoice->submission_number,
            'invoice' => $invoice->invoice_number,
            'amount' => '', 'actual' => '', 'expected' => '', 'remaining' => '',
            'reference' => '', 'reason' => '', 'raw_notes' => '',
            'due_date' => $invoice->due_date?->format('Y-m-d') ?? '',
            'payment_term' => $invoice->payment_term_days_snapshot ?? '',
        ], $copyData);
        $url = route(($internal ? 'finance' : 'local-supplier').'.invoices.show', $invoice, absolute: false);

        // 1. In-app system notification
        app(NotificationService::class)->send(
            $recipients,
            'local_invoice.'.$history->event,
            'local-invoice:'.$history->id,
            $title,
            $message,
            $url,
            'receipt',
            ['local_invoice_id' => $invoice->id, 'category' => NotificationCategory::INVOICE, 'domain' => NotificationDomain::LOCAL],
            $replace,
        );

        if ($internal && $invoice->supplier) {
            app(NotificationService::class)->send(
                $invoice->supplier,
                'local_invoice.'.$history->event,
                'local-invoice:'.$history->id,
                $title,
                'notifications.invoice.confirmation.message',
                route('local-supplier.invoices.show', $invoice, absolute: false),
                'receipt',
                ['local_invoice_id' => $invoice->id, 'category' => NotificationCategory::INVOICE, 'domain' => NotificationDomain::LOCAL],
                $replace,
            );
        }
    }

    /**
     * Send physical document delivery reminder to supplier.
     */
    public function sendPhysicalDeliveryReminder(LocalInvoice $invoice): void
    {
        $recipient = $invoice->supplier;
        if (! $recipient || ! $invoice->scheduled_physical_delivery_date) {
            return;
        }

        $key = 'local-invoice:physical-delivery-reminder:'.$invoice->id
            .':revision:'.$invoice->revision_number
            .':date:'.$invoice->scheduled_physical_delivery_date->toDateString()
            .':schedule:'.($invoice->rescheduled_at?->getTimestamp() ?? 'initial');
        app(NotificationService::class)->send(
            $recipient,
            'local_invoice.physical_delivery_reminder',
            $key,
            'notifications.invoice.events.physical_delivery_reminder.title',
            'notifications.invoice.events.physical_delivery_reminder.message',
            route('local-supplier.invoices.show', $invoice, absolute: false),
            'receipt',
            ['local_invoice_id' => $invoice->id, 'category' => NotificationCategory::INVOICE, 'domain' => NotificationDomain::LOCAL],
            ['submission' => $invoice->submission_number, 'invoice' => $invoice->invoice_number],
        );
    }

    private function resolveTitle(string $event): string
    {
        $key = 'notifications.invoice.events.'.$event.'.title';
        return app('translator')->has($key, 'en') ? $key : 'notifications.invoice.updated.title';
    }
}
