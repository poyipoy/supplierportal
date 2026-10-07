<?php

namespace App\Http\Controllers\Purchasing;

use App\Exports\PurchaseOrderDetailExport;
use App\Exports\PurchaseOrdersExport;
use App\Exports\PurchaseRequisitionDetailExport;
use App\Exports\QuotationDetailExport;
use App\Exports\QuotationsExport;
use App\Exports\RequisitionsExport;
use App\Exports\ShipmentsExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Export\AdvancedExportRequest;
use App\Http\Requests\Export\Filters\PurchaseOrderExportFilters;
use App\Http\Requests\Export\Filters\QuotationExportFilters;
use App\Http\Requests\Export\Filters\RequisitionExportFilters;
use App\Http\Requests\Export\Filters\ShipmentExportFilters;
use App\Models\ExportJob;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\User;
use App\Support\ExportDispatcher;
use Illuminate\Http\Request;

class ExportController extends Controller
{
    public function requisitions(AdvancedExportRequest $request)
    {
        $filters = RequisitionExportFilters::validated($request);

        $exportJob = ExportDispatcher::dispatch(
            __('exports.job_labels.pr_summary'),
            RequisitionsExport::class,
            [
                $filters['period_id'] ?? null,
                $filters['status'] ?? null,
                $filters['search'] ?? null,
            ],
            'rekap_requisitions_'.now()->format('Ymd_His').'.xlsx', // biz-time:ignore instant filename
            $request->exportOptions('purchasing.pr'),
        );

        return $this->dispatchResponse($request, $exportJob);
    }

    public function purchaseOrders(AdvancedExportRequest $request)
    {
        $filters = PurchaseOrderExportFilters::validated($request);

        $exportJob = ExportDispatcher::dispatch(
            __('exports.job_labels.po_summary'),
            PurchaseOrdersExport::class,
            [
                $filters['supplier_id'] ?? null,
                $filters['start_date'] ?? null,
                $filters['end_date'] ?? null,
                $filters['po_number'] ?? null,
                $filters['status'] ?? null,
                $filters['search'] ?? null,
            ],
            'rekap_po_'.now()->format('Ymd_His').'.xlsx', // biz-time:ignore instant filename
            $request->exportOptions('purchasing.po'),
        );

        return $this->dispatchResponse($request, $exportJob);
    }

    public function shipments(AdvancedExportRequest $request)
    {
        $filters = ShipmentExportFilters::validated($request);

        $exportJob = ExportDispatcher::dispatch(
            __('exports.job_labels.shipment_summary'),
            ShipmentsExport::class,
            [
                $filters['supplier_id'] ?? null,
                $filters['status'] ?? null,
                $filters['search'] ?? null,
                $filters['start_date'] ?? null,
                $filters['end_date'] ?? null,
                ...($request->input('options') !== null ? [$filters['shipment_number'] ?? null] : []),
            ],
            'rekap_shipments_'.now()->format('Ymd_His').'.xlsx', // biz-time:ignore instant filename
            $request->exportOptions('purchasing.shipments'),
        );

        return $this->dispatchResponse($request, $exportJob);
    }

    public function requisitionDetail(Request $request, PurchaseRequisition $purchaseRequisition)
    {
        $exportJob = ExportDispatcher::dispatch(
            __('purchasing.copy.detail_purchase_requisition'),
            PurchaseRequisitionDetailExport::class,
            [(int) $purchaseRequisition->getKey()],
            'detail_pr_'.$purchaseRequisition->getKey().'_'.now()->format('Ymd_His').'.xlsx', // biz-time:ignore instant filename
        );

        return $this->dispatchResponse($request, $exportJob);
    }

    public function quotations(AdvancedExportRequest $request)
    {
        $filters = QuotationExportFilters::validated($request);

        $exportJob = ExportDispatcher::dispatch(
            __('exports.job_labels.quotation_summary'),
            QuotationsExport::class,
            [$filters],
            'rekap_quotations_'.now()->format('Ymd_His').'.xlsx', // biz-time:ignore instant filename
            $request->exportOptions('purchasing.quotations'),
        );

        return $this->dispatchResponse($request, $exportJob);
    }

    public function quotationDetail(Request $request, Quotation $quotation)
    {
        $exportJob = ExportDispatcher::dispatch(
            __('purchasing.copy.detail_quotation'),
            QuotationDetailExport::class,
            [(int) $quotation->getKey()],
            'detail_quotation_'.$quotation->getKey().'_'.now()->format('Ymd_His').'.xlsx', // biz-time:ignore instant filename
        );

        return $this->dispatchResponse($request, $exportJob);
    }

    public function purchaseOrderDetail(Request $request, PurchaseOrder $purchaseOrder)
    {
        $exportJob = ExportDispatcher::dispatch(
            __('purchasing.copy.detail_purchase_order'),
            PurchaseOrderDetailExport::class,
            [(int) $purchaseOrder->getKey()],
            'detail_po_'.$purchaseOrder->getKey().'_'.now()->format('Ymd_His').'.xlsx', // biz-time:ignore instant filename
        );

        return $this->dispatchResponse($request, $exportJob);
    }

    private function resolveSupplierFilter(mixed $value): ?User
    {
        if ($value === null || $value === '') {
            return null;
        }

        abort_unless(is_string($value) && ! ctype_digit($value), 404);

        $supplier = (new User)->resolveRouteBinding($value);
        abort_unless($supplier instanceof User && $supplier->role === 'supplier', 404);

        return $supplier;
    }

    private function dispatchResponse(Request $request, ExportJob $exportJob)
    {
        $message = __('purchasing.copy.the_export_request_was_accepted_the_file_will_download_automatically_when_ready');

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
