<?php

namespace App\Http\Controllers\Supplier;

use App\Http\Controllers\Controller;
use App\Models\MaterialClaim;
use App\Models\User;
use App\Notifications\SystemNotification;
use Illuminate\Http\Request;

class ClaimController extends Controller
{
    public function index()
    {
        $claims = MaterialClaim::with(['purchaseOrder.quotation.purchaseRequirement.period', 'inspection'])
            ->where('supplier_id', auth()->id())
            ->orderBy('created_at', 'desc')
            ->get();

        return view('supplier.claims.index', compact('claims'));
    }

    public function show($id)
    {
        $claim = MaterialClaim::with([
            'purchaseOrder',
            'inspection.items.prItem',
            'inspection.attachments'
        ])->findOrFail($id);

        if ($claim->supplier_id !== auth()->id()) {
            abort(403, __('Akses ditolak.'));
        }

        return view('supplier.claims.show', compact('claim'));
    }

    public function respond(Request $request, $id)
    {
        $claim = MaterialClaim::findOrFail($id);

        if ($claim->supplier_id !== auth()->id()) {
            abort(403, __('Akses ditolak.'));
        }

        $request->validate([
            'supplier_response' => 'required|string',
            'attachments.*' => 'nullable|file|mimes:jpg,jpeg,png,pdf,xlsx,doc,docx|max:10240',
        ]);

        $claim->update([
            'supplier_response' => $request->supplier_response,
            'status' => 'responded',
        ]);

        // Upload lampiran respons supplier
        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {
                $path = $file->store('attachments/claims/' . now()->format('Y/m'), 'private');
                $claim->attachments()->create([
                    'file_path' => $path,
                    'file_name' => $file->getClientOriginalName(),
                    'file_type' => $file->getMimeType(),
                    'uploaded_by' => auth()->id(),
                ]);
            }
        }

        // Notify purchasing
        $purchasingUsers = User::where('role', 'purchasing')->get();
        foreach ($purchasingUsers as $pUser) {
            $pUser->notify(new SystemNotification(
                'Respons Klaim Diterima',
                'Supplier telah merespons klaim untuk PO :po_number.',
                route('purchasing.claims.show', $claim->id),
                'bi-reply text-primary',
                [],
                ['po_number' => $claim->purchaseOrder->po_number]
            ));
        }

        return redirect()->route('supplier.claims.show', $claim->id)->with('success', __('Respons berhasil dikirim.'));
    }
}
