<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\PaymentBatch;
use App\Models\SupplierOverpaymentRefund;
use App\Services\Payment\PaymentExecutionService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FinanceDrpPaidController extends Controller
{
    public function index(Request $request)
    {
        $tab = $request->query('tab', 'unpaid');
        $type = $request->query('type');
        $q = trim((string) $request->query('q', ''));
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');
        $overpaymentStatus = $request->query('overpayment_status');

        // Metrics calculations
        $unpaidStatuses = [
            PaymentBatch::STATUS_DRAFT,
            PaymentBatch::STATUS_FINALIZED,
            PaymentBatch::STATUS_PARTIALLY_PAID,
        ];

        $unpaidBatchesQuery = PaymentBatch::whereIn('status', $unpaidStatuses)
            ->where('status', '!=', PaymentBatch::STATUS_CANCELLED)
            ->where('total_net_amount', '>', 0);

        $paidBatchesQuery = PaymentBatch::where('status', PaymentBatch::STATUS_PAID);

        $unpaidCount = (int) (clone $unpaidBatchesQuery)->count();
        $unpaidAmount = (float) (clone $unpaidBatchesQuery)->sum('total_net_amount');
        $paidCount = (int) (clone $paidBatchesQuery)->count();
        $paidAmount = (float) (clone $paidBatchesQuery)->sum('total_net_amount');

        $metrics = [
            'total_batches' => $unpaidCount + $paidCount,
            'unpaid_count' => $unpaidCount,
            'unpaid_amount' => $unpaidAmount,
            'paid_count' => $paidCount,
            'paid_amount' => $paidAmount,
        ];

        $openOverpaymentsCount = (int) SupplierOverpaymentRefund::where('status', SupplierOverpaymentRefund::STATUS_OPEN)->count();

        $query = PaymentBatch::with(['creator', 'finalizer', 'groups.items.localInvoiceVoucher', 'groups.items.localInvoicePayment.overpayment', 'groups.items.payable'])
            ->withCount('groups')
            ->where('status', '!=', PaymentBatch::STATUS_CANCELLED);

        // Status tab filtering
        if ($tab === 'paid') {
            $query->where('status', PaymentBatch::STATUS_PAID);
        } elseif ($tab === 'unpaid') {
            $query->whereIn('status', $unpaidStatuses)
                ->where('total_net_amount', '>', 0);
        } else {
            // tab === 'all'
            $query->where(function ($sub) use ($unpaidStatuses) {
                $sub->where('status', PaymentBatch::STATUS_PAID)
                    ->orWhere(function ($active) use ($unpaidStatuses) {
                        $active->whereIn('status', $unpaidStatuses)
                            ->where('total_net_amount', '>', 0);
                    });
            });
        }

        // Search by batch number, payee, or reference
        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('batch_number', 'like', "%{$q}%")
                    ->orWhereHas('groups', function ($g) use ($q) {
                        $g->where('payee_name', 'like', "%{$q}%")
                            ->orWhere('transfer_reference', 'like', "%{$q}%");
                    });
            });
        }

        // Filter by batch type
        if (! empty($type)) {
            $query->where('batch_type', strtoupper($type));
        }

        // Filter by overpayment status
        if (! empty($overpaymentStatus) && $overpaymentStatus !== 'all') {
            $os = strtolower(trim((string) $overpaymentStatus));
            if ($os === 'has_overpayment') {
                $query->whereHas('groups.items.localInvoicePayment.overpayment');
            } elseif ($os === 'open') {
                $query->whereHas('groups.items.localInvoicePayment.overpayment', function ($op) {
                    $op->where('status', SupplierOverpaymentRefund::STATUS_OPEN);
                });
            } elseif ($os === 'settled') {
                $query->whereHas('groups.items.localInvoicePayment.overpayment', function ($op) {
                    $op->where('status', SupplierOverpaymentRefund::STATUS_SETTLED);
                })->whereDoesntHave('groups.items.localInvoicePayment.overpayment', function ($op) {
                    $op->where('status', SupplierOverpaymentRefund::STATUS_OPEN);
                });
            }
        }

        // Date range filters
        if (! empty($dateFrom)) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }
        if (! empty($dateTo)) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        // Whitelist tab input
        if (! in_array($tab, ['unpaid', 'paid', 'all'], true)) {
            $tab = 'unpaid';
        }

        $batches = $query->latest('id')->paginate(15)->withQueryString();

        // AJAX: return JSON with rendered HTML fragment + metrics
        if ($request->ajax()) {
            return response()->json([
                'success' => true,
                'html' => view('finance.drp._paid_table_content', compact('batches'))->render(),
                'metrics' => $metrics,
                'tab' => $tab,
                'openOverpaymentsCount' => $openOverpaymentsCount,
                'url' => $request->fullUrl(),
            ]);
        }

        return view('finance.drp.paid', compact('batches', 'tab', 'type', 'q', 'dateFrom', 'dateTo', 'metrics', 'overpaymentStatus', 'openOverpaymentsCount'));
    }

    public function markBatchPaid(PaymentBatch $batch, Request $request, PaymentExecutionService $service)
    {
        if ($batch->isDraft()) {
            throw ValidationException::withMessages([
                'batch' => 'Batch DRP masih berstatus DRAFT. Finalisasi batch terlebih dahulu sebelum dapat ditandai lunas.',
            ]);
        }

        if ($batch->isPaid()) {
            throw ValidationException::withMessages([
                'batch' => "Batch DRP [{$batch->batch_number}] sudah berstatus PAID.",
            ]);
        }

        $data = $request->validate([
            'transfer_reference' => ['required', 'string', 'max:100'],
            'transfer_date' => ['required', 'date_format:Y-m-d'],
            'payment_notes' => ['nullable', 'string', 'max:1000'],
            'custom_amounts' => ['nullable', 'array'],
            'custom_amounts.*' => ['nullable', 'numeric', 'gt:0', 'regex:/^\d{1,18}(\.\d{1,2})?$/'],
            'correction_reasons' => ['nullable', 'array'],
            'correction_reasons.*' => ['nullable', 'string', 'max:1000'],
        ]);

        $service->markEntireBatchPaid($batch, $data, $request->user());

        $freshBatch = $batch->fresh(['groups.items.localInvoicePayment.overpayment']);
        $totalOverpayment = (float) $freshBatch->total_overpayment_amount;

        if ($freshBatch->status === PaymentBatch::STATUS_PARTIALLY_PAID) {
            $message = "Batch DRP [{$batch->batch_number}] berhasil diproses dengan status Sebagian Lunas (Partially Paid).";
            if ($totalOverpayment > 0) {
                $message .= ' Terdeteksi kelebihan bayar sebesar Rp '.number_format($totalOverpayment, 0, ',', '.').' yang otomatis dicatat pada modul Refund Overpayment.';
            }
        } else {
            if ($totalOverpayment > 0) {
                $message = "Batch DRP [{$batch->batch_number}] berhasil ditandai Lunas (PAID). Terdeteksi kelebihan bayar sebesar Rp ".number_format($totalOverpayment, 0, ',', '.').' yang otomatis dicatat pada modul Refund Overpayment.';
            } else {
                $message = "Batch DRP [{$batch->batch_number}] berhasil ditandai Lunas (PAID).";
            }
        }

        return back()->with('success', $message);
    }
}
