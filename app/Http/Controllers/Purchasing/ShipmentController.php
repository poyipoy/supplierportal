<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\PoDocument;
use App\Models\Shipment;
use App\Models\ShipmentDocument;
use App\Models\User;
use App\Services\ShipmentService;
use App\Support\NumberFormat;
use App\Support\StatusHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Yajra\DataTables\Facades\DataTables;

class ShipmentController extends Controller
{
    public function __construct(
        protected ShipmentService $shipmentService
    ) {}

    /**
     * Display a listing of all shipments.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in(Shipment::STATUSES)],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        $query = Shipment::query()
            ->with(['supplier', 'items.purchaseOrder', 'documents.latestAttachment'])
            ->latest('shipment_date');

        if (($validated['status'] ?? null) !== null && $validated['status'] !== '') {
            $query->where('status', $validated['status']);
        }

        if ($request->filled('supplier_id')) {
            $supplier = $this->resolveSupplierFilter($request->query('supplier_id'));
            $query->where('supplier_id', $supplier->getKey());
        }

        if ($request->filled('date_from')) {
            $query->whereDate('shipment_date', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('shipment_date', '<=', $request->date_to);
        }

        if ($request->filled('shipment_number')) {
            $query->where('shipment_number', 'like', '%'.trim($request->shipment_number).'%');
        }

        if ($search = trim((string) $request->input('search.value', $request->input('search')))) {
            $query->where(function ($q) use ($search) {
                $q->where('shipment_number', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%")
                    ->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('items.purchaseOrder', fn ($po) => $po->where('po_number', 'like', "%{$search}%"));
            });
        }

        if ($request->ajax()) {
            return DataTables::eloquent($query)
                ->addColumn('shipment_number_display', function ($shp) {
                    $url = route('purchasing.shipments.show', $shp);

                    return '<a href="'.e($url).'" class="fw-bold text-primary text-decoration-none">'.e($shp->shipment_number).'</a>';
                })
                ->addColumn('supplier_name', fn ($shp) => e($shp->supplier->company_name ?? $shp->supplier->name ?? '-'))
                ->addColumn('consolidated_pos', function ($shp) {
                    $pos = $shp->purchaseOrders();
                    if ($pos->isEmpty()) {
                        return '<span class="tw-text-outline">-</span>';
                    }

                    return $pos->map(fn ($po) => '<span class="ui-status-chip ui-status-chip--neutral me-1">'.e($po->po_number).'</span>')->implode('');
                })
                ->addColumn('items_count', fn ($shp) => '<span class="ui-tabular-nums">'.$shp->items->count().'</span>')
                ->addColumn('total_qty', function ($shp) {
                    $total = (int) $shp->items->sum('shipped_qty');

                    return '<span class="fw-bold text-primary ui-tabular-nums">'.number_format($total).' pcs</span>';
                })
                ->addColumn('actual_weight', function ($shp) {
                    $total = (float) $shp->items->sum('actual_weight_kg');

                    return '<span class="ui-tabular-nums">'.NumberFormat::maxDecimals($total).' Kg</span>';
                })
                ->addColumn('total_weight', function ($shp) {
                    $total = (float) $shp->items->sum('actual_weight_kg');

                    return '<span class="fw-bold text-primary ui-tabular-nums">'.NumberFormat::maxDecimals($total).' Kg</span>';
                })
                ->addColumn('shipment_date', fn ($shp) => $shp->shipment_date ? '<span class="ui-tabular-nums">'.$shp->shipment_date->format('d M Y').'</span>' : '-')
                ->addColumn('estimated_arrival', fn ($shp) => $shp->estimated_arrival_date ? '<span class="ui-tabular-nums">'.$shp->estimated_arrival_date->format('d M Y').'</span>' : '-')
                ->addColumn('actual_arrival', function ($shp) {
                    if ($shp->actual_arrival_date) {
                        return '<span class="ui-tabular-nums text-success fw-semibold">'.$shp->actual_arrival_date->format('d M Y').'</span>';
                    }

                    return '<span class="tw-text-outline">-</span>';
                })
                ->addColumn('status_badge', function ($shp) {
                    return StatusHelper::badge(
                        StatusHelper::shipmentBadge($shp->status),
                        StatusHelper::shipmentLabel($shp->status)
                    );
                })
                ->addColumn('action', function ($shp) {
                    $url = route('purchasing.shipments.show', $shp);

                    return '<a href="'.e($url).'" class="ui-data-action ui-data-action--primary">Details</a>';
                })
                ->rawColumns(['shipment_number_display', 'consolidated_pos', 'items_count', 'total_qty', 'actual_weight', 'total_weight', 'shipment_date', 'estimated_arrival', 'actual_arrival', 'status_badge', 'action'])
                ->toJson();
        }

        $shipments = $query->paginate(15)->withQueryString();
        $suppliers = User::importEligible()
            ->orWhereIn('id', DB::table('purchase_orders')->distinct()->pluck('supplier_id'))
            ->orderBy('name')->get();

        return view('purchasing.shipments.index', compact('shipments', 'suppliers'));
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

    /**
     * Display shipment details.
     */
    public function show($id)
    {
        $shipment = Shipment::query()
            ->with([
                'supplier',
                'items.purchaseOrder.creator',
                'items.quotationItem.prItem.purchaseRequisition',
                'documents.latestAttachment',
                'qcInspections.inspector',
                'qcInspections.items.prItem',
            ])
            ->findOrFail($id);

        return view('purchasing.shipments.show', compact('shipment'));
    }

    /**
     * Confirm physical arrival of a shipment.
     */
    public function confirmArrival(Request $request, $id)
    {
        $validated = $request->validate([
            'actual_arrival_date' => 'nullable|date|before_or_equal:today',
        ]);
        $shipment = Shipment::findOrFail($id);

        try {
            $arrived = $this->shipmentService->confirmArrival($shipment, auth()->user(), [
                'actual_arrival_date' => $validated['actual_arrival_date'] ?? now()->toDateString(),
            ]);

            return redirect()->route('purchasing.shipments.show', $arrived)
                ->with('success', "Shipment {$arrived->shipment_number} arrival confirmed. QC team notified for inspection.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Update status of a shipment document.
     */
    public function updateDocumentStatus(Request $request, $id, $document_id)
    {
        $request->validate([
            'status' => 'required|string|in:'.implode(',', ShipmentDocument::STATUSES),
        ]);

        $shipment = Shipment::findOrFail($id);
        $document = ShipmentDocument::where('shipment_id', $shipment->id)->findOrFail($document_id);

        $document->update([
            'status' => $request->input('status'),
            'notes' => $request->input('notes', $document->notes),
        ]);

        PoDocument::syncFromShipmentDocument($document);

        return back()->with('success', "Document status updated to {$document->status}.");
    }
}
