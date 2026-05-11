<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\ExchangeRate;
use App\Models\PoDocument;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseOrderController extends Controller
{
    /**
     * Daftar semua PO.
     */
    public function index(Request $request)
    {
        $query = PurchaseOrder::with([
            'quotation.supplier',
            'quotation.purchaseRequirement.period',
            'quotation.exchange_rate',
            'quotation.items.prItem',
            'documents'
        ])
            ->orderBy('created_at', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('supplier_id')) {
            $query->whereHas('quotation', fn($q) => $q->where('supplier_id', $request->supplier_id));
        }

        $purchaseOrders = $query->get();

        // Compute overdue flag dynamically
        foreach ($purchaseOrders as $po) {
            $po->is_overdue = ($po->status === 'active' && $po->estimated_arrival && $po->estimated_arrival->isPast() && !$po->actual_arrival);
        }

        $suppliers = \App\Models\User::where('role', 'supplier')->get();

        return view('purchasing.po.index', compact('purchaseOrders', 'suppliers'));
    }

    /**
     * Form buat PO dari quotation terpilih.
     */
    public function create($quotation_id)
    {
        $quotation = Quotation::with([
            'supplier',
            'items.prItem',
            'purchaseRequirement.period',
            'exchange_rate'
        ])->findOrFail($quotation_id);

        if ($quotation->status !== 'submitted') {
            return redirect()->back()->with('error', __('Quotation ini tidak valid untuk pembuatan PO.'));
        }

        // Check if PO already exists for this quotation
        if (PurchaseOrder::where('quotation_id', $quotation_id)->exists()) {
            return redirect()->route('purchasing.purchase-orders.index')
                ->with('error', __('PO sudah pernah dibuat untuk quotation ini.'));
        }

        $rate = $quotation->exchange_rate;

        return view('purchasing.po.create', compact('quotation', 'rate'));
    }

    /**
     * Simpan PO baru — atomic transaction.
     */
    public function store(Request $request)
    {
        $request->validate([
            'quotation_id' => 'required|exists:quotations,id',
            'estimated_arrival' => 'required|date',
            'notes' => 'nullable|string',
        ]);

        $quotation = Quotation::with('purchaseRequirement')->findOrFail($request->quotation_id);

        if ($quotation->status !== 'submitted') {
            return redirect()->back()->with('error', __('Quotation ini tidak valid.'));
        }

        try {
            DB::beginTransaction();

            // 1. Create PO
            $po = PurchaseOrder::create([
                'quotation_id' => $quotation->id,
                'po_number' => PurchaseOrder::generatePoNumber(),
                'status' => 'active',
                'created_by' => auth()->id(),
                'estimated_arrival' => $request->estimated_arrival,
                'notes' => $request->notes,
            ]);

            // 2. Create 4 default po_documents
            $docTypes = ['invoice', 'bl', 'packing_list', 'form_e'];
            foreach ($docTypes as $type) {
                PoDocument::create([
                    'po_id' => $po->id,
                    'doc_type' => $type,
                    'status' => 'pending',
                ]);
            }

            // 3. Accept this quotation
            $quotation->update(['status' => 'accepted']);

            // 4. Reject all other quotations for the same PR
            Quotation::where('pr_id', $quotation->pr_id)
                ->where('id', '!=', $quotation->id)
                ->where('status', 'submitted')
                ->update(['status' => 'rejected']);

            // 5. Mark the PR as completed
            $quotation->purchaseRequirement->update(['status' => 'completed']);

            DB::commit();

            // Notify supplier: PO diterbitkan
            $supplierUser = $quotation->supplier;
            if ($supplierUser) {
                $supplierUser->notify(new \App\Notifications\SystemNotification(
                    'PO Baru Diterbitkan',
                    'Purchase Order :po_number telah diterbitkan untuk penawaran Anda.',
                    route('supplier.purchase-orders.show', $po->id),
                    'bi-receipt text-primary',
                    [],
                    ['po_number' => $po->po_number]
                ));
            }

            return redirect()->route('purchasing.purchase-orders.show', $po->id)
                ->with('success', __('Purchase Order :number berhasil dibuat!', ['number' => $po->po_number]));

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withInput()->with('error', __('Gagal membuat PO: :message', ['message' => $e->getMessage()]));
        }
    }

    /**
     * Detail PO: Info, Documents, Timeline.
     */
    public function show($id)
    {
        $po = PurchaseOrder::with([
            'quotation.supplier',
            'quotation.items.prItem',
            'quotation.purchaseRequirement.period',
            'quotation.exchange_rate',
            'documents',
            'creator'
        ])->findOrFail($id);

        $rate = $po->quotation->exchange_rate;

        // Compute document completion
        $completedDocs = $po->documents->filter(function ($doc) {
            return in_array($doc->status, ['verified', 'done']);
        })->count();
        $totalDocs = $po->documents->count();
        $allDocsComplete = ($completedDocs === $totalDocs && $totalDocs > 0);

        return view('purchasing.po.show', compact('po', 'rate', 'completedDocs', 'totalDocs', 'allDocsComplete'));
    }

    /**
     * Konfirmasi material tiba.
     */
    public function confirmArrival(Request $request, $id)
    {
        $po = PurchaseOrder::findOrFail($id);

        $po->update([
            'actual_arrival' => now()->toDateString(),
            'status' => 'waiting_qc',
        ]);

        // Notify all QC users: material tiba
        $qcUsers = \App\Models\User::where('role', 'qc')->get();
        foreach ($qcUsers as $qcUser) {
            /** @var \App\Models\User $qcUser */
            $qcUser->notify(new \App\Notifications\SystemNotification(
                'Material Tiba - Siap Inspeksi',
                'Material dari PO :po_number telah tiba. Silakan lakukan inspeksi QC.',
                route('qc.inspections.create', $po->id),
                'bi-box-seam text-warning',
                [],
                ['po_number' => $po->po_number]
            ));
        }

        return redirect()->route('purchasing.purchase-orders.show', $po->id)
            ->with('success', __('Material dikonfirmasi tiba. QC akan dinotifikasi untuk inspeksi.'));
    }
}
