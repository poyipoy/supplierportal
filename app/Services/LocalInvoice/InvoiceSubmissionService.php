<?php

namespace App\Services\LocalInvoice;

use App\Models\LocalInvoice;
use App\Models\LocalPurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\VendorMaster\VendorMasterService;
use Carbon\Carbon;
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
        private LocalGrReservationService $reservations,
        private VendorMasterService $vendorMasterService
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
                    $sched = Carbon::parse($data['scheduled_physical_delivery_date']);
                    if ($sched->dayOfWeek !== Carbon::WEDNESDAY) {
                        throw ValidationException::withMessages(['scheduled_physical_delivery_date' => 'Physical document delivery schedule must be on a Wednesday.']);
                    }
                }

                // The new browser flow submits the authoritative Local PO / whole-GR
                // chain. The legacy branch remains available for grandfathered
                // service callers and historical provider-backed records; when an
                // authoritative PO exists, it is rejected unless its ID and GR IDs
                // are supplied explicitly.
                if (array_key_exists('local_purchase_order_id', $data)) {
                    $po = LocalPurchaseOrder::whereKey($data['local_purchase_order_id'])->lockForUpdate()->first();
                    if (! $po || (int) $po->supplier_id !== (int) $actor->id) {
                        throw ValidationException::withMessages(['local_purchase_order_id' => 'The selected PO does not belong to this supplier.']);
                    }
                    $poResolved = [
                        'po_source' => 'INTERNAL', 'po_number' => $po->po_number,
                        'internal_po_reference' => $po->po_number, 'manual_po_number' => null,
                        'internal_gr_reference' => null, 'manual_gr_reference' => null,
                        'po_value_snapshot' => $po->total_amount, 'po_invoiced_snapshot' => null,
                        'po_remaining_snapshot' => null, 'has_po_discrepancy' => false,
                    ];
                } else {
                    $poNum = $data['po_number'] ?? ($data['manual_po_number'] ?? ($data['internal_po_reference'] ?? null));
                    $poSource = $data['po_source'] ?? null;
                    $internalPoRef = $data['internal_po_reference'] ?? (($poSource === 'INTERNAL' || blank($poSource)) ? $poNum : null);
                    $manualPoNum = $data['manual_po_number'] ?? (($poSource === 'MANUAL' || blank($poSource)) ? $poNum : null);
                    $manualGrRef = $data['manual_gr_reference'] ?? ($data['gr_reference'] ?? null);
                    try {
                        $poResolved = $this->poReferenceService->validateAndResolve($actor, $poSource, $internalPoRef, $manualPoNum, (float) $data['invoice_amount'], $manualGrRef);
                    } catch (\InvalidArgumentException $e) {
                        $field = str_contains(strtolower($e->getMessage()), 'manual goods receipt')
                            ? 'manual_gr_reference'
                            : 'po_number';
                        throw ValidationException::withMessages([$field => $e->getMessage()]);
                    }
                    if (($poResolved['authoritative'] ?? false) === true) {
                        throw ValidationException::withMessages(['local_purchase_order_id' => 'Select the authoritative Local Purchase Order and its whole Goods Receipts.']);
                    }
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
                if (array_key_exists('local_purchase_order_id', $data)) {
                    $this->reservations->reserve($actor, $invoice, (int) $data['local_purchase_order_id'], $data['goods_receipt_ids']);
                    $invoice->refresh();
                }

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
                    $sched = Carbon::parse($data['scheduled_physical_delivery_date']);
                    if ($sched->dayOfWeek !== Carbon::WEDNESDAY) {
                        throw ValidationException::withMessages(['scheduled_physical_delivery_date' => 'Physical document delivery schedule must be on a Wednesday.']);
                    }
                }

                $request = $invoice->statusHistories()->where('event', 'revision_requested')->latest('id')->firstOrFail();

                if ($invoice->local_purchase_order_id && ! array_key_exists('local_purchase_order_id', $data)) {
                    throw ValidationException::withMessages([
                        'local_purchase_order_id' => 'Authoritative Local Supplier invoices must be resubmitted with their PO and whole GR selection.',
                    ]);
                }

                if (array_key_exists('local_purchase_order_id', $data)) {
                    $po = LocalPurchaseOrder::whereKey($data['local_purchase_order_id'])->lockForUpdate()->first();
                    if (! $po || (int) $po->supplier_id !== (int) $actor->id) {
                        throw ValidationException::withMessages(['local_purchase_order_id' => 'The selected PO does not belong to this supplier.']);
                    }
                    $poResolved = [
                        'po_source' => 'INTERNAL', 'po_number' => $po->po_number,
                        'internal_po_reference' => $po->po_number, 'manual_po_number' => null,
                        'internal_gr_reference' => null, 'manual_gr_reference' => null,
                        'po_value_snapshot' => $po->total_amount, 'po_invoiced_snapshot' => null,
                        'po_remaining_snapshot' => null, 'has_po_discrepancy' => false,
                    ];
                } else {
                    $poNum = $data['po_number'] ?? ($data['manual_po_number'] ?? ($data['internal_po_reference'] ?? $invoice->po_number));
                    $poSource = $data['po_source'] ?? $invoice->po_source;
                    $internalPoRef = $data['internal_po_reference'] ?? (($poSource === 'INTERNAL' || blank($poSource)) ? $poNum : null);
                    $manualPoNum = $data['manual_po_number'] ?? (($poSource === 'MANUAL' || blank($poSource)) ? $poNum : null);
                    $manualGrRef = $data['manual_gr_reference'] ?? ($data['gr_reference'] ?? $invoice->manual_gr_reference);
                    try {
                        $poResolved = $this->poReferenceService->validateAndResolve($actor, $poSource, $internalPoRef, $manualPoNum, (float) $data['invoice_amount'], $manualGrRef);
                    } catch (\InvalidArgumentException $e) {
                        $field = str_contains(strtolower($e->getMessage()), 'manual goods receipt')
                            ? 'manual_gr_reference'
                            : 'po_number';
                        throw ValidationException::withMessages([$field => $e->getMessage()]);
                    }
                    if (($poResolved['authoritative'] ?? false) === true) {
                        throw ValidationException::withMessages(['local_purchase_order_id' => 'Select the authoritative Local Purchase Order and its whole Goods Receipts.']);
                    }
                }

                // 3. Recalculate PPN and tax amount
                $ppnScheme = $data['ppn_scheme'] ?? ($invoice->ppn_scheme ?? '11%');
                $invoiceDpp = (float) $data['invoice_amount'];
                $calcPpn = match ($ppnScheme) {
                    '11%' => round($invoiceDpp * 0.11, 2),
                    '1.1%' => round($invoiceDpp * 0.011, 2),
                    default => 0.0,
                };
                $taxAmount = isset($data['tax_amount']) ? (float) $data['tax_amount'] : $calcPpn;

                $revision = $invoice->revisions()->create(array_merge($this->values($data), [
                    'revision_number' => $invoice->revision_number + 1,
                    'reason' => $request->notes,
                    'requested_by' => $request->actor_id,
                    'requested_at' => $request->created_at,
                    'resubmitted_at' => now(),
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
                $retainedDocIds = array_filter((array) ($data['kept_document_ids'] ?? []));

                $this->documents->store($revision, $actor, $files, $written, $requiresTaxInvoice, $requiresDeliveryNote, $retainedDocIds);

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
                    'tax_amount' => $taxAmount,
                    'status' => LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT,
                    'physical_verified_at' => null,
                    'cashier_received_at' => null,
                    'review_started_at' => null,
                    'due_date' => null,
                    'payment_term_days_snapshot' => null,
                    'scheduled_payment_date' => null,
                    'missed_delivery_count' => 0,
                    'scheduled_physical_delivery_date' => $data['scheduled_physical_delivery_date'] ?? $invoice->scheduled_physical_delivery_date,
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
                ]))->save();

                if (array_key_exists('local_purchase_order_id', $data)) {
                    $this->reservations->reserve($actor, $invoice, (int) $data['local_purchase_order_id'], $data['goods_receipt_ids']);
                    $invoice->refresh();
                    $revision->update(['internal_gr_reference' => $invoice->internal_gr_reference]);
                }

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

    public function cancel(User $actor, LocalInvoice $invoice): LocalInvoice
    {
        Gate::forUser($actor)->authorize('cancel', $invoice);
        return DB::transaction(function () use ($actor, $invoice) {
            $locked = LocalInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor->fresh())->authorize('cancel', $locked);
            $from = $locked->status;
            $this->reservations->release($locked, $actor);
            $locked->update(['status' => LocalInvoice::STATUS_CANCELLED]);
            $history = $locked->statusHistories()->create(['from_status' => $from, 'to_status' => LocalInvoice::STATUS_CANCELLED, 'actor_id' => $actor->id, 'event' => 'cancelled', 'created_at' => now()]);
            $this->notifications->send($locked, $history);
            return $locked->fresh();
        });
    }

    private function values(array $data): array
    {
        return Arr::only($data, ['invoice_number', 'invoice_date', 'po_number', 'invoice_amount', 'tax_amount', 'tax_invoice_number']);
    }
}
