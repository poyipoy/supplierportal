<?php

namespace App\Http\Controllers\Supplier;

use App\Http\Controllers\Controller;
use App\Models\MaterialClaim;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Support\StatusHelper;
use Illuminate\Http\Request;
use Yajra\DataTables\Facades\DataTables;

class ClaimController extends Controller
{
    public function index(Request $request)
    {
        $query = MaterialClaim::with(['purchaseOrder.supplier'])
            ->where('supplier_id', auth()->id())
            ->orderBy('created_at', 'desc');

        if ($request->ajax()) {
            return DataTables::eloquent($query)
                ->addColumn('claim_id', fn($c) => '#' . $c->id)
                ->addColumn('po_number', fn($c) => $c->purchaseOrder->po_number ?? '-')
                ->addColumn('created_date', fn($c) => $c->created_at->format('d M Y'))
                ->addColumn('deadline_display', function ($c) {
                    $meta = StatusHelper::claimDeadlineMeta($c->deadline, $c->status);
                    $date = $c->deadline ? $c->deadline->format('d M Y') : '-';

                    return '<div class="d-flex flex-column align-items-start gap-1">'
                        . '<span>' . e($date) . '</span>'
                        . StatusHelper::badgeWithTooltip($meta['class'], $meta['label'], $meta['description'])
                        . '</div>';
                })
                ->addColumn('status_badge', function ($c) {
                    return StatusHelper::badge(
                        StatusHelper::claimBadge($c->status),
                        StatusHelper::claimLabel($c->status)
                    );
                })
                ->addColumn('action', function ($c) {
                    $label = $c->status === 'pending' ? 'Give Response' : 'View Details';
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
            abort(403, 'Access denied.');
        }

        return view('supplier.claims.show', compact('claim'));
    }

    public function respond(Request $request, $id)
    {
        $claim = MaterialClaim::findOrFail($id);

        if ($claim->supplier_id !== auth()->id()) {
            abort(403, 'Access denied.');
        }

        $request->validate([
            'supplier_response' => 'required|string',
            'attachments.*' => 'nullable|file|mimes:jpg,jpeg,png,pdf,xlsx,doc,docx|max:10240',
        ]);

        $claim->update([
            'supplier_response' => $request->supplier_response,
            'status' => 'responded',
        ]);

        // Upload supplier response attachments
        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {
                if (! $file || ! $file->isValid()) {
                    continue;
                }
                
                // Use getPathname() to avoid getRealPath() returning false on Windows.
                $fileName = $file->hashName();
                $path = 'attachments/claims/' . now()->format('Y/m') . '/' . $fileName;
                
                $stream = fopen($file->getPathname(), 'r');
                if ($stream) {
                    \Illuminate\Support\Facades\Storage::disk('private')->put($path, $stream);
                    fclose($stream);
                    
                    $claim->attachments()->create([
                        'file_path' => $path,
                        'file_name' => $file->getClientOriginalName(),
                        'file_type' => $file->getMimeType(),
                        'uploaded_by' => auth()->id(),
                    ]);
                }
            }
        }

        // Notify purchasing
        $purchasingUsers = User::where('role', 'purchasing')->get();
        foreach ($purchasingUsers as $pUser) {
            /** @var \App\Models\User $pUser */
            $pUser->notify(new SystemNotification(
                'Claim Response Accepted',
                'The supplier has responded to the claim for PO ' . $claim->purchaseOrder->po_number . '.',
                route('purchasing.claims.show', $claim->id),
                'bi-reply text-primary'
            ));
        }

        return redirect()->route('supplier.claims.show', $claim->id)->with('success', 'Response successfully sent.');
    }
}
