<?php

namespace App\Http\Controllers\Supplier;

use App\Exports\PurchaseOrderDetailExport;
use App\Exports\PurchaseOrdersExport;
use App\Exports\QuotationDetailExport;
use App\Exports\QuotationsExport;
use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

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
            ])],
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        $scope = ! empty($filters['period_id']) ? 'period_'.$filters['period_id'] : 'all';

        return Excel::download(
            new QuotationsExport($filters, (int) auth()->id(), true),
            'quotation_supplier_'.$scope.'_'.now()->format('Ymd_His').'.xlsx'
        );
    }

    public function quotationDetail(Quotation $quotation)
    {
        abort_unless(
            (int) $quotation->supplier_id === (int) auth()->id(),
            403,
            'You do not have access to this quotation.'
        );

        return Excel::download(
            new QuotationDetailExport((int) $quotation->getKey(), (int) auth()->id(), false),
            'detail_quotation_'.$quotation->getKey().'_'.now()->format('Ymd_His').'.xlsx'
        );
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
                'end_date' => 'End date cannot be before start date.',
            ]);
        }

        return Excel::download(
            new PurchaseOrdersExport(
                (int) auth()->id(),
                $filters['start_date'] ?? null,
                $filters['end_date'] ?? null,
                $filters['po_number'] ?? null,
                $filters['status'] ?? null,
                $filters['search'] ?? null,
            ),
            'rekap_po_supplier_'.now()->format('Ymd_His').'.xlsx'
        );
    }

    public function purchaseOrderDetail(PurchaseOrder $purchaseOrder)
    {
        abort_unless(
            (int) $purchaseOrder->supplier_id === (int) auth()->id(),
            403,
            'You do not have access to this purchase order.'
        );

        return Excel::download(
            new PurchaseOrderDetailExport((int) $purchaseOrder->getKey(), (int) auth()->id()),
            'detail_po_'.$purchaseOrder->getKey().'_'.now()->format('Ymd_His').'.xlsx'
        );
    }
}
