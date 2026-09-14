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
    public function __construct(
        private InvoiceDocumentService $documents,
        private InvoiceNotificationService $notifications,
        private LocalPoReferenceService $poReferenceService,
        private \App\Services\VendorMaster\VendorMasterService $vendorMasterService
    ) {}

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

                // 1. Validate Wednesday delivery schedule if provided
                if (! empty($data['scheduled_physical_delivery_date'])) {
                    $sched = \Carbon\Carbon::parse($data['scheduled_physical_delivery_date']);
                    if ($sched->dayOfWeek !== \Carbon\Carbon::WEDNESDAY) {
                        throw ValidationException::withMessages(['scheduled_physical_delivery_date' => 'Physical document delivery schedule must be on a Wednesday.']);
                    }
                }

                // 2. Validate & Resolve PO / GR Boundary
                $poNum = $data['po_number'] ?? ($data['manual_po_number'] ?? ($data['internal_po_reference'] ?? null));
                $poSource = $data['po_source'] ?? null;
                if (! $poSource) {
                    if ($poNum && $this->poReferenceService->getInternalPoDetails($actor, $poNum)) {
                        $poSource = 'INTERNAL';
                        $internalPoRef = $poNum;
                        $manualPoNum = null;
                        $manualGrRef = null;
                    } else {
                        $poSource = 'MANUAL';
                        $manualPoNum = $poNum;
                        $internalPoRef = null;
                        $manualGrRef = $data['manual_gr_reference'] ?? ($data['gr_reference'] ?? ($poNum ? 'GR-'.$poNum : null));
                    }
                } else {
                    $internalPoRef = $data['internal_po_reference'] ?? ($poSource === 'INTERNAL' ? $poNum : null);
                    $manualPoNum = $data['manual_po_number'] ?? ($poSource === 'MANUAL' ? $poNum : null);
                    $manualGrRef = $data['manual_gr_reference'] ?? ($data['gr_reference'] ?? null);
                }

                try {
                    $poResolved = $this->poReferenceService->validateAndResolve(
                        $actor,
                        $poSource,
                        $internalPoRef,
                        $manualPoNum,
                        (float) $data['invoice_amount'],
                        $manualGrRef
                    );
                } catch (\InvalidArgumentException $e) {
                    throw ValidationException::withMessages(['po_number' => $e->getMessage()]);
                }

                // 3. Tax / PPN scheme calculation
                $ppnScheme = $data['ppn_scheme'] ?? '11%';
                $invoiceDpp = (float) $data['invoice_amount'];
                $calcPpn = match ($ppnScheme) {
                    '11%' => round($invoiceDpp * 0.11, 2),
                    '1.1%' => round($invoiceDpp * 0.011, 2),
                    default => 0.0,
                };
                $taxAmount = isset($data['tax_amount']) ? (float) $data['tax_amount'] : $calcPpn;

                // 4. Generate sequence number
                $year = now()->year;
                DB::table('local_invoice_sequences')->insertOrIgnore(['year' => $year, 'last_number' => 0]);
                $sequence = DB::table('local_invoice_sequences')->where('year', $year)->lockForUpdate()->first();
                $number = $sequence->last_number + 1;
                DB::table('local_invoice_sequences')->where('year', $year)->update(['last_number' => $number]);
                $suffix = $year.'-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT);

                $invoiceData = array_merge($this->values($data), [
                    'supplier_id' => $actor->id,
                    'submission_number' => 'SUB-'.$suffix,
                    'currency' => 'IDR',
                    'tax_amount' => $taxAmount,
                    'po_source' => $poResolved['po_source'],
                    'po_number' => $poResolved['po_number'],
                    'internal_po_reference' => $poResolved['internal_po_reference'],
                    'manual_po_number' => $poResolved['manual_po_number'],
                    'internal_gr_reference' => $poResolved['internal_gr_reference'],
                    'manual_gr_reference' => $poResolved['manual_gr_reference'],
                    'po_value_snapshot' => $poResolved['po_value_snapshot'],
                    'po_invoiced_snapshot' => $poResolved['po_invoiced_snapshot'],
                    'po_remaining_snapshot' => $poResolved['po_remaining_snapshot'],
                    'has_po_discrepancy' => $poResolved['has_po_discrepancy'],
                    'ppn_scheme' => $ppnScheme,
                    'submitted_ppn_amount' => $taxAmount,
                    'scheduled_physical_delivery_date' => $data['scheduled_physical_delivery_date'] ?? null,
                    'payment_term_days_snapshot' => $supplier ? (int) ($supplier->payment_term_days ?? 30) : 30,
                    'due_date' => null, // Finalized upon cashier receipt
                    'status' => LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT,
                    'submitted_at' => now(),
                    'revision_number' => 1,
                ]);

                $invoice = LocalInvoice::create($invoiceData);

                $revision = $invoice->revisions()->create(array_merge($this->values($data), [
                    'revision_number' => 1,
                    'tax_amount' => $taxAmount,
                    'po_source' => $poResolved['po_source'],
                    'po_number' => $poResolved['po_number'],
                    'internal_po_reference' => $poResolved['internal_po_reference'],
                    'manual_po_number' => $poResolved['manual_po_number'],
                    'internal_gr_reference' => $poResolved['internal_gr_reference'],
                    'manual_gr_reference' => $poResolved['manual_gr_reference'],
                    'ppn_scheme' => $ppnScheme,
                    'submitted_ppn_amount' => $taxAmount,
                    'has_po_discrepancy' => $poResolved['has_po_discrepancy'],
                ]));

                $requiresTaxInvoice = $this->vendorMasterService->requiresFakturPajak($supplier);
                $requiresDeliveryNote = $this->vendorMasterService->requiresSuratJalan($supplier);

                $this->documents->store($revision, $actor, $files, $written, $requiresTaxInvoice, $requiresDeliveryNote);
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
                if ($invoice->status !== LocalInvoice::STATUS_NEED_REVISION || $data['invoice_number'] !== $invoice->invoice_number) {
                    throw ValidationException::withMessages(['invoice' => 'Only an invoice needing revision can be resubmitted, with its original invoice number.']);
                }

                $supplier = Supplier::where('user_id', $actor->id)->firstOrFail();

                // 1. Validate Wednesday delivery schedule if provided
                if (! empty($data['scheduled_physical_delivery_date'])) {
                    $sched = \Carbon\Carbon::parse($data['scheduled_physical_delivery_date']);
                    if ($sched->dayOfWeek !== \Carbon\Carbon::WEDNESDAY) {
                        throw ValidationException::withMessages(['scheduled_physical_delivery_date' => 'Physical document delivery schedule must be on a Wednesday.']);
                    }
                }

                $request = $invoice->statusHistories()->where('event', 'revision_requested')->latest('id')->firstOrFail();
                $revision = $invoice->revisions()->create(array_merge($this->values($data), [
                    'revision_number' => $invoice->revision_number + 1,
                    'reason' => $request->notes,
                    'requested_by' => $request->actor_id,
                    'requested_at' => $request->created_at,
                    'resubmitted_at' => now(),
                    'po_source' => $invoice->po_source,
                    'po_number' => $invoice->po_number,
                    'internal_po_reference' => $invoice->internal_po_reference,
                    'manual_po_number' => $invoice->manual_po_number,
                    'internal_gr_reference' => $invoice->internal_gr_reference,
                    'manual_gr_reference' => $invoice->manual_gr_reference,
                    'ppn_scheme' => $invoice->ppn_scheme,
                    'submitted_ppn_amount' => $invoice->submitted_ppn_amount,
                    'has_po_discrepancy' => $invoice->has_po_discrepancy,
                ]));

                $requiresTaxInvoice = $this->vendorMasterService->requiresFakturPajak($supplier);
                $requiresDeliveryNote = $this->vendorMasterService->requiresSuratJalan($supplier);

                $this->documents->store($revision, $actor, $files, $written, $requiresTaxInvoice, $requiresDeliveryNote);

                $invoice->physicalVerifications()->create([
                    'revision_number' => $invoice->revision_number,
                    'status' => 'invalidated',
                    'verified_by' => $actor->id,
                    'verified_at' => now(),
                    'created_at' => now(),
                    'notes' => 'Superseded by revision '.$revision->revision_number,
                ]);

                $invoice->fill(array_merge($this->values($data), [
                    'revision_number' => $revision->revision_number,
                    'status' => LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT,
                    'physical_verified_at' => null,
                    'cashier_received_at' => null,
                    'review_started_at' => null,
                    'scheduled_physical_delivery_date' => $data['scheduled_physical_delivery_date'] ?? $invoice->scheduled_physical_delivery_date,
                ]))->save();

                $history = $invoice->statusHistories()->create([
                    'from_status' => LocalInvoice::STATUS_NEED_REVISION,
                    'to_status' => $invoice->status,
                    'actor_id' => $actor->id,
                    'event' => 'resubmitted',
                    'created_at' => now(),
                ]);
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
