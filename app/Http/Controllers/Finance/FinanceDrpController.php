<?php

namespace App\Http\Controllers\Finance;

use App\Exports\PaymentBatchDrpExport;
use App\Http\Controllers\Controller;
use App\Models\LocalInvoice;
use App\Models\PaymentBatch;
use App\Models\PaymentGroup;
use App\Models\PaymentItem;
use App\Models\User;
use App\Services\Payment\PaymentBatchService;
use App\Services\Payment\PaymentVoucherService;
use App\Support\ExportDispatcher;
use Illuminate\Http\Request;

class FinanceDrpController extends Controller
{
    public function indexSupplier(Request $request)
    {
        $batches = PaymentBatch::where('batch_type', PaymentBatch::TYPE_SUPPLIER)
            ->withCount('groups')
            ->latest('id')
            ->paginate(15);

        // Candidate Ready to Pay invoices not yet in active DRP
        $supplier = $this->resolveSupplierFilter($request->query('supplier_id'));
        $eligibleInvoices = LocalInvoice::eligibleForPaymentBatch()
            ->when($supplier, fn ($q) => $q->where('supplier_id', $supplier->id))
            ->whereDoesntHave('paymentItem', function ($q) {
                $q->where('status', PaymentItem::STATUS_ACTIVE)
                    ->whereHas('group', fn ($g) => $g->where('status', PaymentGroup::STATUS_UNPAID)
                        ->whereHas('batch', fn ($b) => $b->whereIn('status', PaymentBatch::ACTIVE_STATUSES))
                    );
            })
            ->with(['supplier.supplier', 'receipt', 'currentVerification'])
            ->latest('id')
            ->get();

        return view('finance.drp.supplier', ['batches' => $batches, 'eligibleInvoices' => $eligibleInvoices, 'supplierFilter' => $supplier, 'suppliers' => User::localEligible()->with('supplier')->orderBy('name')->get()]);
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
        $batch->load(['groups.items.payable', 'groups.items.localInvoicePayment.overpayment', 'groups.items.localInvoiceVoucher.payment.transfers', 'creator', 'finalizer']);

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

    public function cancelBatch(PaymentBatch $batch, Request $request, PaymentBatchService $service)
    {
        $request->validate(['reason' => 'required|string|max:500']);
        $service->cancelBatch($batch, $request->user(), $request->input('reason'));

        return redirect()->route($batch->batch_type === PaymentBatch::TYPE_SUPPLIER ? 'finance.drp.supplier' : 'finance.drp.ga')
            ->with('success', "Batch DRP [{$batch->batch_number}] berhasil dibatalkan. Seluruh tagihan telah dikembalikan ke antrean Ready to Pay.");
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

    public function export(PaymentBatch $batch, Request $request)
    {
        if ($batch->batch_type !== PaymentBatch::TYPE_SUPPLIER) {
            abort(422, 'Hanya DRP Supplier yang dapat diexport.');
        }

        if ($batch->status === PaymentBatch::STATUS_CANCELLED) {
            abort(422, 'Batch DRP yang dibatalkan tidak dapat diexport.');
        }

        $hasActiveItems = $batch->groups()
            ->where('status', '!=', PaymentGroup::STATUS_CANCELLED)
            ->whereHas('items', function ($q) {
                $q->where('status', PaymentItem::STATUS_ACTIVE)
                    ->where('payable_type', LocalInvoice::class);
            })
            ->exists();

        if (! $hasActiveItems) {
            abort(422, 'Batch DRP tidak memiliki tagihan aktif untuk diexport.');
        }

        $exportJob = ExportDispatcher::dispatch(
            "DRP Supplier {$batch->batch_number}",
            PaymentBatchDrpExport::class,
            [$request->user()->id, [$batch->id]],
            "DRP_{$batch->batch_number}.xlsx"
        );

        $message = 'Permintaan export DRP telah diterima. File akan terunduh otomatis setelah siap.';

        if ($request->wantsJson()) {
            return response()->json([
                'message' => $message,
                'export_job_id' => $exportJob->getRouteKey(),
                'exports_url' => route('exports.index', absolute: false),
                'status_url' => route('exports.status', $exportJob, absolute: false),
                'cancel_url' => route('exports.cancel', $exportJob, absolute: false),
            ], 202);
        }

        return back()->with('info', $message);
    }

    private function resolveSupplierFilter(mixed $value): ?User
    {
        if ($value === null || $value === '') {
            return null;
        }
        abort_unless(is_string($value) && ! ctype_digit($value), 404);
        $supplier = (new User)->resolveRouteBinding($value);
        abort_unless($supplier instanceof User && $supplier->isLocalEligible(), 404);

        return $supplier;
    }
}
