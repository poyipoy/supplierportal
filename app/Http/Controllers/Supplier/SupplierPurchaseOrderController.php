<?php

namespace App\Http\Controllers\Supplier;

use App\Enums\PoAcknowledgementStatus;
use App\Exceptions\BusinessRuleArgumentException;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Export\Filters\PurchaseOrderExportFilters;
use App\Models\MaterialClaim;
use App\Models\PurchaseOrder;
use App\Models\Shipment;
use App\Services\MaterialProgressService;
use App\Services\Procurement\PoAcknowledgementService;
use App\Services\RegionalDisplayFormatter;
use App\Support\HashidCodec;
use App\Support\StatusHelper;
use App\Support\Ui\RowAction;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Yajra\DataTables\Facades\DataTables;

class SupplierPurchaseOrderController extends Controller
{
    /**
     * Supplier: View accepted POs (read-only).
     */
    public function index(Request $request, RegionalDisplayFormatter $regionalFormatter)
    {
        $filters = PurchaseOrderExportFilters::validated($request, supplier: true, index: true);
        $supplierId = (int) auth()->id();
        $query = PurchaseOrder::query()
            ->select([
                'purchase_orders.id',
                'purchase_orders.supplier_id',
                'purchase_orders.po_number',
                'purchase_orders.status',
                'purchase_orders.estimated_arrival',
                'purchase_orders.actual_arrival',
                'purchase_orders.notes',
                'purchase_orders.created_at',
            ])
            ->withResolvedTotalIdr()
            ->with([
                'awards.quotationItem',
                'quotations:id,pr_id,currency,exchange_rate_id',
                'quotations.items.prItem',
                'quotations.exchange_rate',
                'quotations.purchaseRequisition:id,period_id,pr_number',
                'quotations.purchaseRequisition.period:id,name,month,year',
                'shipmentItems.shipment',
                'shipmentItems.qcItems.inspection.materialClaims',
            ])
            ->selectSub(
                MaterialClaim::query()
                    ->select('material_claims.id')
                    ->whereColumn('material_claims.po_id', 'purchase_orders.id')
                    ->where('material_claims.supplier_id', $supplierId)
                    ->where('material_claims.status', 'pending')
                    ->latest('material_claims.created_at')
                    ->limit(1),
                'pending_claim_id',
            )
            ->selectSub(
                MaterialClaim::query()
                    ->select('material_claims.id')
                    ->whereColumn('material_claims.po_id', 'purchase_orders.id')
                    ->where('material_claims.supplier_id', $supplierId)
                    ->latest('material_claims.created_at')
                    ->limit(1),
                'latest_claim_id',
            )
            ->where('supplier_id', auth()->id())
            ->orderBy('created_at', 'desc');

        PurchaseOrderExportFilters::apply($query, $filters);
        // PUR-08: is_overdue reads this flag instead of querying per row.
        $query->withExists('shipmentItems as has_shipment_items');
        // E7: Distinguish Active POs with vs without an active/arrived shipment.
        $query->withExists([
            'shipmentItems as has_active_shipments' => fn ($q) => $q->whereHas(
                'shipment',
                fn ($s) => $s->whereIn('status', [Shipment::STATUS_SUBMITTED, Shipment::STATUS_ARRIVED])
            ),
        ]);

        if ($request->ajax()) {
            return DataTables::eloquent($query)
                ->filter(fn ($query) => null)
                ->addColumn('po_number_display', fn ($po) => $po->po_number)
                ->addColumn('period_name', function ($po) {
                    $periods = $po->quotations->map(fn ($q) => $q->purchaseRequisition?->period?->display_label)->filter()->unique();

                    return $periods->count() > 1
                        ? $periods->first().' +'.($periods->count() - 1)
                        : ($periods->first() ?? '-');
                })
                ->addColumn('pr_reference', fn ($po) => e($po->pr_reference))
                ->addColumn('remark_display', function ($po) {
                    $notes = trim((string) $po->notes);

                    if ($notes === '') {
                        return '-';
                    }

                    $preview = Str::limit((string) preg_replace('/\s+/u', ' ', $notes), 40);

                    return '<span title="'.e($notes).'">'.e($preview).'</span>';
                })
                // Q4C: a foreign PO without a snapshot rate has no IDR total.
                ->addColumn('total_idr', fn ($po) => $po->resolved_total_idr === null
                    ? '— ('.__('purchasing.copy.exchange_rate_unavailable').')'
                    : 'Rp '.$regionalFormatter->number(number_format((float) $po->resolved_total_idr, 0, ',', '.'), 'indonesian'))
                ->addColumn('status_badge', function ($po) {
                    return StatusHelper::poStatusChip(
                        $po->status,
                        $po->is_overdue,
                        $po->has_active_shipments
                    );
                })
                ->addColumn('outstanding_breakdown', function ($po) {
                    $items = collect($po->fulfillmentProjection()['items'] ?? []);
                    $activeQty = (int) $items->sum(fn ($item) => (int) ($item['remaining_qty'] ?? 0) + (int) ($item['in_transit_qty'] ?? 0));
                    $waitingQcQty = (int) $items->sum(fn ($item) => (int) ($item['waiting_qc_qty'] ?? 0));

                    $activeChip = '<span class="ui-status-chip ui-status-chip--info">'
                        .e(__('supplier.copy.outstanding_active_pcs', ['qty' => number_format($activeQty)]))
                        .'</span>';
                    $waitingTone = $waitingQcQty > 0 ? 'warning' : 'neutral';
                    $waitingChip = '<span class="ui-status-chip ui-status-chip--'.$waitingTone.'">'
                        .e(__('supplier.copy.outstanding_waiting_qc_pcs', ['qty' => number_format($waitingQcQty)]))
                        .'</span>';

                    return '<div class="tw:inline-flex tw:flex-wrap tw:items-center tw:gap-1 ui-tabular-nums" title="'.e(__('supplier.copy.outstanding_breakdown_tooltip')).'">'
                        .$activeChip.$waitingChip
                        .'</div>';
                })
                ->addColumn('estimated_date', fn ($po) => $po->estimated_arrival ? $regionalFormatter->date($po->estimated_arrival, 'human') : '-')
                ->addColumn('action', function ($po) {
                    $html = '<div class="tw:inline-flex tw:gap-1 tw:justify-end tw:flex-wrap">';
                    if ($po->pending_claim_id) {
                        $html .= RowAction::link(
                            route('supplier.claims.show', HashidCodec::encode((int) $po->pending_claim_id)),
                            'Claim Response',
                            'danger',
                            $po->po_number
                        );
                    } elseif ($po->latest_claim_id) {
                        $html .= RowAction::link(
                            route('supplier.claims.show', HashidCodec::encode((int) $po->latest_claim_id)),
                            'View Claim',
                            'danger',
                            $po->po_number
                        );
                    }
                    $html .= RowAction::detail(route('supplier.purchase-orders.show', $po), $po->po_number);
                    $html .= '</div>';

                    return $html;
                })
                ->filterColumn('period_name', function ($query, $keyword) {
                    $query->whereHas('quotations.purchaseRequisition.period', fn ($periodQuery) => $periodQuery->where('name', 'like', '%'.$keyword.'%'));
                })
                ->filterColumn('pr_reference', function ($query, $keyword) {
                    $query->wherePrReferenceContains($keyword);
                })
                ->filterColumn('remark_display', function ($query, $keyword) {
                    $query->where('notes', 'like', '%'.$keyword.'%');
                })
                ->rawColumns(['status_badge', 'outstanding_breakdown', 'remark_display', 'action'])
                ->ignoreSelectsInCountQuery()
                ->only([
                    'po_number_display',
                    'period_name',
                    'pr_reference',
                    'remark_display',
                    'total_idr',
                    'status_badge',
                    'outstanding_breakdown',
                    'estimated_date',
                    'action',
                ])
                ->make(true);
        }

        return view('supplier.po.index');
    }

    /**
     * Supplier: View detail PO (read-only).
     */
    public function show($id)
    {
        $supplierId = auth()->id();

        $po = PurchaseOrder::with([
            'supplier',
            'quotations.items.prItem',
            'quotations.purchaseRequisition.period',
            'quotations.exchange_rate',
            'documents',
            'materialClaims' => fn ($q) => $q->where('supplier_id', $supplierId)->latest(),
            'awards.latestProgressUpdate.updatedByUser',
            'awards.prItem',
            'awards.quotation',
            'awards.quotationItem',
            'shipmentItems.shipment.documents.latestAttachment',
            'supplierSalesContractAttachment',
            'purchasingSalesContractAttachment',
        ])->findOrFail($id);

        // STRICT: only allow if this PO belongs to the logged-in supplier
        if ((int) $po->supplier_id !== (int) $supplierId) {
            abort(403, __('supplier.copy.you_do_not_have_access_to_this_purchase_order_03db80'));
        }

        $quotationRates = $po->quotations->mapWithKeys(function ($q) {
            return [$q->id => $q->exchange_rate];
        });

        $progressService = app(MaterialProgressService::class);
        $poSummary = $progressService->poSummary($po);
        $itemProjections = collect($poSummary['items'] ?? []);

        // Read-only customs documentation summary for display (does not write to DB)
        $customsSummary = $po->customsDocumentationSummary();

        return view('supplier.po.show', compact('po', 'quotationRates', 'itemProjections', 'poSummary', 'customsSummary'));
    }

    public function acknowledge(Request $request, $id, PoAcknowledgementService $acknowledgementService)
    {
        $po = PurchaseOrder::where('supplier_id', auth()->id())->findOrFail($id);

        $validated = $request->validate([
            'mode' => ['nullable', 'string', 'in:confirm,propose_change'],
            'supplier_promised_date' => ['nullable', 'date'],
            'acknowledgement_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $updatedPo = $acknowledgementService->acknowledgeImportPo($po, $request->user(), $validated);
        } catch (BusinessRuleArgumentException|BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $message = $updatedPo->acknowledgementStatusEnum() === PoAcknowledgementStatus::CHANGE_REQUESTED
            ? __('purchasing.po_ack.messages.change_requested')
            : __('purchasing.po_ack.messages.acknowledged');

        return redirect()->route('supplier.purchase-orders.show', $updatedPo)
            ->with('success', $message);
    }

    public function uploadSalesContract(Request $request, $id, PoAcknowledgementService $acknowledgementService)
    {
        $po = PurchaseOrder::where('supplier_id', auth()->id())->findOrFail($id);

        $validated = $request->validate([
            'sales_contract_file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:10240'],
            'sales_contract_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $updatedPo = $acknowledgementService->uploadSupplierSalesContract(
                $po,
                $request->file('sales_contract_file'),
                $request->user(),
                $validated['sales_contract_notes'] ?? null
            );
        } catch (BusinessRuleArgumentException|BusinessRuleException|\InvalidArgumentException $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return back()->withInput()->with('error', $e->getMessage());
        }

        if ($request->expectsJson()) {
            $request->session()->flash('success', __('purchasing.copy.sales_contract_status_supplier_uploaded'));

            return response()->json(['redirect' => route('supplier.purchase-orders.show', $updatedPo)]);
        }

        return redirect()->route('supplier.purchase-orders.show', $updatedPo)
            ->with('success', __('purchasing.copy.sales_contract_status_supplier_uploaded'));
    }
}
