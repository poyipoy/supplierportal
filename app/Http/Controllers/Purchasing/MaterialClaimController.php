<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\MaterialClaim;
use App\Models\PurchaseOrder;
use App\Models\QcInspection;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Support\PurchasingNavigation;
use App\Support\StatusHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Yajra\DataTables\Facades\DataTables;

class MaterialClaimController extends Controller
{
    public function index()
    {
        // Counts for tab badges
        $actionCount = PurchaseOrder::where('status', 'claim_needed')
            ->whereDoesntHave('materialClaims', function($q) {
                $q->whereIn('status', ['pending', 'responded', 'escalated']);
            })->count();

        $historyCount = MaterialClaim::count();

        return view('purchasing.claims.index', compact('actionCount', 'historyCount'));
    }

    public function dataActionNeeded(Request $request)
    {
        $query = PurchaseOrder::with([
            'supplier',
            'qcInspections' => fn($query) => $query
                ->orderByDesc('inspected_at')
                ->orderByDesc('id'),
        ])
            ->where('status', 'claim_needed')
            ->whereDoesntHave('materialClaims', function($q) {
                $q->whereIn('status', ['pending', 'responded', 'escalated']);
            });

        return DataTables::eloquent($query)
            ->addColumn('po_number_display', fn($po) => $po->po_number)
            ->addColumn('supplier_name', fn($po) => $po->supplier->name ?? '-')
            ->addColumn('inspection_date', fn($po) => $po->qcInspections->first()?->inspected_at?->format('d M Y') ?? '-')
            ->addColumn('status_badge', fn($po) => StatusHelper::badge(
                StatusHelper::poBadge($po->status),
                StatusHelper::poLabel($po->status)
            ))
            ->addColumn('action', function ($po) {
                $lastInspection = $po->qcInspections->first();
                if ($lastInspection) {
                    return '<a href="' . PurchasingNavigation::toRoute('purchasing.claims.create', $lastInspection->id) . '" class="btn btn-sm btn-danger"><i class="bi bi-exclamation-octagon me-1"></i> Create Claim</a>';
                }
                return '-';
            })
            ->rawColumns(['status_badge', 'action'])
            ->make(true);
    }

    public function dataHistory(Request $request)
    {
        $query = MaterialClaim::with(['purchaseOrder.supplier', 'inspection'])
            ->orderBy('created_at', 'desc');

        return DataTables::eloquent($query)
            ->addColumn('claim_id', fn($c) => '#' . $c->id)
            ->addColumn('po_number', fn($c) => $c->purchaseOrder->po_number ?? '-')
            ->addColumn('supplier_name', fn($c) => $c->purchaseOrder->supplier->name ?? '-')
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
            ->addColumn('action', fn($c) => '<a href="' . PurchasingNavigation::toRoute('purchasing.claims.show', $c->id) . '" class="btn btn-sm btn-outline-primary">Details</a>')
            ->rawColumns(['deadline_display', 'status_badge', 'action'])
            ->make(true);
    }

    public function create($inspection_id)
    {
        $inspection = QcInspection::with(['purchaseOrder.supplier', 'items.prItem', 'attachments'])
            ->findOrFail($inspection_id);

        if ($inspection->status !== 'ng') {
            return redirect(PurchasingNavigation::backUrl('purchasing.claims.index'))->with('error', 'This inspection is not NG and does not require a claim.');
        }

        // Ensure there is no active claim.
        if (MaterialClaim::where('inspection_id', $inspection_id)->whereIn('status', ['pending', 'responded', 'escalated'])->exists()) {
            return redirect(PurchasingNavigation::backUrl('purchasing.claims.index'))->with('error', 'A claim has already been created for this inspection.');
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

        [$claim, $inspection] = DB::transaction(function () use ($request) {
            $inspection = QcInspection::whereKey($request->inspection_id)
                ->lockForUpdate()
                ->firstOrFail();
            $inspection->load('purchaseOrder.supplier');

            if ($inspection->status !== 'ng') {
                throw ValidationException::withMessages([
                    'inspection_id' => 'Only NG inspections can be claimed.',
                ]);
            }

            if (! $inspection->purchaseOrder) {
                throw ValidationException::withMessages([
                    'inspection_id' => 'Inspection PO was not found.',
                ]);
            }

            if (MaterialClaim::where('inspection_id', $inspection->id)
                ->whereIn('status', ['pending', 'responded', 'escalated'])
                ->exists()) {
                throw ValidationException::withMessages([
                    'inspection_id' => 'An active claim already exists for this inspection.',
                ]);
            }

            $claim = MaterialClaim::create([
                'inspection_id' => $inspection->id,
                'po_id' => $inspection->po_id,
                'submitted_by' => auth()->id(),
                'supplier_id' => $inspection->purchaseOrder->supplier_id,
                'status' => 'pending',
                'description' => $request->description,
                'resolution_expected' => $request->resolution_expected,
                'deadline' => $request->deadline,
            ]);

            return [$claim, $inspection];
        });

        // Notify the supplier.
        $supplierUser = $inspection->purchaseOrder->supplier;
        if ($supplierUser) {
            $supplierUser->notify(new SystemNotification(
                'New Material Claim',
                'You received a new claim for PO ' . $inspection->purchaseOrder->po_number . '. Please respond before ' . \Carbon\Carbon::parse($claim->deadline)->format('d M Y') . '.',
                route('supplier.claims.show', $claim->id),
                'bi-exclamation-octagon text-danger'
            ));
        }

        $showParameters = [$claim->id];
        if (PurchasingNavigation::isSafeUrl($request->input('return_url'))) {
            $showParameters['return_url'] = $request->input('return_url');
        }

        return redirect()->route('purchasing.claims.show', $showParameters)->with('success', 'Claim successfully sent to the supplier.');
    }

    public function show($id)
    {
        $claim = MaterialClaim::with([
            'purchaseOrder.supplier',
            'inspection.items.prItem',
            'inspection.attachments',
            'submitter'
        ])->findOrFail($id);

        return view('purchasing.claims.show', compact('claim'));
    }

    public function resolve($id)
    {
        $claim = MaterialClaim::with('purchaseOrder')->findOrFail($id);
        
        if ($claim->status !== 'responded') {
            return back()->with('error', 'Only responded claims can be resolved.');
        }

        $claim->update(['status' => 'resolved']);

        if ($claim->purchaseOrder) {
            $hasActiveClaims = MaterialClaim::where('po_id', $claim->po_id)
                ->where('id', '!=', $claim->id)
                ->whereIn('status', ['pending', 'responded', 'escalated'])
                ->exists();

            if (! $hasActiveClaims) {
                $claim->purchaseOrder->update(['status' => 'completed']);
            }
        }

        // Notify supplier: claim completed
        /** @var User $supplierUser */
        $supplierUser = User::find($claim->supplier_id);
        if ($supplierUser) {
            $supplierUser->notify(new SystemNotification(
                'Material Claim Completed',
                'Claim for PO ' . ($claim->purchaseOrder->po_number ?? '-') . ' has been marked completed by Purchasing.',
                route('supplier.claims.show', $claim->id),
                'bi-check-circle text-success'
            ));
        }

        return back()->with('success', 'Claim has been marked completed.');
    }
}
