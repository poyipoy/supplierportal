<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\PurchaseRequirement;
use App\Models\PrItem;
use App\Models\Period;
use App\Support\PurchasingNavigation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Yajra\DataTables\Facades\DataTables;

class PurchaseRequirementController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        if ($request->ajax()) {
            $query = PurchaseRequirement::with(['period', 'items', 'creator'])
                ->orderBy('created_at', 'desc');

            if ($request->filled('period_id')) {
                $query->where('period_id', $request->period_id);
            }

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            return DataTables::eloquent($query)
                ->addIndexColumn()
                ->addColumn('pr_number_display', fn($pr) => $pr->pr_number ?? '-')
                ->addColumn('period_name', fn($pr) => $pr->period->name ?? '-')
                ->addColumn('creator_name', fn($pr) => $pr->creator->name ?? '-')
                ->addColumn('item_count', fn($pr) => $pr->items->count() . ' Item')
                ->addColumn('status_badge', function ($pr) {
                    $badgeClass = match($pr->status) {
                        'draft' => 'bg-secondary',
                        'submitted' => 'bg-primary',
                        'rejected' => 'bg-danger',
                        'bidding' => 'bg-warning text-dark',
                        'completed' => 'bg-success',
                        default => 'bg-secondary'
                    };
                    $statusLabel = match($pr->status) {
                        'draft' => 'Draft',
                        'submitted' => 'Submitted',
                        'rejected' => 'Rejected',
                        'bidding' => 'Bidding',
                        'completed' => 'Completed',
                        default => ucwords(str_replace('_', ' ', $pr->status)),
                    };
                    return '<span class="badge ' . $badgeClass . ' text-uppercase" style="font-size: 0.7rem;">' . $statusLabel . '</span>';
                })
                ->addColumn('created_date', fn($pr) => $pr->created_at->format('d M Y, H:i'))
                ->addColumn('action', function ($pr) {
                    $html = '<a href="' . PurchasingNavigation::toRoute('purchasing.requirements.show', $pr->id) . '" class="btn btn-sm btn-outline-info" title="Lihat Detail"><i class="bi bi-eye"></i></a>';
                    if ($pr->created_by === auth()->id() && in_array($pr->status, ['draft', 'rejected'])) {
                        $html .= ' <a href="' . PurchasingNavigation::toRoute('purchasing.requirements.edit', $pr->id) . '" class="btn btn-sm btn-outline-primary" title="Edit"><i class="bi bi-pencil"></i></a>';
                        $html .= ' <form action="' . route('purchasing.requirements.destroy', $pr->id) . '" method="POST" class="d-inline delete-form">' . csrf_field() . method_field('DELETE') . '<button type="button" class="btn btn-sm btn-outline-danger btn-delete" title="Hapus"><i class="bi bi-trash"></i></button></form>';
                    }
                    return $html;
                })
                ->rawColumns(['status_badge', 'action'])
                ->make(true);
        }

        $periods = Period::orderBy('year', 'desc')->orderBy('month', 'desc')->get();

        return view('purchasing.pr.index', compact('periods'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $periods = Period::where('status', 'open')->orderBy('year', 'desc')->orderBy('month', 'desc')->get();

        if ($periods->isEmpty()) {
            return redirect(PurchasingNavigation::backUrl('purchasing.requirements.index'))
                ->with('error', 'Tidak ada periode aktif (open). Silakan hubungi Admin untuk membuka periode.');
        }

        return view('purchasing.pr.create', compact('periods'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $this->prepareMaterialInput($request);

        $validated = $request->validate(
            $this->materialValidationRules(),
            $this->materialValidationMessages()
        );
        $items = $this->sanitizeMaterialItems($validated['items']);

        try {
            DB::beginTransaction();

            $pr = PurchaseRequirement::create([
                'period_id' => $validated['period_id'],
                'created_by' => auth()->id(),
                'pr_number' => $validated['action'] === 'submitted' ? PurchaseRequirement::generatePrNumber() : null,
                'notes' => $request->notes,
                'status' => $validated['action'], // 'draft' or 'submitted'
            ]);

            foreach ($items as $item) {
                $pr->items()->create($item);
            }

            DB::commit();

            // Notify admin when PR submitted
            if ($validated['action'] === 'submitted') {
                $admins = \App\Models\User::where('role', 'admin')->get();
                foreach ($admins as $admin) {
                    $admin->notify(new \App\Notifications\SystemNotification(
                        'Permintaan Material Baru',
                        "PR baru {$pr->pr_number} telah diajukan oleh " . auth()->user()->name,
                        route('purchasing.requirements.show', $pr->id),
                        'bi-clipboard-plus text-primary',
                    ));
                }
            }

            $message = $validated['action'] === 'submitted'
                ? "Permintaan material berhasil diajukan!"
                : "Permintaan material berhasil disimpan sebagai draft.";

            return redirect(PurchasingNavigation::backUrl('purchasing.requirements.index'))->with('success', $message);

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withInput()->with('error', 'Terjadi kesalahan sistem saat menyimpan data: ' . $e->getMessage());
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $pr = PurchaseRequirement::with([
            'period',
            'items',
            'quotations.supplier',
            'quotations.items.prItem',
            'quotations.exchange_rate',
            'creator',
        ])->findOrFail($id);

        $quotations = $pr->quotations->map(function ($quotation) {
            $quotation->total_amount = $quotation->items->sum(function ($item) {
                $weight = optional($item->prItem)->weight_needed ?? 0;

                return (float) $item->price_per_kg * (float) $weight;
            });

            $rate = $quotation->exchange_rate;
            $quotation->total_idr = $rate
                ? $quotation->items->sum(function ($item) use ($rate) {
                    $weight = optional($item->prItem)->weight_needed ?? 0;

                    return (float) $item->price_per_kg * (float) $weight * (float) $rate->rate_to_idr;
                })
                : null;

            return $quotation;
        });

        $lowestTotalIdr = $quotations
            ->pluck('total_idr')
            ->filter(fn($total) => $total !== null && $total > 0)
            ->min();

        $submittedQuotationCount = $quotations->where('status', 'submitted')->count();

        return view('purchasing.pr.show', compact(
            'pr',
            'quotations',
            'lowestTotalIdr',
            'submittedQuotationCount'
        ));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        $pr = PurchaseRequirement::with(['period', 'items'])->findOrFail($id);

        if ($pr->created_by !== auth()->id() || !in_array($pr->status, ['draft', 'rejected'])) {
            return redirect(PurchasingNavigation::backUrl('purchasing.requirements.index'))
                ->with('error', "Anda tidak dapat mengedit permintaan ini.");
        }

        $periods = Period::where('status', 'open')
            ->orWhere('id', $pr->period_id) // Allow keeping current period even if closed
            ->orderBy('year', 'desc')->orderBy('month', 'desc')->get();

        return view('purchasing.pr.edit', compact('pr', 'periods'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $pr = PurchaseRequirement::findOrFail($id);

        if ($pr->created_by !== auth()->id() || !in_array($pr->status, ['draft', 'rejected'])) {
            return redirect(PurchasingNavigation::backUrl('purchasing.requirements.index'))
                ->with('error', "Anda tidak dapat mengedit permintaan ini.");
        }

        $this->prepareMaterialInput($request);

        $validated = $request->validate(
            $this->materialValidationRules(),
            $this->materialValidationMessages()
        );
        $items = $this->sanitizeMaterialItems($validated['items']);

        try {
            DB::beginTransaction();

            $pr->update([
                'period_id' => $validated['period_id'],
                'pr_number' => ($validated['action'] === 'submitted' && !$pr->pr_number) ? PurchaseRequirement::generatePrNumber() : $pr->pr_number,
                'notes' => $request->notes,
                'status' => $validated['action'],
            ]);

            // For simplicity in dynamic forms: delete all existing items and recreate
            // Alternatively, use an ID-based check, but recreate is safe within transaction.
            $pr->items()->delete();

            foreach ($items as $item) {
                $pr->items()->create($item);
            }

            DB::commit();

            $message = $validated['action'] === 'submitted'
                ? 'Permintaan material berhasil diajukan!'
                : 'Draft permintaan material berhasil diperbarui.';

            return redirect(PurchasingNavigation::backUrl('purchasing.requirements.index'))->with('success', $message);

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withInput()->with('error', 'Terjadi kesalahan sistem saat menyimpan data: ' . $e->getMessage());
        }
    }

    private function prepareMaterialInput(Request $request): void
    {
        $items = $request->input('items');

        if (is_array($items)) {
            $request->merge([
                'items' => $this->sanitizeMaterialItems($items),
            ]);
        }
    }

    private function materialValidationRules(): array
    {
        return [
            'period_id' => 'required|exists:periods,id',
            'action' => 'required|in:draft,submitted',
            'items' => 'required|array|min:1',
            'items.*.material_name' => 'required|string|max:255',
            'items.*.weight_needed' => 'required|numeric|min:0.01',
            'items.*.hs_code' => 'nullable|string|max:100',
            'items.*.shape' => ['nullable', Rule::in(PrItem::SHAPES)],
            'items.*.thickness' => 'nullable|numeric|min:0',
            'items.*.d_inner' => 'nullable|numeric|min:0',
            'items.*.d_outer' => 'nullable|numeric|min:0',
            'items.*.width' => 'nullable|numeric|min:0',
            'items.*.length' => 'nullable|numeric|min:0',
        ];
    }

    private function materialValidationMessages(): array
    {
        return [
            'items.required' => 'Minimal 1 material wajib ditambahkan.',
            'items.*.material_name.required' => 'Nama material wajib diisi.',
            'items.*.weight_needed.required' => 'Berat dibutuhkan wajib diisi.',
            'items.*.shape.in' => 'Bentuk material harus Flat, Round, atau Hollow.',
        ];
    }

    private function sanitizeMaterialItems(array $items): array
    {
        $sanitized = [];

        foreach ($items as $index => $item) {
            $sanitized[$index] = PrItem::sanitizeMaterialData(is_array($item) ? $item : []);
        }

        return $sanitized;
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $pr = PurchaseRequirement::findOrFail($id);

        if ($pr->created_by !== auth()->id() || !in_array($pr->status, ['draft', 'rejected'])) {
            return redirect(PurchasingNavigation::backUrl('purchasing.requirements.index'))
                ->with('error', "Permintaan material tidak dapat dihapus karena sudah diproses.");
        }

        try {
            DB::beginTransaction();
            $pr->items()->delete();
            $pr->delete();
            DB::commit();

            return redirect(PurchasingNavigation::backUrl('purchasing.requirements.index'))
                ->with('success', 'Permintaan material berhasil dihapus.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Terjadi kesalahan saat menghapus data.');
        }
    }
}
