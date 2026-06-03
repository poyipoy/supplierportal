<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\ExchangeRate;
use App\Models\PoDocument;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Support\PurchasingNavigation;
use App\Support\StatusHelper;
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
            'supplier',
            'quotations.purchaseRequirement.period',
            'quotations.exchange_rate',
            'quotations.items.prItem',
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
            $query->where('supplier_id', $request->supplier_id);
        }

        if ($request->ajax()) {
            return DataTables::eloquent($query)
                ->addColumn('po_number_display', fn($po) => $po->po_number)
                ->addColumn('supplier_name', fn($po) => $po->supplier->name ?? '-')
                ->addColumn('period_name', function ($po) {
                    $periods = $po->quotations->map(fn($q) => $q->purchaseRequirement?->period?->name)->filter()->unique();
                    return $periods->count() > 1
                        ? $periods->first() . ' +' . ($periods->count() - 1)
                        : ($periods->first() ?? '-');
                })
                ->addColumn('total_idr', function ($po) {
                    $totalIdr = 0;
                    foreach ($po->quotations as $quotation) {
                        $rate = $quotation->exchange_rate;
                        foreach ($quotation->items as $item) {
                            $totalIdr += $item->amount * ($rate ? $rate->rate_to_idr : 1);
                        }
                    }
                    return 'Rp ' . number_format($totalIdr, 0, ',', '.');
                })
                ->addColumn('status_badge', function ($po) {
                    return StatusHelper::badge(
                        StatusHelper::poBadge($po->status, $po->is_overdue),
                        StatusHelper::poLabel($po->status, $po->is_overdue)
                    );
                })
                ->addColumn('estimated_date', function ($po) {
                    $meta = StatusHelper::poArrivalMeta(
                        $po->estimated_arrival,
                        $po->is_overdue,
                        $po->status,
                        $po->actual_arrival
                    );
                    $date = $po->estimated_arrival ? $po->estimated_arrival->format('d M Y') : '-';

                    return '<div class="d-flex flex-column align-items-start gap-1">'
                        . '<span>' . e($date) . '</span>'
                        . StatusHelper::badgeWithTooltip($meta['class'], $meta['label'], $meta['description'])
                        . '</div>';
                })
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
                ->rawColumns(['status_badge', 'estimated_date', 'action'])
                ->make(true);
        }

        $suppliers = \App\Models\User::where('role', 'supplier')->get();

        return view('purchasing.po.index', compact('suppliers'));
    }

    

    /**
     * Form buat PO dari quotation terpilih.
     * Sekarang mendukung konsolidasi: menampilkan quotation lain dari supplier & currency yang sama.
     */
    public function create($quotation_id)
    {
        $quotation = Quotation::with([
            'supplier',
            'items.prItem',
            'purchaseRequirement.period',
            'exchange_rate'
        ])->findOrFail($quotation_id);

        if (! in_array($quotation->status, ['submitted', 'accepted'], true)) {
            return redirect()->back()->with('error', 'Quotation ini tidak valid untuk pembuatan PO.');
        }

        if ($quotation->isExpired()) {
            return redirect(PurchasingNavigation::backUrl('purchasing.quotations.index'))
                ->with('error', 'Masa berlaku penawaran sudah kadaluarsa. Minta supplier mengirim penawaran ulang sebelum membuat PO.');
        }

        // Check if this quotation is already in a PO
        if ($quotation->purchaseOrders()->exists()) {
            return redirect(PurchasingNavigation::backUrl('purchasing.purchase-orders.index'))
                ->with('error', 'PO sudah pernah dibuat untuk quotation ini.');
        }

        $rate = $quotation->exchange_rate;

        // Cari quotation lain dari supplier & currency yang sama yang bisa digabungkan
        $otherQuotations = Quotation::with(['items.prItem', 'purchaseRequirement.period', 'exchange_rate'])
            ->where('supplier_id', $quotation->supplier_id)
            ->where('currency', $quotation->currency)
            ->whereIn('status', ['submitted', 'accepted'])
            ->where('id', '!=', $quotation->id)
            ->whereDoesntHave('purchaseOrders') // belum punya PO
            ->where(function ($q) {
                $q->whereNull('validity_period')
                    ->orWhereDate('validity_period', '>=', today());
            })
            ->get();

        return view('purchasing.po.create', compact('quotation', 'rate', 'otherQuotations'));
    }

    /**
     * Simpan PO baru — atomic transaction.
     * Mendukung multiple quotation_ids untuk konsolidasi.
     */
    public function store(Request $request)
    {
        $request->validate([
            'quotation_ids' => 'required|array|min:1',
            'quotation_ids.*' => 'required|exists:quotations,id',
            'estimated_arrival' => 'required|date',
            'notes' => 'nullable|string',
        ]);

        // Load semua quotation yang dipilih
        /** @var \Illuminate\Database\Eloquent\Collection<\App\Models\Quotation> $quotations */
        $quotations = Quotation::with(['purchaseRequirement', 'exchange_rate'])
            ->whereIn('id', $request->quotation_ids)
            ->get();

        // Validasi: semua harus submitted
        foreach ($quotations as $q) {
            /** @var \App\Models\Quotation $q */
            if (! in_array($q->status, ['submitted', 'accepted'], true)) {
                return redirect()->back()->with('error', "Quotation #{$q->id} tidak valid (status: {$q->status}).");
            }
            if ($q->isExpired()) {
                return redirect()->back()->with('error', "Quotation #{$q->id} sudah kadaluarsa.");
            }
            if ($q->purchaseOrders()->exists()) {
                return redirect()->back()->with('error', "Quotation #{$q->id} sudah memiliki PO.");
            }
        }

        // Validasi: semua harus dari supplier yang sama
        $supplierIds = $quotations->pluck('supplier_id')->unique();
        if ($supplierIds->count() !== 1) {
            return back()->with('error', 'Semua quotation harus dari supplier yang sama.');
        }

        // Validasi: semua harus currency yang sama
        $currencies = $quotations->pluck('currency')->unique();
        if ($currencies->count() !== 1) {
            return back()->with('error', 'Semua quotation harus menggunakan mata uang yang sama.');
        }

        $supplierId = $supplierIds->first();
        $currency = $currencies->first();

        // Ambil kurs terbaru sebagai fallback
        $latestRate = ExchangeRate::where('currency', $currency)
            ->orderBy('valid_from', 'desc')
            ->first();

        try {
            DB::beginTransaction();

            // 1. Create PO
            $po = PurchaseOrder::create([
                'supplier_id' => $supplierId,
                'currency' => $currency,
                'exchange_rate_id' => $latestRate?->id,
                'po_number' => PurchaseOrder::generatePoNumber(),
                'status' => 'active',
                'created_by' => auth()->id(),
                'estimated_arrival' => $request->estimated_arrival,
                'notes' => $request->notes,
            ]);

            // 2. Attach all quotations to PO via pivot
            $po->quotations()->attach($quotations->pluck('id'));

            // 3. Create 4 default po_documents
            $docTypes = ['invoice', 'bl', 'packing_list', 'form_e'];
            foreach ($docTypes as $type) {
                PoDocument::create([
                    'po_id' => $po->id,
                    'doc_type' => $type,
                    'status' => 'pending',
                ]);
            }

            // 4. Accept all selected quotations
            foreach ($quotations as $q) {
                /** @var \App\Models\Quotation $q */
                $q->update(['status' => 'accepted']);

                // 5. Reject all other quotations for the same PR
                Quotation::where('pr_id', $q->pr_id)
                    ->where('id', '!=', $q->id)
                    ->whereIn('status', ['submitted', 'accepted'])
                    ->update(['status' => 'rejected']);

                // 6. Mark the PR as completed
                $q->purchaseRequirement->update(['status' => 'completed']);
            }

            DB::commit();

            // Notify supplier: PO diterbitkan
            $supplierUser = $quotations->first()->supplier;
            if ($supplierUser) {
                $prCount = $quotations->count();
                $prLabel = $prCount > 1 ? " (menggabungkan {$prCount} PR)" : '';
                $supplierUser->notify(new \App\Notifications\SystemNotification(
                    'PO Baru Diterbitkan',
                    "Purchase Order {$po->po_number} telah diterbitkan untuk penawaran Anda{$prLabel}.",
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
            'supplier',
            'quotations.supplier',
            'quotations.items.prItem',
            'quotations.purchaseRequirement.period',
            'quotations.exchange_rate',
            'documents',
            'creator',
            'qcInspections.inspector',
            'qcInspections.items.prItem',
            'qcInspections.attachments',
            'materialClaims',
        ])->findOrFail($id);

        // Collect rates per quotation for display
        $quotationRates = $po->quotations->mapWithKeys(function ($q) {
            return [$q->id => $q->exchange_rate];
        });

        // Compute document completion
        $completedStatuses = ['received', 'verified', 'done'];
        $completedDocs = $po->documents->filter(function ($doc) use ($completedStatuses) {
            return in_array($doc->status, $completedStatuses);
        })->count();
        $totalDocs = max($po->documents->count(), 4);
        $allDocsComplete = ($completedDocs >= 4);
        $docProgress = StatusHelper::documentProgressMeta($completedDocs, $totalDocs);

        return view('purchasing.po.show', compact('po', 'quotationRates', 'completedDocs', 'totalDocs', 'allDocsComplete', 'docProgress'));
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
