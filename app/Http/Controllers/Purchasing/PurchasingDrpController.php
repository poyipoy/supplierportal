<?php

declare(strict_types=1);

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Finance\LocalInvoiceSettlementController;
use App\Models\LocalInvoiceVoucher;
use App\Models\PaymentBatch;
use App\Models\SupplierOverpaymentRefund;
use App\Services\Payment\PaymentVoucherService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PurchasingDrpController extends Controller
{
    public function indexSupplier(Request $request): View
    {
        $batches = PaymentBatch::where('batch_type', PaymentBatch::TYPE_SUPPLIER)
            ->withCount('groups')
            ->latest('id')
            ->paginate(15);

        return view('purchasing.drp.supplier', compact('batches'));
    }

    public function indexGa(Request $request): View
    {
        $batches = PaymentBatch::where('batch_type', PaymentBatch::TYPE_GA)
            ->withCount('groups')
            ->latest('id')
            ->paginate(15);

        return view('purchasing.drp.ga', compact('batches'));
    }

    public function show(PaymentBatch $batch, PaymentVoucherService $voucherService): View
    {
        $batch->load([
            'groups.items.payable',
            'groups.items.localInvoicePayment.overpayment',
            'groups.items.localInvoiceVoucher.payment.transfers',
            'creator',
            'finalizer',
        ]);

        return view('purchasing.drp.show', [
            'batch' => $batch,
            'voucherService' => $voucherService,
        ]);
    }

    public function indexPaid(Request $request): View
    {
        $tab = (string) $request->query('tab', 'unpaid');
        $type = (string) $request->query('type', '');
        $q = trim((string) $request->query('q', ''));
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');
        $overpaymentStatus = (string) $request->query('overpayment_status', '');

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

        $query = PaymentBatch::with([
            'creator',
            'finalizer',
            'groups.items.localInvoiceVoucher',
            'groups.items.localInvoicePayment.overpayment',
            'groups.items.payable',
        ])
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
        if ($type !== '') {
            $query->where('batch_type', strtoupper($type));
        }

        // Filter by overpayment status
        if ($overpaymentStatus !== '' && $overpaymentStatus !== 'all') {
            $os = strtolower(trim($overpaymentStatus));
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

        $batches = $query->latest('id')->paginate(15)->withQueryString();

        return view('purchasing.drp.paid', compact(
            'batches',
            'tab',
            'type',
            'q',
            'dateFrom',
            'dateTo',
            'metrics',
            'overpaymentStatus',
            'openOverpaymentsCount'
        ));
    }

    public function printVoucher(
        Request $request,
        LocalInvoiceVoucher $voucher,
        LocalInvoiceSettlementController $settlementController
    ): Response {
        return $settlementController->printVoucher($request, $voucher);
    }
}
