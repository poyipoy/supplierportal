<?php

namespace App\Http\Controllers\Supplier;

use App\Http\Controllers\Controller;
use App\Models\MaterialClaim;
use App\Models\User;
use App\Notifications\SystemNotification;
use Illuminate\Http\Request;
use Yajra\DataTables\Facades\DataTables;

class ClaimController extends Controller
{
    public function index(Request $request)
    {
        $query = MaterialClaim::with(['purchaseOrder.quotation.supplier'])
            ->where('supplier_id', auth()->id())
            ->orderBy('created_at', 'desc');

        if ($request->ajax()) {
            return DataTables::eloquent($query)
                ->addColumn('claim_id', fn($c) => '#' . $c->id)
                ->addColumn('po_number', fn($c) => $c->purchaseOrder->po_number ?? '-')
                ->addColumn('created_date', fn($c) => $c->created_at->format('d M Y'))
                ->addColumn('deadline_display', function ($c) {
                    $isOverdue = $c->status === 'pending' && $c->deadline->isPast();
                    $class = $isOverdue ? 'text-danger fw-bold' : '';
                    return '<span class="' . $class . '">' . $c->deadline->format('d M Y') . '</span>';
                })
                ->addColumn('status_badge', function ($c) {
                    $badgeClass = match($c->status) {
                        'pending' => 'bg-warning text-dark',
                        'responded' => 'bg-info',
                        'resolved' => 'bg-success',
                        'escalated' => 'bg-danger',
                        default => 'bg-secondary'
                    };
                    return '<span class="badge ' . $badgeClass . ' text-uppercase">' . ucwords(str_replace('_', ' ', $c->status)) . '</span>';
                })
                ->addColumn('action', function ($c) {
                    $label = $c->status === 'pending' ? 'Beri Respons' : 'Lihat Detail';
                    return '<a href="' . route('supplier.claims.show', $c->id) . '" class="btn btn-sm btn-primary" style="background-color: var(--adasi-blue);">' . $label . '</a>';
                })
                ->rawColumns(['deadline_display', 'status_badge', 'action'])
                ->make(true);
        }

        return view('supplier.claims.index');
    }

    public function show($id)
    {
        $claim = MaterialClaim::with([
            'purchaseOrder',
            'inspection.items.prItem',
            'inspection.attachments'
        ])->findOrFail($id);

        if ($claim->supplier_id !== auth()->id()) {
            abort(403, 'Akses ditolak.');
        }

        return view('supplier.claims.show', compact('claim'));
    }

    public function respond(Request $request, $id)
    {
        $claim = MaterialClaim::findOrFail($id);

        if ($claim->supplier_id !== auth()->id()) {
            abort(403, 'Akses ditolak.');
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
                'Supplier telah merespons klaim untuk PO ' . $claim->purchaseOrder->po_number . '.',
                route('purchasing.claims.show', $claim->id),
                'bi-reply text-primary'
            ));
        }

        return redirect()->route('supplier.claims.show', $claim->id)->with('success', 'Respons berhasil dikirim.');
    }
}
