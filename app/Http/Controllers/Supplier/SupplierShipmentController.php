<?php

namespace App\Http\Controllers\Supplier;

use App\Exceptions\BusinessRuleViolation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Supplier\BatchUploadShipmentDocumentsRequest;
use App\Http\Requests\Supplier\StoreSupplierShipmentRequest;
use App\Http\Requests\Supplier\UpdateShipmentPreshipmentRequest;
use App\Http\Requests\Supplier\UpdateSupplierShipmentRequest;
use App\Models\Attachment;
use App\Models\PurchaseOrder;
use App\Models\Shipment;
use App\Models\ShipmentDocument;
use App\Models\SupplierPreshipmentProfile;
use App\Services\Procurement\PreshipmentInspectionService;
use App\Services\RegionalDisplayFormatter;
use App\Services\ShipmentService;
use App\Support\NumberFormat;
use App\Support\Procurement\ProcurementFeatures;
use App\Support\StatusHelper;
use App\Support\Ui\RowAction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Yajra\DataTables\Facades\DataTables;

class SupplierShipmentController extends Controller
{
    /** Q6B: MTC files ('mtc' inspection profile, private disk). */
    private const MTC_RULES = [
        'mtc_files' => 'nullable|array|max:10',
        'mtc_files.*' => 'file|mimes:pdf,jpg,jpeg,png|max:5120',
    ];

    public function __construct(
        protected ShipmentService $shipmentService,
        protected ?PreshipmentInspectionService $preshipmentService = null,
    ) {
        $this->preshipmentService ??= app(PreshipmentInspectionService::class);
    }

    /**
     * Display a listing of shipments for the authenticated supplier.
     */
    public function index(Request $request, RegionalDisplayFormatter $regionalFormatter)
    {
        Gate::authorize('viewAny', Shipment::class);
        $supplierId = auth()->id();

        $query = Shipment::query()
            ->where('supplier_id', $supplierId)
            ->with(['items.purchaseOrder', 'documents.latestAttachment'])
            ->withCount('mtcAttachments')
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
                    ->orWhere('supplier_reference', 'like', "%{$search}%")
                    ->orWhere('invoice_number', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%")
                    ->orWhereHas('items.purchaseOrder', fn ($po) => $po->where('po_number', 'like', "%{$search}%"));
            });
        }

        if ($request->ajax()) {
            return DataTables::eloquent($query)
                ->filter(fn ($query) => null)
                ->addColumn('shipment_number_display', function ($shp) {
                    $url = route('supplier.shipments.show', $shp);

                    return '<a href="'.e($url).'" class="fw-bold text-primary text-decoration-none">'.e($shp->shipment_number).'</a>';
                })
                ->addColumn('po_references', function ($shp) {
                    $pos = $shp->purchaseOrders();
                    if ($pos->isEmpty()) {
                        return '<span class="tw:text-on-surface-variant">-</span>';
                    }

                    return $pos->map(fn ($po) => '<span class="ui-status-chip ui-status-chip--neutral me-1">'.e($po->po_number).'</span>')->implode('');
                })
                ->addColumn('supplier_reference_display', fn ($shp) => e($shp->supplier_reference ?: '-'))
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
                ->addColumn('missing_documents', fn ($shp) => StatusHelper::shipmentMissingDocumentsHtml($shp))
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
                        $invoiceDoc = $shp->documents->firstWhere('doc_type', ShipmentDocument::DOC_TYPE_INVOICE);
                        $invoiceMissing = ProcurementFeatures::requireCommercialInvoiceForShipment()
                            && ($invoiceDoc === null || $invoiceDoc->latestAttachment === null);
                        $primaryAction = '<form action="'.route('supplier.shipments.submit', $shp).'" method="POST" class="draft-submit-form tw:m-0">'
                            .csrf_field()
                            .'<button type="button" class="ui-data-action ui-data-action--primary ui-focus-ring btn-submit-draft" data-invoice-missing="'.($invoiceMissing ? '1' : '0').'" aria-label="'.e(__('purchasing.action_names.submit_draft', ['name' => $shp->shipment_number])).'">'
                            .__('shipments.copy.submit')
                            .'</button></form>';
                        $secondaryActions[] = '<li><a href="'.$viewUrl.'" class="dropdown-item">'.e(__('shipments.copy.view_details')).'</a></li>';
                        $secondaryActions[] = '<li><a href="'.$editUrl.'" class="dropdown-item">'.e(__('shipments.copy.edit_draft')).'</a></li>';
                        $secondaryActions[] = '<li><form action="'.route('supplier.shipments.cancel', $shp).'" method="POST" class="cancel-form">'.csrf_field().'<button type="button" class="dropdown-item text-danger btn-cancel-shipment btn-delete">'.e(__('shipments.copy.cancel_shipment')).'</button></form></li>';
                    } else {
                        $primaryAction = RowAction::detail($viewUrl, $shp->shipment_number);
                    }

                    if ($secondaryActions === []) {
                        return '<div class="d-inline-flex justify-content-end">'.$primaryAction.'</div>';
                    }

                    return '<div class="d-inline-flex align-items-center justify-content-end gap-1">'.$primaryAction
                        .'<div class="dropdown"><button type="button" class="ui-data-action ui-focus-ring dropdown-toggle" data-bs-toggle="dropdown" data-bs-popper-config=\'{"strategy":"fixed"}\' aria-expanded="false" aria-label="'.e(__('purchasing.action_names.more_actions_for', ['name' => $shp->shipment_number])).'">'.e(__('shipments.copy.more')).'</button>'
                        .'<ul class="dropdown-menu dropdown-menu-end">'.implode('', $secondaryActions).'</ul></div></div>';
                })
                ->rawColumns(['shipment_number_display', 'po_references', 'supplier_reference_display', 'items_count', 'total_qty', 'actual_weight', 'total_weight', 'shipment_date', 'shipment_date_display', 'estimated_arrival', 'missing_documents', 'status_badge', 'action'])
                ->only(['shipment_number_display', 'po_references', 'supplier_reference_display', 'items_count', 'total_qty', 'actual_weight', 'total_weight', 'shipment_date', 'shipment_date_display', 'estimated_arrival', 'missing_documents', 'status_badge', 'action'])
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
        Gate::authorize('create', Shipment::class);
        $supplierId = auth()->id();

        // Load active or overdue POs belonging to this supplier
        $purchaseOrders = PurchaseOrder::query()
            ->where('supplier_id', $supplierId)
            ->whereIn('status', ['active', 'overdue', 'waiting_qc'])
            ->with([
                'awards.quotationItem.prItem',
                'awards.latestProgressUpdate',
                'quotations.items.prItem',
            ])
            ->latest()
            ->get();

        $poItems = collect();
        // Items still owed by the supplier but not Ready to Ship are not offered; the
        // count lets the view say why they are missing. ShipmentService enforces the same rule.
        $notReadyCount = 0;
        $readyGate = ProcurementFeatures::requireReadyToShipForShipment();
        $salesContractGate = ProcurementFeatures::requireSalesContractBeforeShipment();

        foreach ($purchaseOrders as $po) {
            $salesContractBlocked = $salesContractGate && $po->requiresSalesContractHandshake() && (! $po->hasSignedSalesContract() || ! $po->isAcknowledged());
            // Determine items from awards (or fallback to quotation items for legacy POs)
            $items = $po->awards->isNotEmpty()
                ? $po->awards->map(fn ($a) => $a->quotationItem)->filter()
                : $po->allQuotationItems()->filter(fn ($i) => $i->isAvailable());
            $awardsByItem = $po->awards->keyBy('quotation_item_id');

            foreach ($items as $item) {
                $status = $this->shipmentService->getItemDeliveryStatus($po->id, $item->id);

                if (($status['remaining_qty'] ?? $status['remaining']) > 0) {
                    $award = $awardsByItem->get($item->id);
                    if ($salesContractBlocked || ($readyGate && $award && ! $award->isReadyToShip())) {
                        $notReadyCount++;

                        continue;
                    }

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
        $preshipment = $this->preshipmentPrefill(null);

        return view('supplier.shipments.create', compact('purchaseOrders', 'poItems', 'preselectedPoId', 'shipment', 'notReadyCount', 'preshipment'));
    }

    /**
     * Show the owner-scoped edit form for a draft shipment.
     */
    public function edit($id)
    {
        $shipment = Shipment::with(['items', 'preshipmentInspection'])->findOrFail($id);
        Gate::authorize('view', $shipment);
        if ($shipment->status !== Shipment::STATUS_DRAFT) {
            return redirect()->route('supplier.shipments.show', $shipment)
                ->with('error', __('shipments.copy.only_draft_shipments_can_be_edited'));
        }

        Gate::authorize('update', $shipment);

        $currentQuantities = $shipment->items->keyBy(fn ($item) => $item->purchase_order_id.':'.$item->quotation_item_id);
        $currentPoIds = $shipment->items->pluck('purchase_order_id')->unique()->all();
        $purchaseOrders = PurchaseOrder::query()
            ->where('supplier_id', auth()->id())
            ->where(function ($query) use ($currentPoIds) {
                $query->whereIn('status', ['active', 'overdue', 'waiting_qc'])
                    ->orWhereIn('id', $currentPoIds);
            })
            ->with(['awards.quotationItem.prItem', 'awards.latestProgressUpdate', 'quotations.items.prItem'])
            ->latest()
            ->get();
        $poItems = collect();
        $notReadyCount = 0;
        $readyGate = ProcurementFeatures::requireReadyToShipForShipment();
        $salesContractGate = ProcurementFeatures::requireSalesContractBeforeShipment();

        foreach ($purchaseOrders as $po) {
            $salesContractBlocked = $salesContractGate && $po->requiresSalesContractHandshake() && (! $po->hasSignedSalesContract() || ! $po->isAcknowledged());
            $items = $po->awards->isNotEmpty()
                ? $po->awards->map(fn ($award) => $award->quotationItem)->filter()
                : $po->allQuotationItems()->filter(fn ($item) => $item->isAvailable());
            $awardsByItem = $po->awards->keyBy('quotation_item_id');

            foreach ($items as $item) {
                $status = $this->shipmentService->getItemDeliveryStatus($po->id, $item->id);
                $current = $currentQuantities->get($po->id.':'.$item->id);

                if (($status['remaining_qty'] ?? $status['remaining']) > 0 || $current) {
                    $award = $awardsByItem->get($item->id);
                    $notReady = $salesContractBlocked || ($readyGate && $award && ! $award->isReadyToShip());

                    // A line already in this draft stays visible (flagged) so the draft remains
                    // editable; saving or submitting it is refused until it is Ready to Ship again.
                    if ($notReady && ! $current) {
                        $notReadyCount++;

                        continue;
                    }

                    $poItems->push([
                        'not_ready' => $notReady,
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
        $preshipment = $this->preshipmentPrefill($shipment);

        return view('supplier.shipments.create', compact('purchaseOrders', 'poItems', 'preselectedPoId', 'shipment', 'notReadyCount', 'preshipment'));
    }

    /**
     * Store a newly created shipment (draft or submitted).
     */
    public function store(Request $request)
    {
        Gate::authorize('create', Shipment::class);
        StoreSupplierShipmentRequest::validateFor($request, true);
        $request->validate(StoreSupplierShipmentRequest::MTC_RULES);

        try {
            $submit = $request->input('action') === 'submit';
            $mtcFiles = $request->file('mtc_files');
            $result = $this->shipmentService->createShipmentFromForm(
                auth()->user(),
                $this->shipmentFormData($request),
                $submit,
                $request->file('commercial_invoice_file'),
                is_array($mtcFiles) ? $mtcFiles : [],
            );
            $shipment = $result['shipment'];

            if ($result['commercial_invoice_required']) {
                return $this->shipmentFormResponse(
                    $request,
                    route('supplier.shipments.show', $shipment),
                    (string) $result['commercial_invoice_message'],
                    'error'
                );
            }

            if ($submit) {
                return $this->shipmentFormResponse(
                    $request,
                    route('supplier.shipments.show', $shipment),
                    __('shipments.feedback.submitted', ['shipment' => $shipment->shipment_number])
                );
            }

            return $this->shipmentFormResponse(
                $request,
                route('supplier.shipments.show', $shipment),
                __('shipments.feedback.created', ['shipment' => $shipment->shipment_number])
            );
        } catch (\InvalidArgumentException|\DomainException $e) {
            if ($request->expectsJson()) {
                throw ValidationException::withMessages(['shipment' => $e->getMessage()]);
            }

            return back()->withInput()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            if ($request->expectsJson()) {
                throw ValidationException::withMessages([
                    'shipment' => __('shipments.copy.an_unexpected_error_occurred_while_saving_the_shipment_please_try_again'),
                ]);
            }

            return back()->withInput()->with('error', __('shipments.copy.an_unexpected_error_occurred_while_saving_the_shipment_please_try_again'));
        }
    }

    /**
     * Persist owner-scoped edits to a draft shipment.
     */
    public function update(Request $request, $id)
    {
        $shipment = Shipment::findOrFail($id);
        Gate::authorize('update', $shipment);

        UpdateSupplierShipmentRequest::validateFor($request, false);
        $request->validate(UpdateSupplierShipmentRequest::MTC_RULES);

        try {
            $shipment = $this->shipmentService->updateDraft($shipment, auth()->user(), $this->shipmentFormData($request));
            $this->attachFormCommercialInvoice($request, $shipment);
            $this->attachFormMtc($request, $shipment);

            return $this->shipmentFormResponse(
                $request,
                route('supplier.shipments.show', $shipment),
                __('shipments.feedback.updated', ['shipment' => $shipment->shipment_number])
            );
        } catch (\InvalidArgumentException|\DomainException $exception) {
            if ($request->expectsJson()) {
                throw ValidationException::withMessages(['shipment' => $exception->getMessage()]);
            }

            return back()->withInput()->with('error', $exception->getMessage());
        } catch (\Throwable $exception) {
            report($exception);

            if ($request->expectsJson()) {
                throw ValidationException::withMessages([
                    'shipment' => __('shipments.copy.an_unexpected_error_occurred_while_updating_the_shipment_please_try_again'),
                ]);
            }

            return back()->withInput()->with('error', __('shipments.copy.an_unexpected_error_occurred_while_updating_the_shipment_please_try_again'));
        }
    }

    /**
     * Update Pre-Shipment Inspection details directly on a Draft or Submitted shipment.
     */
    public function updatePreshipment(UpdateShipmentPreshipmentRequest $request, $id)
    {
        $shipment = Shipment::findOrFail($id);
        Gate::authorize('updatePreshipment', $shipment);

        $preshipmentInput = $this->extractPreshipmentPayload($request);

        try {
            $this->shipmentService->updatePreshipment($shipment, $request->user(), $preshipmentInput);

            return $this->shipmentFormResponse(
                $request,
                route('supplier.shipments.show', $shipment),
                __('shipments.preshipment.updated')
            );
        } catch (\InvalidArgumentException|\DomainException $exception) {
            if ($request->expectsJson()) {
                throw ValidationException::withMessages(['preshipment' => $exception->getMessage()]);
            }

            return back()->withInput()->with('error', $exception->getMessage());
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
                'documents.attachments',
                'preshipmentInspection',
                'qcInspections.items',
                'mtcAttachments.fileInspection',
                'mtcAttachments.uploader',
            ])
            ->findOrFail($id);

        Gate::authorize('view', $shipment);

        $preshipment = in_array($shipment->status, [Shipment::STATUS_DRAFT, Shipment::STATUS_SUBMITTED], true)
            ? $this->preshipmentPrefill($shipment)
            : null;

        return view('supplier.shipments.show', compact('shipment', 'preshipment'));
    }

    /**
     * Submit a draft shipment.
     */
    public function submit(Request $request, $id)
    {
        $shipment = Shipment::findOrFail($id);

        Gate::authorize('submit', $shipment);

        $validated = UpdateSupplierShipmentRequest::validateFor($request, false, false);

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

        Gate::authorize('cancel', $shipment);

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
     * Mission 05 (Q6B): append MTC files (Draft or Submitted only).
     */
    public function storeMtc(Request $request, $id)
    {
        $shipment = Shipment::findOrFail($id);
        Gate::authorize('uploadMtc', $shipment);
        $request->validate(self::MTC_RULES + ['mtc_files' => 'required|array|min:1|max:10']);

        try {
            $this->shipmentService->uploadMtc($shipment, $request->file('mtc_files'), auth()->user());
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['mtc' => $exception->getMessage()]);
        }

        return $this->mtcResponse($request, $shipment, __('shipments.mtc.uploaded'));
    }

    /**
     * Mission 05 (Q6B): remove one MTC file (Draft only).
     */
    public function destroyMtc(Request $request, $id, Attachment $attachment)
    {
        $shipment = Shipment::findOrFail($id);
        Gate::authorize('deleteMtc', $shipment);

        try {
            $this->shipmentService->removeMtc($shipment, $attachment, auth()->user());
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['mtc' => $exception->getMessage()]);
        }

        return $this->mtcResponse($request, $shipment, __('shipments.mtc.removed'));
    }

    /** MTC chosen on the create/edit form is stored before any in-request submit. */
    private function attachFormMtc(Request $request, Shipment $shipment): void
    {
        $files = $request->file('mtc_files');
        if (is_array($files) && $files !== []) {
            $this->shipmentService->uploadMtc($shipment, $files, auth()->user());
        }
    }

    /** E5: Commercial Invoice file chosen on the create/edit form is stored before any in-request submit. */
    private function attachFormCommercialInvoice(Request $request, Shipment $shipment): void
    {
        $file = $request->file('commercial_invoice_file');
        if ($file === null) {
            return;
        }

        $invoiceDoc = $shipment->documents()
            ->where('doc_type', ShipmentDocument::DOC_TYPE_INVOICE)
            ->first();

        if ($invoiceDoc !== null) {
            $this->shipmentService->uploadDocument(
                $invoiceDoc,
                $file,
                auth()->user(),
                $request->input('invoice_number')
            );
        }
    }

    /**
     * Validated create/edit payload for ShipmentService (E6, E9, E11 included).
     *
     * @return array<string, mixed>
     */
    private function shipmentFormData(Request $request): array
    {
        return [
            'shipment_date' => $request->input('shipment_date'),
            'estimated_arrival_date' => $request->input('estimated_arrival_date'),
            ...($request->has('invoice_number') ? ['invoice_number' => $request->input('invoice_number')] : []),
            'supplier_reference' => $request->input('supplier_reference'),
            'notes' => $request->input('notes'),
            'items' => $request->input('items'),
            'preshipment' => $this->extractPreshipmentPayload($request),
        ];
    }

    /**
     * Allow-list PSI fields and master-save control flags to prevent mass assignment.
     *
     * @return array<string, mixed>
     */
    private function extractPreshipmentPayload(Request $request): array
    {
        $allowedKeys = [
            ...SupplierPreshipmentProfile::FIELDS,
            'same_as_exporter',
            'goods_location',
            'save_exporter_default',
            'location_action',
            'set_location_default',
        ];

        return array_intersect_key(
            (array) $request->input('preshipment', []),
            array_flip($allowedKeys)
        );
    }

    /**
     * E9/PSI: values, saved locations, countries, and port suggestions scoped to auth supplier.
     *
     * @return array<string, mixed>
     */
    private function preshipmentPrefill(?Shipment $shipment): array
    {
        return $this->preshipmentService->prefillFor(auth()->user(), $shipment);
    }

    private function shipmentFormResponse(Request $request, string $redirectUrl, string $message, string $flashKey = 'success')
    {
        if ($request->expectsJson()) {
            $request->session()->flash($flashKey, $message);

            return response()->json(['redirect' => $redirectUrl]);
        }

        return redirect()->to($redirectUrl)->with($flashKey, $message);
    }

    private function mtcResponse(Request $request, Shipment $shipment, string $message)
    {
        if ($request->expectsJson()) {
            $request->session()->flash('success', $message);

            return response()->json(['redirect' => route('supplier.shipments.show', $shipment)]);
        }

        return back()->with('success', $message);
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

        Gate::authorize('uploadDocument', $shipment);

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
        } catch (\InvalidArgumentException|\DomainException|BusinessRuleViolation $e) {
            if ($request->expectsJson()) {
                throw ValidationException::withMessages(['file' => $e->getMessage()]);
            }

            return back()->with('error', __('shipments.errors.upload_with_reason', ['message' => $e->getMessage()]));
        } catch (\Throwable $e) {
            report($e);

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => __('shipments.copy.an_unexpected_error_occurred_while_uploading_the_document_please_try_again'),
                ], 500);
            }

            return back()->with('error', __('shipments.copy.an_unexpected_error_occurred_while_uploading_the_document_please_try_again'));
        }
    }

    /**
     * Batch upload multiple document attachments for this shipment.
     */
    public function uploadDocumentsBatch(BatchUploadShipmentDocumentsRequest $request, $id)
    {
        $shipment = Shipment::findOrFail($id);

        Gate::authorize('uploadDocument', $shipment);

        try {
            $attachments = $this->shipmentService->uploadDocumentsBatch(
                $shipment,
                $request->validated('documents'),
                $request->user()
            );

            if ($request->expectsJson()) {
                $request->session()->flash('success', __('shipments.copy.batch_upload_success'));

                return response()->json([
                    'success' => true,
                    'count' => count($attachments),
                    'redirect' => route('supplier.shipments.show', $shipment),
                ]);
            }

            return back()->with('success', __('shipments.copy.batch_upload_success'));
        } catch (ValidationException $e) {
            throw $e;
        } catch (\InvalidArgumentException|\DomainException|BusinessRuleViolation $e) {
            if ($request->expectsJson()) {
                throw ValidationException::withMessages(['documents' => $e->getMessage()]);
            }

            return back()->with('error', __('shipments.errors.upload_with_reason', ['message' => $e->getMessage()]));
        } catch (\Throwable $e) {
            report($e);

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => __('shipments.copy.an_unexpected_error_occurred_while_uploading_the_document_please_try_again'),
                ], 500);
            }

            return back()->with('error', __('shipments.copy.an_unexpected_error_occurred_while_uploading_the_document_please_try_again'));
        }
    }
}
