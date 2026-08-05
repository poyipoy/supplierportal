<?php

namespace App\Http\Controllers\Purchasing;

use App\Exports\PurchaseOrdersExport;
use App\Exports\PurchaseOrderDetailExport;
use App\Exports\PurchaseRequisitionDetailExport;
use App\Exports\QuotationDetailExport;
use App\Exports\QuotationsExport;
use App\Exports\RequisitionsExport;
use App\Http\Controllers\Controller;
use App\Models\ExchangeRate;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

class ExportController extends Controller
{
    public function requisitions(Request $request)
    {
        $filters = $request->validate([
            'period_id' => ['nullable', 'integer', 'exists:periods,id'],
            'status' => ['nullable', Rule::in(['draft', 'submitted', 'rejected', 'bidding', 'completed'])],
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        return Excel::download(
            new RequisitionsExport(
                $filters['period_id'] ?? null,
                $filters['status'] ?? null,
                $filters['search'] ?? null
            ),
            'rekap_requisitions_'.now()->format('Ymd_His').'.xlsx'
        );
    }

    public function purchaseOrders(Request $request)
    {
        $filters = $request->validate([
            'supplier_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query->where('role', 'supplier')),
            ],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'po_number' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'waiting_qc', 'claim_needed', 'overdue', 'completed', 'cancelled'])],
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        if (! empty($filters['start_date']) && ! empty($filters['end_date']) && $filters['end_date'] < $filters['start_date']) {
            throw ValidationException::withMessages([
                'end_date' => 'End date cannot be before start date.',
            ]);
        }

        return Excel::download(
            new PurchaseOrdersExport(
                $filters['supplier_id'] ?? null,
                $filters['start_date'] ?? null,
                $filters['end_date'] ?? null,
                $filters['po_number'] ?? null,
                $filters['status'] ?? null,
                $filters['search'] ?? null
            ),
            'rekap_po_'.now()->format('Ymd_His').'.xlsx'
        );
    }

    public function requisitionDetail(PurchaseRequisition $purchaseRequisition)
    {
        return Excel::download(
            new PurchaseRequisitionDetailExport((int) $purchaseRequisition->getKey()),
            'detail_pr_'.$purchaseRequisition->getKey().'_'.now()->format('Ymd_His').'.xlsx'
        );
    }

    public function quotations(Request $request)
    {
        $filters = $request->validate([
            'pr_number' => ['nullable', 'string', 'max:100'],
            'date_from' => ['nullable', 'date_format:Y-m'],
            'date_to' => ['nullable', 'date_format:Y-m'],
            'supplier_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query->where('role', 'supplier')),
            ],
            'status' => ['nullable', Rule::in([
                Quotation::STATUS_SUBMITTED,
                Quotation::STATUS_REVISION_REQUESTED,
                Quotation::STATUS_ACCEPTED,
                Quotation::STATUS_REJECTED,
            ])],
            'currency' => ['nullable', Rule::in(ExchangeRate::CURRENCIES)],
        ]);

        if (! empty($filters['date_from']) && ! empty($filters['date_to']) && $filters['date_to'] < $filters['date_from']) {
            throw ValidationException::withMessages([
                'date_to' => 'End date cannot be before start date.',
            ]);
        }

        return Excel::download(
            new QuotationsExport($filters),
            'rekap_quotations_'.now()->format('Ymd_His').'.xlsx'
        );
    }

    public function quotationDetail(Quotation $quotation)
    {
        return Excel::download(
            new QuotationDetailExport((int) $quotation->getKey()),
            'detail_quotation_'.$quotation->getKey().'_'.now()->format('Ymd_His').'.xlsx'
        );
    }

    public function purchaseOrderDetail(PurchaseOrder $purchaseOrder)
    {
        return Excel::download(
            new PurchaseOrderDetailExport((int) $purchaseOrder->getKey()),
            'detail_po_'.$purchaseOrder->getKey().'_'.now()->format('Ymd_His').'.xlsx'
        );
    }
}
