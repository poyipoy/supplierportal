<?php

namespace App\Http\Controllers\Qc;

use App\Http\Controllers\Controller;
use App\Http\Requests\Export\Filters\InspectionExportFilters;
use App\Http\Requests\Qc\StoreQcInspectionRequest;
use App\Models\PurchaseOrder;
use App\Models\QcInspection;
use App\Models\Shipment;
use App\Services\NotificationService;
use App\Services\Qc\QcInspectionSubmissionService;
use App\Services\RegionalDisplayFormatter;
use App\Support\BusinessTime;
use App\Support\StatusHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Yajra\DataTables\Facades\DataTables;

class QcInspectionController extends Controller
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * Display inspection lists for waiting and history tabs.
     */
    public function index()
    {
        $waitingCount = $this->waitingPurchaseOrdersQuery()->count();
        $historyCount = QcInspection::count();

        return view('qc.inspections.index', compact('waitingCount', 'historyCount'));
    }

    public function dataWaiting(Request $request, RegionalDisplayFormatter $regionalFormatter)
    {
        $query = $this->waitingPurchaseOrdersQuery()->with([
            'supplier',
            'quotations' => fn ($query) => $query->withCount('items'),
            'shipmentItems.shipment',
            'qcInspections:id,po_id,shipment_id',
        ])
            ->orderBy('actual_arrival', 'asc');

        return DataTables::eloquent($query)
            ->addColumn('po_number_display', fn ($po) => $po->po_number)
            ->addColumn('supplier_name', fn ($po) => $po->supplier->name ?? '-')
            ->addColumn('arrival_date', fn ($po) => $po->actual_arrival ? $regionalFormatter->date($po->actual_arrival, 'human') : '-')
            ->addColumn('item_count', function ($po) {
                $count = (int) $po->quotations->sum('items_count');

                return trans_choice('purchasing.copy.item_count', $count, ['count' => $count]);
            })
            ->addColumn('action', function ($po) {
                $inspectedShipmentIds = $po->qcInspections
                    ->whereNotNull('shipment_id')
                    ->pluck('shipment_id')
                    ->all();
                $waitingShipment = $po->shipments()
                    ->first(fn ($s) => $s->status === Shipment::STATUS_ARRIVED && ! in_array($s->id, $inspectedShipmentIds, true));

                $url = $waitingShipment
                    ? route('qc.inspections.create', ['po_id' => $po, 'shipment_id' => $waitingShipment])
                    : route('qc.inspections.create', $po);

                return '<a href="'.$url.'" class="ui-data-action ui-data-action--primary ui-focus-ring">'.e(__('qc.copy.start_inspection')).'</a>';
            })
            ->rawColumns(['action'])
            ->make(true);
    }

    public function dataHistory(Request $request)
    {
        $query = QcInspection::with(['purchaseOrder.supplier', 'shipment', 'inspector'])
            ->orderBy('inspected_at', 'desc');

        InspectionExportFilters::apply($query, InspectionExportFilters::validated($request));

        return DataTables::eloquent($query)
            ->addColumn('po_number', fn ($i) => $i->purchaseOrder->po_number ?? '-')
            ->addColumn('supplier_name', fn ($i) => $i->purchaseOrder->supplier->name ?? '-')
            ->addColumn('inspected_date', fn ($i) => $i->inspected_at ? BusinessTime::format($i->inspected_at, 'd M Y, H:i') : '-')
            ->addColumn('status_badge', fn ($i) => StatusHelper::badge(
                StatusHelper::qcBadge($i->status),
                StatusHelper::qcLabel($i->status)
            ))
            ->addColumn('inspector_name', fn ($i) => $i->inspector->name ?? '-')
            ->addColumn('action', fn ($i) => '<a href="'.route('qc.inspections.show', $i).'" class="ui-data-action ui-data-action--primary ui-focus-ring">'.e(__('qc.copy.details')).'</a>')
            ->rawColumns(['status_badge', 'action'])
            ->make(true);
    }

    /**
     * Start inspection form.
     */
    public function create(Request $request, $po_id)
    {
        $po = PurchaseOrder::with(['supplier', 'quotations.items.prItem', 'shipmentItems.shipment'])->findOrFail($po_id);

        if (! in_array($po->status, ['waiting_qc', 'claim_needed'], true)) {
            return redirect()->route('qc.inspections.index')->with('error', __('qc.copy.this_po_is_not_in_waiting_qc_status'));
        }

        $shipment = null;
        if ($request->filled('shipment_id')) {
            $rawShipmentId = $request->query('shipment_id');
            $shipment = (new Shipment)->resolveRouteBinding($rawShipmentId);

            if (! $shipment) {
                return redirect()->route('qc.inspections.index')->with('error', __('qc.copy.the_specified_shipment_could_not_be_found'));
            }
        }

        if (! $shipment) {
            $inspectedShipmentIds = QcInspection::where('po_id', $po->id)
                ->whereNotNull('shipment_id')
                ->pluck('shipment_id')
                ->all();

            $shipment = $po->shipments()
                ->first(fn ($s) => $s->status === Shipment::STATUS_ARRIVED && ! in_array($s->id, $inspectedShipmentIds, true));
        }

        if ($shipment) {
            $hasPoItems = $shipment->items()->where('purchase_order_id', $po->id)->exists();
            if (! $hasPoItems) {
                return redirect()->route('qc.inspections.index')->with('error', __('qc.copy.the_specified_shipment_does_not_contain_items_for_this_po'));
            }

            if (QcInspection::where('po_id', $po->id)->where('shipment_id', $shipment->id)->exists()) {
                return redirect()->route('qc.inspections.index')->with('error', __('qc.feedback.already_inspected', ['po' => $po->po_number]));
            }
        } else {
            if (QcInspection::where('po_id', $po->id)->whereNull('shipment_id')->exists()) {
                return redirect()->route('qc.inspections.index')->with('error', __('qc.copy.this_po_has_already_been_inspected'));
            }
        }

        return view('qc.inspections.create', compact('po', 'shipment'));
    }

    /**
     * Save inspection results.
     */
    public function store(Request $request, $po_id, QcInspectionSubmissionService $submissionService)
    {
        $po = PurchaseOrder::with(['quotations.items.prItem'])->findOrFail($po_id);
        $poPrItems = $po->quotations
            ->flatMap(fn ($quotation) => $quotation->items->pluck('prItem'))
            ->filter()
            ->keyBy('id');

        $validated = StoreQcInspectionRequest::validateFor($request, $poPrItems);

        try {
            $inspection = $submissionService->submit($request, $po_id, $poPrItems, $validated);

            if ($request->expectsJson()) {
                $request->session()->flash('success', __('qc.copy.inspection_result_successfully_saved'));

                return response()->json(['redirect' => route('qc.inspections.show', $inspection)]);
            }

            return redirect()->route('qc.inspections.show', $inspection)->with('success', __('qc.copy.inspection_result_successfully_saved'));
        } catch (ValidationException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            if ($request->expectsJson()) {
                throw ValidationException::withMessages(['attachments' => $e->getMessage()]);
            }

            return back()->withInput()->with('error', $e->getMessage());
        } catch (\Exception $e) {
            Log::error('QC Inspection store failed', [
                'po_id' => $po_id,
                'user_id' => auth()->id(),
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            if ($request->expectsJson()) {
                throw $e;
            }

            return back()->withInput()->with('error', __('qc.copy.an_error_occurred_while_saving_the_inspection_please_try_again'));
        }
    }

    /**
     * Display inspection details.
     */
    public function show($id)
    {
        $inspection = QcInspection::with([
            'purchaseOrder.supplier',
            'shipment',
            'inspector',
            'items.prItem',
            'items.shipmentItem',
            'attachments',
        ])->findOrFail($id);

        return view('qc.inspections.show', compact('inspection'));
    }

    /**
     * Check if the PO is fully fulfilled and all deliveries have passed QC inspection.
     */
    protected function isPoFullyFulfilledAndInspected(PurchaseOrder $po, ?Shipment $currentShipment = null): bool
    {
        return $po->isFullyFulfilledAndInspected($currentShipment);
    }

    /**
     * Add evidence photos for a saved NG inspection.
     */
    public function storeAttachments(Request $request, $id, QcInspectionSubmissionService $submissionService)
    {
        $inspection = QcInspection::findOrFail($id);

        if ($inspection->status !== 'ng') {
            return back()->with('error', __('qc.copy.evidence_photos_can_only_be_added_for_ng_inspections'));
        }

        $request->validate([
            'attachments' => 'required|array|min:1',
            'attachments.*' => 'required|file|mimes:jpg,jpeg,png|max:10240',
        ], [
            'attachments.required' => __('qc.copy.select_at_least_1_ng_evidence_photo'),
            'attachments.min' => __('qc.copy.select_at_least_1_ng_evidence_photo'),
            'attachments.*.mimes' => __('qc.copy.ng_evidence_photos_must_be_jpg_jpeg_or_png_files'),
            'attachments.*.max' => __('qc.copy.each_ng_evidence_photo_must_not_exceed_10mb'),
        ]);

        try {
            $submissionService->storeAdditionalAttachments($request, $inspection);
        } catch (\Throwable $e) {
            if ($e instanceof ValidationException || $request->expectsJson()) {
                throw $e;
            }
            Log::error('QC Inspection storeAttachments failed', [
                'inspection_id' => $id,
                'exception' => $e->getMessage(),
            ]);

            return back()->with('error', __('qc.copy.failed_to_save_evidence_photos_please_try_again'));
        }

        if ($request->expectsJson()) {
            $request->session()->flash('success', __('qc.copy.qc_evidence_photos_successfully_added'));

            return response()->json(['redirect' => route('qc.inspections.show', $inspection)]);
        }

        return back()->with('success', __('qc.copy.qc_evidence_photos_successfully_added'));
    }

    private function waitingPurchaseOrdersQuery()
    {
        return PurchaseOrder::query()->where(function ($query) {
            $query->where('status', 'waiting_qc')
                ->orWhere(function ($claimQuery) {
                    $claimQuery->where('status', 'claim_needed')
                        ->whereHas('shipmentItems', function ($itemQuery) {
                            $itemQuery->whereHas('shipment', fn ($shipmentQuery) => $shipmentQuery
                                ->where('status', Shipment::STATUS_ARRIVED))
                                ->whereDoesntHave('qcItems');
                        });
                });
        });
    }
}
