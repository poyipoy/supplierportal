<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\ExchangeRate;
use App\Models\PoDocument;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Support\PurchasingNavigation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Yajra\DataTables\Facades\DataTables;

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
            'documents',
            'qcInspections',
            'materialClaims',
        ])
            ->orderBy('created_at', 'desc');

        if ($request->filled('po_number')) {
            $query->where('po_number', 'like', '%' . trim($request->po_number) . '%');
        }

        if ($request->filled('status')) {
            if ($request->status === 'overdue') {
                $query->where(function ($q) {
                    $q->where('status', 'overdue')
                        ->orWhere(function ($q) {
                            $q->where('status', 'active')
                                ->whereNotNull('estimated_arrival')
                                ->whereDate('estimated_arrival', '<', today())
                                ->whereNull('actual_arrival');
                        });
                });
            } else {
                $query->where('status', $request->status);
            }
        }

        if ($request->filled('supplier_id')) {
            $query->whereHas('quotation', fn($q) => $q->where('supplier_id', $request->supplier_id));
        }

        if ($request->ajax()) {
            return DataTables::eloquent($query)
                ->addColumn('po_number_display', fn($po) => $po->po_number)
                ->addColumn('supplier_name', fn($po) => $po->quotation->supplier->name ?? '-')
                ->addColumn('period_name', fn($po) => $po->quotation->purchaseRequirement->period->name ?? '-')
                ->addColumn('total_idr', function ($po) {
                    $rate = $po->quotation->exchange_rate;
                    $totalIdr = 0;
                    foreach ($po->quotation->items as $item) {
                        $totalIdr += $item->amount * ($rate ? $rate->rate_to_idr : 1);
                    }
                    return 'Rp ' . number_format($totalIdr, 0, ',', '.');
                })
                ->addColumn('status_badge', function ($po) {
                    $badgeClass = match(true) {
                        $po->is_overdue => 'bg-danger',
                        $po->status === 'active' => 'bg-primary',
                        $po->status === 'waiting_qc' => 'bg-warning text-dark',
                        $po->status === 'claim_needed' => 'bg-danger',
                        $po->status === 'completed' => 'bg-success',
                        default => 'bg-secondary'
                    };
                    $statusLabel = match(true) {
                        $po->is_overdue => 'Overdue',
                        $po->status === 'active' => 'Active',
                        $po->status === 'waiting_qc' => 'Waiting QC',
                        $po->status === 'claim_needed' => 'Claim Needed',
                        $po->status === 'completed' => 'Completed',
                        default => ucwords(str_replace('_', ' ', $po->status))
                    };
                    return '<span class="badge ' . $badgeClass . ' text-uppercase" style="font-size: 0.7rem;">' . $statusLabel . '</span>';
                })
                ->addColumn('estimated_date', fn($po) => $po->estimated_arrival ? $po->estimated_arrival->format('d M Y') : '-')
                ->addColumn('action', function ($po) {
                    $html = '<div class="d-inline-flex gap-1 justify-content-end flex-wrap">';
                    if ($po->status === 'claim_needed') {
                        $activeClaim = $po->materialClaims->whereIn('status', ['pending', 'responded', 'escalated'])->sortByDesc('created_at')->first();
                        $latestNgInspection = $po->qcInspections->where('status', 'ng')->sortByDesc('inspected_at')->first();
                        if ($activeClaim) {
                            $html .= '<a href="' . PurchasingNavigation::toRoute('purchasing.claims.show', $activeClaim->id) . '" class="btn btn-sm btn-outline-danger"><i class="bi bi-exclamation-octagon me-1"></i> Klaim</a>';
                        } elseif ($latestNgInspection) {
                            $html .= '<a href="' . PurchasingNavigation::toRoute('purchasing.claims.create', $latestNgInspection->id) . '" class="btn btn-sm btn-danger"><i class="bi bi-plus-circle me-1"></i> Buat Klaim</a>';
                        }
                    }
                    $html .= '<a href="' . PurchasingNavigation::toRoute('purchasing.purchase-orders.show', $po->id) . '" class="btn btn-sm btn-outline-info"><i class="bi bi-eye"></i> Detail</a>';
                    $html .= '</div>';
                    return $html;
                })
                ->rawColumns(['status_badge', 'action'])
                ->make(true);
        }

        $suppliers = \App\Models\User::where('role', 'supplier')->get();

        return view('purchasing.po.index', compact('suppliers'));
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
            return redirect()->back()->with('error', 'Quotation ini tidak valid untuk pembuatan PO.');
        }

        if ($quotation->isExpired()) {
            return redirect(PurchasingNavigation::backUrl('purchasing.quotations.index'))
                ->with('error', 'Masa berlaku penawaran sudah kadaluarsa. Minta supplier mengirim penawaran ulang sebelum membuat PO.');
        }

        // Check if PO already exists for this quotation
        if (PurchaseOrder::where('quotation_id', $quotation_id)->exists()) {
            return redirect(PurchasingNavigation::backUrl('purchasing.purchase-orders.index'))
                ->with('error', 'PO sudah pernah dibuat untuk quotation ini.');
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
            return redirect()->back()->with('error', 'Quotation ini tidak valid.');
        }

        if ($quotation->isExpired()) {
            return redirect(PurchasingNavigation::backUrl('purchasing.quotations.index'))
                ->with('error', 'Masa berlaku penawaran sudah kadaluarsa. PO tidak dapat dibuat dari penawaran ini.');
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
                    "Purchase Order {$po->po_number} telah diterbitkan untuk penawaran Anda.",
                    route('supplier.purchase-orders.show', $po->id),
                    'bi-receipt text-primary'
                ));
            }

            $showParameters = [$po->id];
            if (PurchasingNavigation::isSafeUrl($request->input('return_url'))) {
                $showParameters['return_url'] = $request->input('return_url');
            }

            return redirect()->route('purchasing.purchase-orders.show', $showParameters)
                ->with('success', 'Purchase Order ' . $po->po_number . ' berhasil dibuat!');

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withInput()->with('error', 'Gagal membuat PO: ' . $e->getMessage());
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
            'creator',
            'qcInspections.inspector',
            'qcInspections.items.prItem',
            'qcInspections.attachments',
            'materialClaims',
        ])->findOrFail($id);

        $rate = $po->quotation->exchange_rate;

        // Compute document completion
        $completedStatuses = ['received', 'verified', 'done'];
        $completedDocs = $po->documents->filter(function ($doc) use ($completedStatuses) {
            return in_array($doc->status, $completedStatuses);
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

        if (!in_array($po->status, ['active', 'overdue'])) {
            return redirect()->route('purchasing.purchase-orders.show', $po->id)
                ->with('error', 'Material hanya bisa dikonfirmasi tiba untuk PO berstatus Active atau Overdue.');
        }

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
                "Material dari PO {$po->po_number} telah tiba. Silakan lakukan inspeksi QC.",
                route('qc.inspections.create', $po->id),
                'bi-box-seam text-warning'
            ));
        }

        return redirect()->route('purchasing.purchase-orders.show', $po->id)
            ->with('success', 'Material dikonfirmasi tiba. QC akan dinotifikasi untuk inspeksi.');
    }
}
