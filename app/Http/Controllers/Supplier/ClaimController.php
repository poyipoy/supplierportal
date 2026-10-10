<?php

namespace App\Http\Controllers\Supplier;

use App\Http\Controllers\Controller;
use App\Models\MaterialClaim;
use App\Models\PurchaseOrder;
use App\Models\QcInspection;
use App\Models\User;
use App\Services\FileSecurity\FileInspectionService;
use App\Services\NotificationService;
use App\Services\RegionalDisplayFormatter;
use App\Support\BusinessTime;
use App\Support\NotificationCategory;
use App\Support\StatusHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Yajra\DataTables\Facades\DataTables;

class ClaimController extends Controller
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function index(Request $request, RegionalDisplayFormatter $regionalFormatter)
    {
        $query = MaterialClaim::with(['purchaseOrder.supplier'])
            ->where('supplier_id', auth()->id())
            ->orderBy('created_at', 'desc');

        if ($request->ajax()) {
            return DataTables::eloquent($query)
                ->addColumn('claim_id', fn ($c) => $c->claim_number)
                ->addColumn('po_number', fn ($c) => $c->purchaseOrder->po_number ?? '-')
                ->addColumn('created_date', fn ($c) => BusinessTime::format($c->created_at, 'd M Y', false))
                ->addColumn('deadline_display', function ($c) use ($regionalFormatter) {
                    $meta = StatusHelper::claimDeadlineMeta($c->deadline, $c->status);
                    $date = $c->deadline ? $regionalFormatter->date($c->deadline, 'human') : '-';

                    return '<div class="d-flex flex-column align-items-start gap-1">'
                        .'<span>'.e($date).'</span>'
                        .StatusHelper::badgeWithTooltip($meta['class'], $meta['label'], $meta['description'])
                        .'</div>';
                })
                ->addColumn('status_badge', function ($c) {
                    return StatusHelper::badge(
                        StatusHelper::claimBadge($c->status),
                        StatusHelper::claimLabel($c->status)
                    );
                })
                ->addColumn('action', function ($c) {
                    $label = $c->status === 'pending' ? __('claims.copy.give_response') : __('claims.copy.view_details');

                    return '<a href="'.route('supplier.claims.show', $c).'" class="ui-data-action ui-data-action--primary ui-focus-ring">'.$label.'</a>';
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
            'inspection.attachments',
        ])->findOrFail($id);

        if ($claim->supplier_id !== auth()->id()) {
            abort(403, __('claims.copy.access_denied'));
        }

        return view('supplier.claims.show', compact('claim'));
    }

    public function respond(Request $request, $id)
    {
        $claimReference = MaterialClaim::findOrFail($id);

        if ((int) $claimReference->supplier_id !== (int) auth()->id()) {
            abort(403, __('claims.copy.access_denied'));
        }

        $request->validate([
            'supplier_response' => 'required|string',
            'attachments.*' => 'nullable|file|mimes:jpg,jpeg,png,pdf,xlsx,doc,docx|max:10240',
        ]);

        $staged = [];
        try {
            foreach ($request->file('attachments', []) as $file) {
                $path = 'attachments/claims/'.now()->format('Y/m').'/'.$file->hashName(); // biz-time:ignore storage path
                $staged[] = app(FileInspectionService::class)->storeUpload($file, 'claim', $path, 'attachments');
            }
            $claim = DB::transaction(function () use ($claimReference, $request, $staged) {
                $po = $claimReference->po_id
                    ? PurchaseOrder::whereKey($claimReference->po_id)->lockForUpdate()->first()
                    : null;
                QcInspection::withTrashed()->whereKey($claimReference->inspection_id)->lockForUpdate()->firstOrFail();
                $claim = MaterialClaim::whereKey($claimReference->id)->lockForUpdate()->firstOrFail();

                if ((int) $claim->supplier_id !== (int) auth()->id()) {
                    abort(403, __('claims.copy.access_denied'));
                }

                if (! in_array($claim->status, ['pending', 'escalated'], true)) {
                    throw new \RuntimeException(__('claims.copy.this_claim_can_no_longer_accept_a_supplier_response'));
                }

                $claim->update([
                    'supplier_response' => $request->supplier_response,
                    'status' => 'responded',
                ]);
                foreach ($staged as $stored) {
                    $claim->attachments()->create([
                        'file_path' => $stored['file_path'],
                        'file_name' => $stored['file_name'],
                        'file_type' => $stored['file_type'],
                        'file_inspection_id' => $stored['file_inspection_id'],
                        'uploaded_by' => auth()->id(),
                    ]);
                }
                $po?->reconcileOperationalStatus();

                return $claim->fresh('purchaseOrder');
            });
        } catch (\RuntimeException $exception) {
            foreach ($staged as $stored) {
                Storage::disk('private')->delete($stored['file_path']);
            }
            if ($request->expectsJson()) {
                throw ValidationException::withMessages(['attachments' => $exception->getMessage()]);
            }

            return back()->withInput()->with('error', $exception->getMessage());
        } catch (\Throwable $exception) {
            foreach ($staged as $stored) {
                Storage::disk('private')->delete($stored['file_path']);
            }
            throw $exception;
        }

        // Notify purchasing
        $purchasingUsers = User::where('role', 'purchasing')->where('is_active', true)->get();
        $this->notifications->send(
            $purchasingUsers,
            'claim.responded',
            "claim.responded:{$claim->id}",
            'claims.copy.claim_response_accepted',
            'claims.notify.responded_body',
            route('purchasing.claims.show', $claim, absolute: false),
            'reply text-primary',
            [
                'category' => NotificationCategory::OTHER,
                'claim_id' => $claim->id,
                'inspection_id' => $claim->inspection_id,
                'po_id' => $claim->po_id,
                'po_number' => $claim->purchaseOrder->po_number,
            ],
            ['po' => $claim->purchaseOrder->po_number],
        );

        if ($request->expectsJson()) {
            $request->session()->flash('success', __('claims.copy.response_successfully_sent'));

            return response()->json(['redirect' => route('supplier.claims.show', $claim)]);
        }

        return redirect()->route('supplier.claims.show', $claim)->with('success', __('claims.copy.response_successfully_sent'));
    }
}
