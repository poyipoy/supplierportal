<?php

namespace App\Services\LocalInvoice;

use App\Models\LocalInvoice;
use App\Models\LocalInvoiceStatusHistory;
use App\Models\User;
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
        $recipients = $internal ? User::whereIn('role', ['accounting', 'finance'])->where('is_active', true)->get() : collect([$invoice->supplier]);
        $title = 'Local invoice: '.ucwords(str_replace('_', ' ', $history->event));
        $message = $invoice->submission_number.' — '.($history->notes ?: ($internal ? 'Waiting for physical documents.' : $invoice->invoice_number));
        $url = route(($internal ? 'accounting' : 'local-supplier').'.invoices.show', $invoice, absolute: false);
        app(NotificationService::class)->send($recipients, 'local_invoice.'.$history->event, 'local-invoice:'.$history->id, $title, $message, $url, 'receipt', ['local_invoice_id' => $invoice->id, 'category' => NotificationCategory::INVOICE, 'domain' => NotificationDomain::LOCAL]);
        if ($history->event === 'revision_requested') {
            $recipient = $invoice->supplier;
            DB::afterCommit(function () use ($recipient, $invoice, $history) {
                try {
                    $recipient->notify(new RevisionRequiredNotification($invoice->submission_number, $history->notes, route('local-supplier.invoices.show', $invoice)));
                } catch (\Throwable $exception) {
                    report($exception);
                }
            });
        }
    }
}
