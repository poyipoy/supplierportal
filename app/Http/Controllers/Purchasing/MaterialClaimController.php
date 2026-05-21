<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\MaterialClaim;
use App\Models\PurchaseOrder;
use App\Models\QcInspection;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Support\PurchasingNavigation;
use Illuminate\Http\Request;

class MaterialClaimController extends Controller
{
    public function index()
    {
        // PO with claim_needed that DO NOT have an active claim yet
        $actionNeeded = PurchaseOrder::with(['quotation.supplier', 'qcInspections'])
            ->where('status', 'claim_needed')
            ->whereDoesntHave('materialClaims', function($q) {
                $q->whereIn('status', ['pending', 'responded', 'escalated']);
            })
            ->get();

        // Riwayat Klaim
        $claims = MaterialClaim::with(['purchaseOrder.quotation.supplier', 'inspection'])
            ->orderBy('created_at', 'desc')
            ->get();

        return view('purchasing.claims.index', compact('actionNeeded', 'claims'));
    }

    public function create($inspection_id)
    {
        $inspection = QcInspection::with(['purchaseOrder.quotation.supplier', 'items.prItem', 'attachments'])
            ->findOrFail($inspection_id);

        if ($inspection->status !== 'ng') {
            return redirect(PurchasingNavigation::backUrl('purchasing.claims.index'))->with('error', 'Inspeksi ini bukan NG, tidak perlu diklaim.');
        }

        // Pastikan belum ada klaim aktif
        if (MaterialClaim::where('inspection_id', $inspection_id)->whereIn('status', ['pending', 'responded', 'escalated'])->exists()) {
            return redirect(PurchasingNavigation::backUrl('purchasing.claims.index'))->with('error', 'Klaim sudah dibuat untuk inspeksi ini.');
        }

        return view('purchasing.claims.create', compact('inspection'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'inspection_id' => 'required|exists:qc_inspections,id',
            'description' => 'required|string',
            'resolution_expected' => 'required|string',
            'deadline' => 'required|date|after:today',
        ]);

        $inspection = QcInspection::with('purchaseOrder.quotation.supplier')->findOrFail($request->inspection_id);

        $claim = MaterialClaim::create([
            'inspection_id' => $inspection->id,
            'po_id' => $inspection->po_id,
            'submitted_by' => auth()->id(),
            'supplier_id' => $inspection->purchaseOrder->quotation->supplier_id,
            'status' => 'pending',
            'description' => $request->description,
            'resolution_expected' => $request->resolution_expected,
            'deadline' => $request->deadline,
        ]);

        // Beri notif ke Supplier
        $supplierUser = $inspection->purchaseOrder->quotation->supplier;
        if ($supplierUser) {
            $supplierUser->notify(new SystemNotification(
                'Klaim Material Baru',
                'Anda menerima klaim baru untuk PO ' . $inspection->purchaseOrder->po_number . '. Harap direspons sebelum ' . \Carbon\Carbon::parse($claim->deadline)->format('d M Y') . '.',
                route('supplier.claims.show', $claim->id),
                'bi-exclamation-octagon text-danger'
            ));
        }

        $showParameters = [$claim->id];
        if (PurchasingNavigation::isSafeUrl($request->input('return_url'))) {
            $showParameters['return_url'] = $request->input('return_url');
        }

        return redirect()->route('purchasing.claims.show', $showParameters)->with('success', 'Klaim berhasil dikirim ke supplier.');
    }

    public function show($id)
    {
        $claim = MaterialClaim::with([
            'purchaseOrder.quotation.supplier',
            'inspection.items.prItem',
            'inspection.attachments',
            'submitter'
        ])->findOrFail($id);

        return view('purchasing.claims.show', compact('claim'));
    }

    public function resolve($id)
    {
        $claim = MaterialClaim::findOrFail($id);
        
        if ($claim->status !== 'responded') {
            return back()->with('error', 'Hanya klaim yang sudah direspons yang dapat diselesaikan.');
        }

        $claim->update(['status' => 'resolved']);

        // Update status PO menjadi completed karena masalah klaim sudah selesai
        if ($claim->purchaseOrder) {
            $claim->purchaseOrder->update(['status' => 'completed']);
        }

        // Notify supplier: klaim selesai
        /** @var User $supplierUser */
        $supplierUser = User::find($claim->supplier_id);
        if ($supplierUser) {
            $supplierUser->notify(new SystemNotification(
                'Klaim Material Selesai',
                'Klaim untuk PO ' . $claim->purchaseOrder->po_number . ' telah ditandai selesai oleh Purchasing.',
                route('supplier.claims.show', $claim->id),
                'bi-check-circle text-success'
            ));
        }

        return back()->with('success', 'Klaim telah ditandai selesai.');
    }
}
