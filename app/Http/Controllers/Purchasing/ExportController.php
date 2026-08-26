<?php

namespace App\Http\Controllers\Purchasing;

use App\Exports\PurchaseOrderDetailExport;
use App\Exports\PurchaseOrdersExport;
use App\Exports\PurchaseRequisitionDetailExport;
use App\Exports\QuotationDetailExport;
use App\Exports\QuotationsExport;
use App\Exports\RequisitionsExport;
use App\Http\Controllers\Controller;
use App\Models\ExchangeRate;
use App\Models\ExportJob;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\User;
use App\Support\ExportDispatcher;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ExportController extends Controller
{
    public function requisitions(Request $request)
    {
        $filters = $request->validate([
            'period_id' => ['nullable', 'integer', 'exists:periods,id'],
            'status' => ['nullable', Rule::in(['draft', 'submitted', 'rejected', 'bidding', 'completed'])],
            'search' => ['nullable', 'string', 'max:255'],
        ]);
        if (isset($filters['period_id'])) {
            $filters['period_id'] = (int) $filters['period_id'];
        }

        $exportJob = ExportDispatcher::dispatch(
            'Rekap Purchase Requisition',
            RequisitionsExport::class,
            [
                $filters['period_id'] ?? null,
                $filters['status'] ?? null,
                $filters['search'] ?? null,
            ],
            'rekap_requisitions_'.now()->format('Ymd_His').'.xlsx',
        );

        return $this->dispatchResponse($request, $exportJob);
    }

    public function purchaseOrders(Request $request)
    {
        $supplier = $this->resolveSupplierFilter($request->query('supplier_id'));

        $filters = $request->validate([
            'supplier_id' => [
                'nullable',
                'string',
                'max:255',
            ],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'po_number' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'waiting_qc', 'claim_needed', 'overdue', 'completed', 'cancelled'])],
            'search' => ['nullable', 'string', 'max:255'],
        ]);
        $filters['supplier_id'] = $supplier?->getKey();

        if (! empty($filters['start_date']) && ! empty($filters['end_date']) && $filters['end_date'] < $filters['start_date']) {
            throw ValidationException::withMessages([
                'end_date' => 'End date cannot be before start date.',
            ]);
        }

        $exportJob = ExportDispatcher::dispatch(
            'Rekap Purchase Order',
            PurchaseOrdersExport::class,
            [
                $filters['supplier_id'] ?? null,
                $filters['start_date'] ?? null,
                $filters['end_date'] ?? null,
                $filters['po_number'] ?? null,
                $filters['status'] ?? null,
                $filters['search'] ?? null,
            ],
            'rekap_po_'.now()->format('Ymd_His').'.xlsx',
        );

        return $this->dispatchResponse($request, $exportJob);
    }

    public function requisitionDetail(Request $request, PurchaseRequisition $purchaseRequisition)
    {
        $exportJob = ExportDispatcher::dispatch(
            'Detail Purchase Requisition',
            PurchaseRequisitionDetailExport::class,
            [(int) $purchaseRequisition->getKey()],
            'detail_pr_'.$purchaseRequisition->getKey().'_'.now()->format('Ymd_His').'.xlsx',
        );

        return $this->dispatchResponse($request, $exportJob);
    }

    public function quotations(Request $request)
    {
        $supplier = $this->resolveSupplierFilter($request->query('supplier_id'));

        $filters = $request->validate([
            'pr_number' => ['nullable', 'string', 'max:100'],
            'date_from' => ['nullable', 'date_format:Y-m'],
            'date_to' => ['nullable', 'date_format:Y-m'],
            'supplier_id' => [
                'nullable',
                'string',
                'max:255',
            ],
            'status' => ['nullable', Rule::in([
                Quotation::STATUS_SUBMITTED,
                Quotation::STATUS_REVISION_REQUESTED,
                Quotation::STATUS_ACCEPTED,
                Quotation::STATUS_REJECTED,
            ])],
            'currency' => ['nullable', Rule::in(ExchangeRate::CURRENCIES)],
        ]);
        $filters['supplier_id'] = $supplier?->getKey();

        if (! empty($filters['date_from']) && ! empty($filters['date_to']) && $filters['date_to'] < $filters['date_from']) {
            throw ValidationException::withMessages([
                'date_to' => 'End date cannot be before start date.',
            ]);
        }

        $exportJob = ExportDispatcher::dispatch(
            'Rekap Quotation',
            QuotationsExport::class,
            [$filters],
            'rekap_quotations_'.now()->format('Ymd_His').'.xlsx',
        );

        return $this->dispatchResponse($request, $exportJob);
    }

    public function quotationDetail(Request $request, Quotation $quotation)
    {
        $exportJob = ExportDispatcher::dispatch(
            'Detail Quotation',
            QuotationDetailExport::class,
            [(int) $quotation->getKey()],
            'detail_quotation_'.$quotation->getKey().'_'.now()->format('Ymd_His').'.xlsx',
        );

        return $this->dispatchResponse($request, $exportJob);
    }

    public function purchaseOrderDetail(Request $request, PurchaseOrder $purchaseOrder)
    {
        $exportJob = ExportDispatcher::dispatch(
            'Detail Purchase Order',
            PurchaseOrderDetailExport::class,
            [(int) $purchaseOrder->getKey()],
            'detail_po_'.$purchaseOrder->getKey().'_'.now()->format('Ymd_His').'.xlsx',
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
        $message = 'The export request was accepted. The file will download automatically when ready.';

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
