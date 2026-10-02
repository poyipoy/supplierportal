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
    public function send(LocalInvoice $invoice, LocalInvoiceStatusHistory $history): void
    {
        if ($history->event === 'review_started') {
            return;
        }

        $internal = in_array($history->event, ['submitted', 'resubmitted'], true);
        $recipients = $internal
            ? User::whereIn('role', ['accounting', 'finance'])->where('is_active', true)->get()
            : collect([$invoice->supplier]);

        $title = 'Local invoice: '.ucwords(str_replace('_', ' ', $history->event));
        $message = $invoice->submission_number.' — '.($history->notes ?: ($internal ? 'Waiting for physical documents.' : $invoice->invoice_number));
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
            ['local_invoice_id' => $invoice->id, 'category' => NotificationCategory::INVOICE, 'domain' => NotificationDomain::LOCAL]
        );

        if ($internal && $invoice->supplier) {
            app(NotificationService::class)->send(
                $invoice->supplier,
                'local_invoice.'.$history->event,
                'local-invoice:'.$history->id,
                $title,
                $invoice->submission_number.' — '.$invoice->invoice_number,
                route('local-supplier.invoices.show', $invoice, absolute: false),
                'receipt',
                ['local_invoice_id' => $invoice->id, 'category' => NotificationCategory::INVOICE, 'domain' => NotificationDomain::LOCAL]
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
            'Pengingat pengiriman berkas fisik',
            $invoice->submission_number.' — Jadwal penyerahan berkas fisik invoice Anda sudah dekat.',
            route('local-supplier.invoices.show', $invoice, absolute: false),
            'receipt',
            ['local_invoice_id' => $invoice->id, 'category' => NotificationCategory::INVOICE, 'domain' => NotificationDomain::LOCAL]
        );
    }
}
