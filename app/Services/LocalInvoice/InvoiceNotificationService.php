<?php

namespace App\Services\LocalInvoice;

use App\Models\LocalInvoice;
use App\Models\LocalInvoiceStatusHistory;
use App\Models\User;
use App\Notifications\LocalInvoice\InvoicePaidNotification;
use App\Notifications\LocalInvoice\InvoiceSubmissionReceivedNotification;
use App\Notifications\LocalInvoice\PhysicalDeliveryReminderNotification;
use App\Notifications\LocalInvoice\RevisionRequiredNotification;
use App\Services\NotificationService;
use App\Support\NotificationCategory;
use App\Support\NotificationDomain;
use Illuminate\Support\Facades\DB;

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

        // 2. Email events for supplier
        $recipient = $invoice->supplier;
        if (! $recipient) {
            return;
        }

        if (in_array($history->event, ['submitted', 'resubmitted'], true)) {
            DB::afterCommit(function () use ($recipient, $invoice) {
                try {
                    $recipient->notify(new InvoiceSubmissionReceivedNotification(
                        $invoice->submission_number,
                        $invoice->invoice_number,
                        $invoice->scheduled_physical_delivery_date?->format('d M Y'),
                        route('local-supplier.invoices.show', $invoice)
                    ));
                } catch (\Throwable $exception) {
                    report($exception);
                }
            });
        } elseif ($history->event === 'revision_requested') {
            DB::afterCommit(function () use ($recipient, $invoice, $history) {
                try {
                    $recipient->notify(new RevisionRequiredNotification(
                        $invoice->submission_number,
                        $history->notes ?: 'Please check revision details.',
                        route('local-supplier.invoices.show', $invoice)
                    ));
                } catch (\Throwable $exception) {
                    report($exception);
                }
            });
        } elseif ($history->event === 'paid') {
            DB::afterCommit(function () use ($recipient, $invoice, $history) {
                try {
                    // Critical invariant: Never expose internal planned payment date to Supplier
                    $recipient->notify(new InvoicePaidNotification(
                        $invoice->submission_number,
                        $invoice->invoice_number,
                        (float) $invoice->invoice_amount,
                        $history->notes,
                        route('local-supplier.invoices.show', $invoice)
                    ));
                } catch (\Throwable $exception) {
                    report($exception);
                }
            });
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

        try {
            $recipient->notify(new PhysicalDeliveryReminderNotification(
                $invoice->submission_number,
                $invoice->invoice_number,
                $invoice->scheduled_physical_delivery_date->format('d M Y'),
                route('local-supplier.invoices.show', $invoice)
            ));
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
