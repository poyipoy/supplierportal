<?php

namespace App\Services\LocalInvoice;

use App\Models\LocalInvoice;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

class InvoiceSubmissionService
{
    public function __construct(private InvoiceDocumentService $documents, private InvoiceNotificationService $notifications) {}

    public function submit(User $actor, array $data, array $files): LocalInvoice
    {
        Gate::forUser($actor)->authorize('create', LocalInvoice::class);
        $written = [];
        try {
            return DB::transaction(function () use ($actor, $data, $files, &$written) {
                User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($actor->fresh())->authorize('create', LocalInvoice::class);
                $supplier = Supplier::where('user_id', $actor->id)->lockForUpdate()->first();
                if (! $supplier || $supplier->payment_term_days < 1 || $supplier->payment_term_days > 365) {
                    throw ValidationException::withMessages(['payment_term_days' => 'A Local payment term must be configured by Admin.']);
                }
                if (LocalInvoice::where('supplier_id', $actor->id)->where('invoice_number', $data['invoice_number'])->exists()) {
                    throw ValidationException::withMessages(['invoice_number' => 'This invoice number has already been submitted.']);
                }
                $year = now()->year;
                DB::table('local_invoice_sequences')->insertOrIgnore(['year' => $year, 'last_number' => 0]);
                $sequence = DB::table('local_invoice_sequences')->where('year', $year)->lockForUpdate()->first();
                $number = $sequence->last_number + 1;
                DB::table('local_invoice_sequences')->where('year', $year)->update(['last_number' => $number]);
                $suffix = $year.'-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT);
                $invoice = LocalInvoice::create(array_merge($this->values($data), [
                    'supplier_id' => $actor->id, 'submission_number' => 'SUB-'.$suffix, 'currency' => 'IDR',
                    'payment_term_days_snapshot' => $supplier->payment_term_days,
                    'status' => 'WAITING_PHYSICAL_DOCUMENT', 'submitted_at' => now(), 'revision_number' => 1,
                ]));
                $revision = $invoice->revisions()->create(array_merge($this->values($data), ['revision_number' => 1]));
                $this->documents->store($revision, $actor, $files, $written);
                $invoice->receipt()->create(['receipt_number' => 'TT-'.$suffix, 'issued_at' => now()]);
                $history = $invoice->statusHistories()->create(['from_status' => null, 'to_status' => $invoice->status, 'actor_id' => $actor->id, 'event' => 'submitted', 'created_at' => now()]);
                $this->notifications->send($invoice, $history);

                return $invoice;
            });
        } catch (Throwable $exception) {
            $this->documents->compensate($written);
            throw $exception;
        }
    }

    public function resubmit(User $actor, LocalInvoice $invoice, array $data, array $files): LocalInvoice
    {
        Gate::forUser($actor)->authorize('resubmit', $invoice);
        $written = [];
        try {
            return DB::transaction(function () use ($actor, $invoice, $data, $files, &$written) {
                $invoice = LocalInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($actor->fresh())->authorize('resubmit', $invoice);
                if ($invoice->status !== 'NEED_REVISION' || $data['invoice_number'] !== $invoice->invoice_number) {
                    throw ValidationException::withMessages(['invoice' => 'Only an invoice needing revision can be resubmitted, with its original invoice number.']);
                }
                $request = $invoice->statusHistories()->where('event', 'revision_requested')->latest('id')->firstOrFail();
                $revision = $invoice->revisions()->create(array_merge($this->values($data), [
                    'revision_number' => $invoice->revision_number + 1, 'reason' => $request->notes,
                    'requested_by' => $request->actor_id, 'requested_at' => $request->created_at, 'resubmitted_at' => now(),
                ]));
                $this->documents->store($revision, $actor, $files, $written);
                $invoice->physicalVerifications()->create(['revision_number' => $invoice->revision_number, 'status' => 'invalidated', 'verified_by' => $actor->id, 'verified_at' => now(), 'created_at' => now(), 'notes' => 'Superseded by revision '.$revision->revision_number]);
                $invoice->fill(array_merge($this->values($data), ['revision_number' => $revision->revision_number, 'status' => 'WAITING_PHYSICAL_DOCUMENT', 'physical_verified_at' => null, 'review_started_at' => null]))->save();
                $history = $invoice->statusHistories()->create(['from_status' => 'NEED_REVISION', 'to_status' => $invoice->status, 'actor_id' => $actor->id, 'event' => 'resubmitted', 'created_at' => now()]);
                $this->notifications->send($invoice, $history);

                return $invoice;
            });
        } catch (Throwable $exception) {
            $this->documents->compensate($written);
            throw $exception;
        }
    }

    private function values(array $data): array
    {
        return Arr::only($data, ['invoice_number', 'invoice_date', 'po_number', 'invoice_amount', 'tax_amount']);
    }
}
