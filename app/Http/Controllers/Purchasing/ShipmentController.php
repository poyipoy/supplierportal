<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\Shipment;
use App\Models\ShipmentDocument;
use App\Models\User;
use App\Services\ShipmentService;
use Illuminate\Http\Request;

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
        $query = Shipment::query()
            ->with(['supplier', 'items.purchaseOrder', 'documents.latestAttachment'])
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->query('supplier_id'));
        }

        $shipments = $query->paginate(15)->withQueryString();
        $suppliers = User::where('role', 'supplier')->orderBy('name')->get();

        return view('purchasing.shipments.index', compact('shipments', 'suppliers'));
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

        return back()->with('success', "Document status updated to {$document->status}.");
    }
}
