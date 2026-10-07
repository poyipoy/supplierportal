<?php

namespace App\Http\Controllers\Supplier;

use App\Exports\PurchaseOrderDetailExport;
use App\Exports\PurchaseOrdersExport;
use App\Exports\QuotationDetailExport;
use App\Exports\QuotationsExport;
use App\Http\Controllers\Controller;
use App\Models\ExportJob;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Support\ExportDispatcher;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ExportController extends Controller
{
    public function quotations(Request $request)
    {
        $filters = $request->validate([
            'period_id' => ['nullable', 'integer', 'exists:periods,id'],
            'pr_number' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in([
                'unresponded',
                Quotation::STATUS_DRAFT,
                Quotation::STATUS_REVISION_REQUESTED,
                Quotation::STATUS_SUBMITTED,
                Quotation::STATUS_ACCEPTED,
                Quotation::STATUS_REJECTED,
                Quotation::STATUS_ALL_UNAVAILABLE,
            ])],
            'search' => ['nullable', 'string', 'max:255'],
        ]);
        if (isset($filters['period_id'])) {
            $filters['period_id'] = (int) $filters['period_id'];
        }

        $scope = ! empty($filters['period_id']) ? 'period_'.$filters['period_id'] : 'all';

        $exportJob = ExportDispatcher::dispatch(
            __('exports.job_labels.supplier_quotation_summary'),
            QuotationsExport::class,
            [$filters, (int) auth()->id(), true],
            'quotation_supplier_'.$scope.'_'.now()->format('Ymd_His').'.xlsx', // biz-time:ignore instant filename
        );

        return $this->dispatchResponse($request, $exportJob);
    }

    public function quotationDetail(Request $request, Quotation $quotation)
    {
        abort_unless(
            (int) $quotation->supplier_id === (int) auth()->id(),
            403,
            __('supplier.copy.you_do_not_have_access_to_this_quotation')
        );

        $exportJob = ExportDispatcher::dispatch(
            __('supplier.copy.detail_quotation_supplier'),
            QuotationDetailExport::class,
            [(int) $quotation->getKey(), (int) auth()->id(), false],
            'detail_quotation_'.$quotation->getKey().'_'.now()->format('Ymd_His').'.xlsx', // biz-time:ignore instant filename
        );

        return $this->dispatchResponse($request, $exportJob);
    }

    public function purchaseOrders(Request $request)
    {
        $filters = $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'po_number' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['draft', 'active', 'waiting_qc', 'claim_needed', 'overdue', 'completed', 'cancelled'])],
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        if (! empty($filters['start_date']) && ! empty($filters['end_date']) && $filters['end_date'] < $filters['start_date']) {
            throw ValidationException::withMessages([
                'end_date' => __('supplier.copy.end_date_cannot_be_before_start_date'),
            ]);
        }

        $exportJob = ExportDispatcher::dispatch(
            __('exports.job_labels.supplier_po_summary'),
            PurchaseOrdersExport::class,
            [
                (int) auth()->id(),
                $filters['start_date'] ?? null,
                $filters['end_date'] ?? null,
                $filters['po_number'] ?? null,
                $filters['status'] ?? null,
                $filters['search'] ?? null,
            ],
            'rekap_po_supplier_'.now()->format('Ymd_His').'.xlsx', // biz-time:ignore instant filename
        );

        return $this->dispatchResponse($request, $exportJob);
    }

    public function purchaseOrderDetail(Request $request, PurchaseOrder $purchaseOrder)
    {
        abort_unless(
            (int) $purchaseOrder->supplier_id === (int) auth()->id(),
            403,
            __('supplier.copy.you_do_not_have_access_to_this_purchase_order')
        );

        $exportJob = ExportDispatcher::dispatch(
            __('supplier.copy.detail_purchase_order_supplier'),
            PurchaseOrderDetailExport::class,
            [(int) $purchaseOrder->getKey(), (int) auth()->id()],
            'detail_po_'.$purchaseOrder->getKey().'_'.now()->format('Ymd_His').'.xlsx', // biz-time:ignore instant filename
        );

        return $this->dispatchResponse($request, $exportJob);
    }

    private function dispatchResponse(Request $request, ExportJob $exportJob)
    {
        $message = __('supplier.copy.the_export_request_was_accepted_the_file_will_download_automatically_when_ready');

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
}
