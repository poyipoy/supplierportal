<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\GaClaim;
use App\Models\LocalInvoice;
use App\Models\PaymentBatch;
use App\Models\PaymentGroup;
use App\Models\PaymentItem;
use App\Services\Payment\PaymentBatchService;
use App\Services\Payment\PaymentExecutionService;
use App\Services\Payment\PaymentVoucherService;
use Illuminate\Http\Request;

class FinanceDrpController extends Controller
{
    public function indexSupplier()
    {
        $batches = PaymentBatch::where('batch_type', PaymentBatch::TYPE_SUPPLIER)
            ->withCount('groups')
            ->latest('id')
            ->paginate(15);

        // Candidate Ready to Pay invoices not yet in active DRP
        $eligibleInvoices = LocalInvoice::where('status', LocalInvoice::STATUS_READY_TO_PAY)
            ->whereDoesntHave('paymentItem', function ($q) {
                $q->where('status', PaymentItem::STATUS_ACTIVE)
                    ->whereHas('group', fn ($g) => $g->where('status', PaymentGroup::STATUS_UNPAID)
                        ->whereHas('batch', fn ($b) => $b->whereIn('status', PaymentBatch::ACTIVE_STATUSES))
                    );
            })
            ->with(['supplier.supplier', 'receipt'])
            ->latest('id')
            ->get();

        return view('finance.drp.supplier', compact('batches', 'eligibleInvoices'));
    }

    public function createSupplierBatch(Request $request, PaymentBatchService $service)
    {
        $request->validate([
            'invoice_ids' => 'required|array|min:1',
            'invoice_ids.*' => 'required|integer|exists:local_invoices,id',
            'notes' => 'nullable|string|max:1000',
        ]);

        $batch = $service->createSupplierBatch($request->user(), $request->input('invoice_ids'), $request->input('notes'));

        return redirect()->route('finance.drp.show', $batch)->with('success', "DRP Batch [{$batch->batch_number}] created in DRAFT status.");
    }

    public function indexGa()
    {
        $batches = PaymentBatch::where('batch_type', PaymentBatch::TYPE_GA)
            ->withCount('groups')
            ->latest('id')
            ->paginate(15);

        return view('finance.drp.ga', compact('batches'));
    }

    public function show(PaymentBatch $batch, PaymentVoucherService $voucherService)
    {
        $batch->load(['groups.items.payable', 'creator', 'finalizer']);

        return view('finance.drp.show', [
            'batch' => $batch,
            'voucherService' => $voucherService,
        ]);
    }

    public function finalize(PaymentBatch $batch, Request $request, PaymentBatchService $service)
    {
        $service->finalizeBatch($batch, $request->user());

        return back()->with('success', "DRP Batch [{$batch->batch_number}] finalized. Membership and fees locked.");
    }

    public function removeItem(PaymentItem $item, Request $request, PaymentBatchService $service)
    {
        $request->validate(['reason' => 'required|string|max:500']);
        $service->removeItem($item, $request->user(), $request->input('reason'));

        return back()->with('success', 'Item removed from Draft DRP.');
    }

    public function overrideFee(PaymentGroup $group, Request $request, PaymentBatchService $service)
    {
        $request->validate([
            'bank_fee' => 'required|numeric|min:0',
            'reason' => 'required|string|max:500',
        ]);

        $service->overrideGroupFee($group, (float) $request->input('bank_fee'), $request->input('reason'), $request->user());

        return back()->with('success', 'Bank fee updated with mandatory reason.');
    }

    public function assignVoucher(PaymentGroup $group, Request $request, PaymentVoucherService $service)
    {
        $request->validate([
            'voucher_number' => 'required|string|max:100',
            'voucher_date' => 'required|date',
        ]);

        $service->assignVoucher($group, $request->input('voucher_number'), $request->input('voucher_date'), $request->user());

        return back()->with('success', 'Voucher details updated.');
    }

    public function markPaid(PaymentGroup $group, Request $request, PaymentExecutionService $service)
    {
        $data = $request->validate([
            'transfer_reference' => 'required|string|max:100',
            'transfer_date' => 'required|date',
            'payment_notes' => 'nullable|string|max:1000',
        ]);

        $service->markGroupPaid($group, $data, $request->user());

        return back()->with('success', 'Payment confirmed for group. Associated invoices marked PAID.');
    }
}
