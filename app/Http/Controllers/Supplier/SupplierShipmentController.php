<?php

namespace App\Http\Controllers\Supplier;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\Shipment;
use App\Models\ShipmentDocument;
use App\Services\ShipmentService;
use Illuminate\Http\Request;

class SupplierShipmentController extends Controller
{
    public function __construct(
        protected ShipmentService $shipmentService
    ) {}

    /**
     * Display a listing of shipments for the authenticated supplier.
     */
    public function index(Request $request)
    {
        $supplierId = auth()->id();

        $query = Shipment::query()
            ->where('supplier_id', $supplierId)
            ->with(['items.purchaseOrder', 'documents.latestAttachment'])
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
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

                if ($status['remaining'] > 0) {
                    $poItems->push([
                        'po' => $po,
                        'quotation_item' => $item,
                        'pr_item' => $item->prItem,
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
            abort(403, 'You do not have access to this Shipment.');
        }
        if ($shipment->status !== Shipment::STATUS_DRAFT) {
            return redirect()->route('supplier.shipments.show', $shipment)
                ->with('error', 'Only draft shipments can be edited.');
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

                if ($status['remaining'] > 0 || $current) {
                    $poItems->push([
                        'po' => $po,
                        'quotation_item' => $item,
                        'pr_item' => $item->prItem,
                        'ordered' => $status['ordered'],
                        'allocated' => $status['allocated'],
                        'remaining' => $status['remaining'],
                        'current_quantity' => $current?->shipped_quantity,
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
                    ->with('success', "Shipment {$shipment->shipment_number} submitted successfully.");
            }

            return redirect()->route('supplier.shipments.show', $shipment)
                ->with('success', "Draft shipment {$shipment->shipment_number} created successfully.");
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    /**
     * Persist owner-scoped edits to a draft shipment.
     */
    public function update(Request $request, $id)
    {
        $shipment = Shipment::findOrFail($id);
        if ((int) $shipment->supplier_id !== (int) auth()->id()) {
            abort(403, 'You do not have access to this Shipment.');
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
                ->with('success', "Draft shipment {$shipment->shipment_number} updated successfully.");
        } catch (\Throwable $exception) {
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
                'qcInspections.items',
            ])
            ->findOrFail($id);

        if ((int) $shipment->supplier_id !== (int) auth()->id()) {
            abort(403, 'You do not have access to this Shipment.');
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
            abort(403, 'You do not have access to this Shipment.');
        }

        $validated = $this->validateShipmentPayload($request, false, false);

        try {
            $submitted = $this->shipmentService->submitShipment($shipment, $validated);

            return redirect()->route('supplier.shipments.show', $submitted)
                ->with('success', "Shipment {$submitted->shipment_number} submitted successfully.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Cancel a shipment and release allocations.
     */
    public function cancel($id)
    {
        $shipment = Shipment::findOrFail($id);

        if ((int) $shipment->supplier_id !== (int) auth()->id()) {
            abort(403, 'You do not have access to this Shipment.');
        }

        try {
            $cancelled = $this->shipmentService->cancelShipment($shipment, auth()->user());

            return redirect()->route('supplier.shipments.show', $cancelled)
                ->with('success', "Shipment {$cancelled->shipment_number} cancelled and reservations released.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
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
            abort(403, 'You do not have access to this Shipment.');
        }

        $document = ShipmentDocument::where('shipment_id', $shipment->id)->findOrFail($document_id);

        try {
            $this->shipmentService->uploadDocument(
                $document,
                $request->file('file'),
                auth()->user(),
                $request->input('document_number')
            );

            return back()->with('success', 'Document uploaded successfully.');
        } catch (\Throwable $e) {
            return back()->with('error', 'Failed to upload document: '.$e->getMessage());
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
            'items.*.shipped_quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,4'],
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
                    $validator->errors()->add("items.{$index}", 'Duplicate item allocation for the same Purchase Order item is not allowed.');
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

            $quantity = $item['shipped_quantity'] ?? null;

            return $quantity !== null && trim((string) $quantity) !== '';
        });

        $request->merge(['items' => $items]);
    }
}
