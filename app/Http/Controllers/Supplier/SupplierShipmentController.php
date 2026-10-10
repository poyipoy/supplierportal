<?php

namespace App\Http\Controllers\Supplier;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\Shipment;
use App\Models\ShipmentDocument;
use App\Services\RegionalDisplayFormatter;
use App\Services\ShipmentService;
use App\Support\NumberFormat;
use App\Support\StatusHelper;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Yajra\DataTables\Facades\DataTables;

class SupplierShipmentController extends Controller
{
    public function __construct(
        protected ShipmentService $shipmentService
    ) {}

    /**
     * Display a listing of shipments for the authenticated supplier.
     */
    public function index(Request $request, RegionalDisplayFormatter $regionalFormatter)
    {
        $supplierId = auth()->id();

        $query = Shipment::query()
            ->where('supplier_id', $supplierId)
            ->with(['items.purchaseOrder', 'documents.latestAttachment'])
            ->latest('shipment_date');

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('shipment_date', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('shipment_date', '<=', $request->date_to);
        }

        if ($search = trim((string) $request->input('search.value', $request->input('search')))) {
            $query->where(function ($q) use ($search) {
                $q->where('shipment_number', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%")
                    ->orWhereHas('items.purchaseOrder', fn ($po) => $po->where('po_number', 'like', "%{$search}%"));
            });
        }

        if ($request->ajax()) {
            return DataTables::eloquent($query)
                ->addColumn('shipment_number_display', function ($shp) {
                    $url = route('supplier.shipments.show', $shp);

                    return '<a href="'.e($url).'" class="fw-bold text-primary text-decoration-none">'.e($shp->shipment_number).'</a>';
                })
                ->addColumn('po_references', function ($shp) {
                    $pos = $shp->purchaseOrders();
                    if ($pos->isEmpty()) {
                        return '<span class="tw-text-outline">-</span>';
                    }

                    return $pos->map(fn ($po) => '<span class="ui-status-chip ui-status-chip--neutral me-1">'.e($po->po_number).'</span>')->implode('');
                })
                ->addColumn('items_count', fn ($shp) => '<span class="ui-tabular-nums">'.e($regionalFormatter->number((string) $shp->items->count(), 'plain')).'</span>')
                ->addColumn('total_qty', function ($shp) use ($regionalFormatter) {
                    $total = (int) $shp->items->sum('shipped_qty');
                    $text = number_format($total);

                    return '<span class="fw-bold text-primary ui-tabular-nums">'.e($regionalFormatter->number($text, 'international')).' pcs</span>';
                })
                ->addColumn('actual_weight', function ($shp) use ($regionalFormatter) {
                    $total = (float) $shp->items->sum('actual_weight_kg');
                    $text = NumberFormat::maxDecimals($total);

                    return '<span class="ui-tabular-nums">'.e($regionalFormatter->number($text, 'decimal')).' Kg</span>';
                })
                ->addColumn('total_weight', function ($shp) {
                    $total = (float) $shp->items->sum('actual_weight_kg');

                    return '<span class="fw-bold text-primary ui-tabular-nums">'.NumberFormat::maxDecimals($total).' Kg</span>';
                })
                ->addColumn('shipment_date', fn ($shp) => $shp->shipment_date ? '<span class="ui-tabular-nums">'.e($shp->shipment_date->format('d M Y')).'</span>' : '-')
                ->addColumn('shipment_date_display', fn ($shp) => $shp->shipment_date ? '<span class="ui-tabular-nums">'.e($regionalFormatter->date($shp->shipment_date, 'human')).'</span>' : '-')
                ->addColumn('estimated_arrival', fn ($shp) => $shp->estimated_arrival_date ? '<span class="ui-tabular-nums">'.e($regionalFormatter->date($shp->estimated_arrival_date, 'human')).'</span>' : '-')
                ->addColumn('status_badge', function ($shp) {
                    return StatusHelper::badge(
                        StatusHelper::shipmentBadge($shp->status),
                        StatusHelper::shipmentLabel($shp->status)
                    );
                })
                ->addColumn('action', function ($shp) {
                    $viewUrl = route('supplier.shipments.show', $shp);
                    $editUrl = route('supplier.shipments.edit', $shp);
                    $isOwner = (int) $shp->supplier_id === (int) auth()->id();
                    $secondaryActions = [];

                    if ($isOwner && $shp->status === 'draft') {
                        $primaryAction = '<form action="'.route('supplier.shipments.submit', $shp).'" method="POST" class="draft-submit-form tw-m-0">'
                            .csrf_field()
                            .'<button type="button" class="ui-data-action ui-data-action--primary ui-focus-ring btn-submit-draft" aria-label="'.e(__('purchasing.action_names.submit_draft', ['name' => $shp->shipment_number])).'">'
                            .__('shipments.copy.submit')
                            .'</button></form>';
                        $secondaryActions[] = '<li><a href="'.$viewUrl.'" class="dropdown-item">'.e(__('shipments.copy.view_details')).'</a></li>';
                        $secondaryActions[] = '<li><a href="'.$editUrl.'" class="dropdown-item">'.e(__('shipments.copy.edit_draft')).'</a></li>';
                        $secondaryActions[] = '<li><form action="'.route('supplier.shipments.cancel', $shp).'" method="POST" class="cancel-form">'.csrf_field().'<button type="button" class="dropdown-item text-danger btn-cancel-shipment btn-delete">'.e(__('shipments.copy.cancel_shipment')).'</button></form></li>';
                    } else {
                        $primaryAction = '<a href="'.$viewUrl.'" class="ui-data-action ui-data-action--primary ui-focus-ring" aria-label="'.e(__('purchasing.action_names.view', ['name' => $shp->shipment_number])).'">'.e(__('shipments.copy.details')).'</a>';
                    }

                    if ($secondaryActions === []) {
                        return '<div class="d-inline-flex justify-content-end">'.$primaryAction.'</div>';
                    }

                    return '<div class="d-inline-flex align-items-center justify-content-end gap-1">'.$primaryAction
                        .'<div class="dropdown"><button type="button" class="ui-data-action ui-focus-ring dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" aria-label="'.e(__('purchasing.action_names.more_actions_for', ['name' => $shp->shipment_number])).'">'.e(__('shipments.copy.more')).'</button>'
                        .'<ul class="dropdown-menu dropdown-menu-end">'.implode('', $secondaryActions).'</ul></div></div>';
                })
                ->rawColumns(['shipment_number_display', 'po_references', 'items_count', 'total_qty', 'actual_weight', 'total_weight', 'shipment_date', 'shipment_date_display', 'estimated_arrival', 'status_badge', 'action'])
                ->toJson();
        }

        $shipments = $query->paginate(15)->withQueryString();

        return view('supplier.shipments.index', compact('shipments'));
    }

    /**
     * Show the form for creating a new shipment.
     */
    public function create(Request $request)
    {
        $supplierId = auth()->id();

        // Load active or overdue POs belonging to this supplier
        $purchaseOrders = PurchaseOrder::query()
            ->where('supplier_id', $supplierId)
            ->whereIn('status', ['active', 'overdue', 'waiting_qc'])
            ->with([
                'awards.quotationItem.prItem',
                'quotations.items.prItem',
            ])
            ->latest()
            ->get();

        $poItems = collect();

        foreach ($purchaseOrders as $po) {
            // Determine items from awards (or fallback to quotation items for legacy POs)
            $items = $po->awards->isNotEmpty()
                ? $po->awards->map(fn ($a) => $a->quotationItem)->filter()
                : $po->allQuotationItems()->filter(fn ($i) => $i->isAvailable());

            foreach ($items as $item) {
                $status = $this->shipmentService->getItemDeliveryStatus($po->id, $item->id);

                if (($status['remaining_qty'] ?? $status['remaining']) > 0) {
                    $poItems->push([
                        'po' => $po,
                        'quotation_item' => $item,
                        'pr_item' => $item->prItem,
                        'ordered_qty' => $status['ordered_qty'] ?? (int) $status['ordered'],
                        'allocated_qty' => $status['allocated_qty'] ?? (int) $status['allocated'],
                        'remaining_qty' => $status['remaining_qty'] ?? (int) $status['remaining'],
                        'ordered' => $status['ordered'],
                        'allocated' => $status['allocated'],
                        'remaining' => $status['remaining'],
                    ]);
                }
            }
        }

        $preselectedPoId = $request->query('po_id');

        $shipment = null;

        return view('supplier.shipments.create', compact('purchaseOrders', 'poItems', 'preselectedPoId', 'shipment'));
    }

    /**
     * Show the owner-scoped edit form for a draft shipment.
     */
    public function edit($id)
    {
        $shipment = Shipment::with('items')->findOrFail($id);
        if ((int) $shipment->supplier_id !== (int) auth()->id()) {
            abort(403, __('shipments.copy.you_do_not_have_access_to_this_shipment'));
        }
        if ($shipment->status !== Shipment::STATUS_DRAFT) {
            return redirect()->route('supplier.shipments.show', $shipment)
                ->with('error', __('shipments.copy.only_draft_shipments_can_be_edited'));
        }

        $currentQuantities = $shipment->items->keyBy(fn ($item) => $item->purchase_order_id.':'.$item->quotation_item_id);
        $currentPoIds = $shipment->items->pluck('purchase_order_id')->unique()->all();
        $purchaseOrders = PurchaseOrder::query()
            ->where('supplier_id', auth()->id())
            ->where(function ($query) use ($currentPoIds) {
                $query->whereIn('status', ['active', 'overdue', 'waiting_qc'])
                    ->orWhereIn('id', $currentPoIds);
            })
            ->with(['awards.quotationItem.prItem', 'quotations.items.prItem'])
            ->latest()
            ->get();
        $poItems = collect();

        foreach ($purchaseOrders as $po) {
            $items = $po->awards->isNotEmpty()
                ? $po->awards->map(fn ($award) => $award->quotationItem)->filter()
                : $po->allQuotationItems()->filter(fn ($item) => $item->isAvailable());

            foreach ($items as $item) {
                $status = $this->shipmentService->getItemDeliveryStatus($po->id, $item->id);
                $current = $currentQuantities->get($po->id.':'.$item->id);

                if (($status['remaining_qty'] ?? $status['remaining']) > 0 || $current) {
                    $poItems->push([
                        'po' => $po,
                        'quotation_item' => $item,
                        'pr_item' => $item->prItem,
                        'ordered_qty' => $status['ordered_qty'] ?? (int) $status['ordered'],
                        'allocated_qty' => $status['allocated_qty'] ?? (int) $status['allocated'],
                        'remaining_qty' => $status['remaining_qty'] ?? (int) $status['remaining'],
                        'ordered' => $status['ordered'],
                        'allocated' => $status['allocated'],
                        'remaining' => $status['remaining'],
                        'current_qty' => $current?->shipped_qty,
                        'current_actual_weight_kg' => $current?->actual_weight_kg,
                        'current_quantity' => $current?->shipped_qty,
                    ]);
                }
            }
        }

        $preselectedPoId = null;

        return view('supplier.shipments.create', compact('purchaseOrders', 'poItems', 'preselectedPoId', 'shipment'));
    }

    /**
     * Store a newly created shipment (draft or submitted).
     */
    public function store(Request $request)
    {
        $this->validateShipmentPayload($request, true);

        try {
            $shipment = $this->shipmentService->createDraft(auth()->user(), [
                'shipment_date' => $request->input('shipment_date'),
                'estimated_arrival_date' => $request->input('estimated_arrival_date'),
                'notes' => $request->input('notes'),
                'items' => $request->input('items'),
            ]);

            if ($request->input('action') === 'submit') {
                $shipment = $this->shipmentService->submitShipment($shipment, [
                    'items' => $request->input('items'),
                    'shipment_date' => $request->input('shipment_date'),
                    'estimated_arrival_date' => $request->input('estimated_arrival_date'),
                    'notes' => $request->input('notes'),
                ]);

                return redirect()->route('supplier.shipments.show', $shipment)
                    ->with('success', __('shipments.feedback.submitted', ['shipment' => $shipment->shipment_number]));
            }

            return redirect()->route('supplier.shipments.show', $shipment)
                ->with('success', __('shipments.feedback.created', ['shipment' => $shipment->shipment_number]));
        } catch (\InvalidArgumentException|\DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()->with('error', __('shipments.copy.an_unexpected_error_occurred_while_saving_the_shipment_please_try_again'));
        }
    }

    /**
     * Persist owner-scoped edits to a draft shipment.
     */
    public function update(Request $request, $id)
    {
        $shipment = Shipment::findOrFail($id);
        if ((int) $shipment->supplier_id !== (int) auth()->id()) {
            abort(403, __('shipments.copy.you_do_not_have_access_to_this_shipment'));
        }

        $this->validateShipmentPayload($request, false);

        try {
            $shipment = $this->shipmentService->updateDraft($shipment, auth()->user(), [
                'shipment_date' => $request->input('shipment_date'),
                'estimated_arrival_date' => $request->input('estimated_arrival_date'),
                'notes' => $request->input('notes'),
                'items' => $request->input('items'),
            ]);

            return redirect()->route('supplier.shipments.show', $shipment)
                ->with('success', __('shipments.feedback.updated', ['shipment' => $shipment->shipment_number]));
        } catch (\InvalidArgumentException|\DomainException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        } catch (\Throwable $exception) {
            report($exception);

            return back()->withInput()->with('error', __('shipments.copy.an_unexpected_error_occurred_while_updating_the_shipment_please_try_again'));
        }
    }

    /**
     * Display shipment details.
     */
    public function show($id)
    {
        $shipment = Shipment::query()
            ->with([
                'supplier',
                'items.purchaseOrder',
                'items.quotationItem.prItem',
                'documents.latestAttachment',
                'qcInspections.items',
            ])
            ->findOrFail($id);

        if ((int) $shipment->supplier_id !== (int) auth()->id()) {
            abort(403, __('shipments.copy.you_do_not_have_access_to_this_shipment'));
        }

        return view('supplier.shipments.show', compact('shipment'));
    }

    /**
     * Submit a draft shipment.
     */
    public function submit(Request $request, $id)
    {
        $shipment = Shipment::findOrFail($id);

        if ((int) $shipment->supplier_id !== (int) auth()->id()) {
            abort(403, __('shipments.copy.you_do_not_have_access_to_this_shipment'));
        }

        $validated = $this->validateShipmentPayload($request, false, false);

        try {
            $submitted = $this->shipmentService->submitShipment($shipment, $validated);

            return redirect()->route('supplier.shipments.show', $submitted)
                ->with('success', __('shipments.feedback.submitted', ['shipment' => $submitted->shipment_number]));
        } catch (\InvalidArgumentException|\DomainException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', __('shipments.copy.an_unexpected_error_occurred_while_submitting_the_shipment_please_try_again'));
        }
    }

    /**
     * Cancel a shipment and release allocations.
     */
    public function cancel($id)
    {
        $shipment = Shipment::findOrFail($id);

        if ((int) $shipment->supplier_id !== (int) auth()->id()) {
            abort(403, __('shipments.copy.you_do_not_have_access_to_this_shipment'));
        }

        try {
            $cancelled = $this->shipmentService->cancelShipment($shipment, auth()->user());

            return redirect()->route('supplier.shipments.show', $cancelled)
                ->with('success', __('shipments.feedback.cancelled', ['shipment' => $cancelled->shipment_number]));
        } catch (\InvalidArgumentException|\DomainException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', __('shipments.copy.an_unexpected_error_occurred_while_cancelling_the_shipment_please_try_again'));
        }
    }

    /**
     * Upload document attachment for this shipment.
     */
    public function uploadDocument(Request $request, $id, $document_id)
    {
        $request->validate([
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png,xlsx,doc,docx|max:10240',
            'document_number' => 'nullable|string|max:100',
        ]);

        $shipment = Shipment::findOrFail($id);

        if ((int) $shipment->supplier_id !== (int) auth()->id()) {
            abort(403, __('shipments.copy.you_do_not_have_access_to_this_shipment'));
        }

        $document = ShipmentDocument::where('shipment_id', $shipment->id)->findOrFail($document_id);

        try {
            $this->shipmentService->uploadDocument(
                $document,
                $request->file('file'),
                auth()->user(),
                $request->input('document_number')
            );

            if ($request->expectsJson()) {
                $request->session()->flash('success', __('shipments.copy.document_uploaded_successfully'));

                return response()->json(['redirect' => route('supplier.shipments.show', $shipment)]);
            }

            return back()->with('success', __('shipments.copy.document_uploaded_successfully'));
        } catch (ValidationException $e) {
            throw $e;
        } catch (\InvalidArgumentException|\DomainException|\RuntimeException $e) {
            if ($request->expectsJson()) {
                throw ValidationException::withMessages(['file' => $e->getMessage()]);
            }

            return back()->with('error', __('shipments.errors.upload_with_reason', ['message' => $e->getMessage()]));
        } catch (\Throwable $e) {
            if ($request->expectsJson()) {
                throw $e;
            }
            report($e);

            return back()->with('error', __('shipments.copy.an_unexpected_error_occurred_while_uploading_the_document_please_try_again'));
        }
    }

    private function validateShipmentPayload(Request $request, bool $allowAction, bool $required = true): array
    {
        $this->removeUnselectedRows($request);

        $requiredRule = $required ? 'required' : 'sometimes';
        $rules = [
            'shipment_date' => $requiredRule.'|date',
            'estimated_arrival_date' => $requiredRule.'|date|after_or_equal:shipment_date',
            'notes' => 'nullable|string|max:1000',
            'items' => $requiredRule.'|array|min:1',
            'items.*' => 'required|array',
            'items.*.purchase_order_id' => 'required|integer|exists:purchase_orders,id',
            'items.*.quotation_item_id' => 'required|integer|exists:quotation_items,id',
            // pcs and kg are independent measurements. Neither may be omitted and
            // neither may be satisfied by the legacy `shipped_quantity` alias,
            // which previously let a request supply pcs and inherit it as kg.
            'items.*.shipped_qty' => ['required', 'integer', 'min:1'],
            'items.*.actual_weight_kg' => ['required', 'numeric', 'gt:0', 'decimal:0,4'],
        ];
        if ($allowAction) {
            $rules['action'] = 'nullable|string|in:draft,submit';
        }

        $validator = validator($request->all(), $rules);
        $validator->after(function ($validator) use ($request) {
            $seen = [];
            foreach ((array) $request->input('items', []) as $index => $item) {
                if (! is_array($item) || ! isset($item['purchase_order_id'], $item['quotation_item_id'])) {
                    continue;
                }

                $key = (int) $item['purchase_order_id'].':'.(int) $item['quotation_item_id'];
                if (isset($seen[$key])) {
                    $validator->errors()->add("items.{$index}", __('shipments.copy.duplicate_item_allocation_for_the_same_purchase_order_item_is_not_allowed'));
                }
                $seen[$key] = true;
            }
        });

        return $validator->validate();
    }

    /**
     * The allocation table renders one row per available PO item. Rows with
     * no quantity are intentionally unselected and must not fail the nested
     * required quantity rule (or reach the service layer).
     */
    private function removeUnselectedRows(Request $request): void
    {
        $items = $request->input('items');
        if (! is_array($items)) {
            return;
        }

        $items = array_filter($items, static function ($item): bool {
            if (! is_array($item)) {
                return true;
            }

            $qty = $item['shipped_qty'] ?? $item['shipped_quantity'] ?? null;

            return $qty !== null && trim((string) $qty) !== '';
        });

        $request->merge(['items' => $items]);
    }
}
