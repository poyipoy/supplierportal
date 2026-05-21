<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\PurchaseRequirement;
use App\Models\PrItem;
use App\Models\Period;
use App\Support\PurchasingNavigation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseRequirementController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = PurchaseRequirement::with(['period', 'items', 'creator'])
            ->orderBy('created_at', 'desc');

        if ($request->filled('period_id')) {
            $query->where('period_id', $request->period_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $requirements = $query->get();
        $periods = Period::orderBy('year', 'desc')->orderBy('month', 'desc')->get();

        return view('purchasing.pr.index', compact('requirements', 'periods'));
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
        $request->validate([
            'period_id' => 'required|exists:periods,id',
            'action' => 'required|in:draft,submitted',
            'items' => 'required|array|min:1',
            'items.*.material_name' => 'required|string|max:255',
            'items.*.weight_needed' => 'required|numeric|min:0.01',
            'items.*.hs_code' => 'nullable|string|max:100',
            'items.*.shape' => 'nullable|string|max:100',
            'items.*.thickness' => 'nullable|numeric',
            'items.*.d_inner' => 'nullable|numeric',
            'items.*.d_outer' => 'nullable|numeric',
            'items.*.width' => 'nullable|numeric',
            'items.*.length' => 'nullable|numeric',
        ], [
            'items.required' => 'Minimal 1 material wajib ditambahkan.',
            'items.*.material_name.required' => 'Nama material wajib diisi.',
            'items.*.weight_needed.required' => 'Berat dibutuhkan wajib diisi.',
        ]);

        try {
            DB::beginTransaction();

            $pr = PurchaseRequirement::create([
                'period_id' => $request->period_id,
                'created_by' => auth()->id(),
                'pr_number' => $request->action === 'submitted' ? PurchaseRequirement::generatePrNumber() : null,
                'notes' => $request->notes,
                'status' => $request->action, // 'draft' or 'submitted'
            ]);

            foreach ($request->items as $item) {
                $pr->items()->create([
                    'hs_code' => $item['hs_code'] ?? null,
                    'material_name' => $item['material_name'],
                    'shape' => $item['shape'] ?? null,
                    'thickness' => $item['thickness'] ?? null,
                    'd_inner' => $item['d_inner'] ?? null,
                    'd_outer' => $item['d_outer'] ?? null,
                    'width' => $item['width'] ?? null,
                    'length' => $item['length'] ?? null,
                    'weight_needed' => $item['weight_needed'],
                ]);
            }

            DB::commit();

            // Notify admin when PR submitted
            if ($request->action === 'submitted') {
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

            $message = $request->action === 'submitted'
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

        $request->validate([
            'period_id' => 'required|exists:periods,id',
            'action' => 'required|in:draft,submitted',
            'items' => 'required|array|min:1',
            'items.*.material_name' => 'required|string|max:255',
            'items.*.weight_needed' => 'required|numeric|min:0.01',
            'items.*.hs_code' => 'nullable|string|max:100',
            'items.*.shape' => 'nullable|string|max:100',
            'items.*.thickness' => 'nullable|numeric',
            'items.*.d_inner' => 'nullable|numeric',
            'items.*.d_outer' => 'nullable|numeric',
            'items.*.width' => 'nullable|numeric',
            'items.*.length' => 'nullable|numeric',
        ], [
            'items.required' => 'Minimal 1 material wajib ditambahkan.',
            'items.*.material_name.required' => 'Nama material wajib diisi.',
            'items.*.weight_needed.required' => 'Berat dibutuhkan wajib diisi.',
        ]);

        try {
            DB::beginTransaction();

            $pr->update([
                'period_id' => $request->period_id,
                'pr_number' => ($request->action === 'submitted' && !$pr->pr_number) ? PurchaseRequirement::generatePrNumber() : $pr->pr_number,
                'notes' => $request->notes,
                'status' => $request->action,
            ]);

            // For simplicity in dynamic forms: delete all existing items and recreate
            // Alternatively, use an ID-based check, but recreate is safe within transaction.
            $pr->items()->delete();

            foreach ($request->items as $item) {
                $pr->items()->create([
                    'hs_code' => $item['hs_code'] ?? null,
                    'material_name' => $item['material_name'],
                    'shape' => $item['shape'] ?? null,
                    'thickness' => $item['thickness'] ?? null,
                    'd_inner' => $item['d_inner'] ?? null,
                    'd_outer' => $item['d_outer'] ?? null,
                    'width' => $item['width'] ?? null,
                    'length' => $item['length'] ?? null,
                    'weight_needed' => $item['weight_needed'],
                ]);
            }

            DB::commit();

            $message = $request->action === 'submitted'
                ? 'Permintaan material berhasil diajukan!'
                : 'Draft permintaan material berhasil diperbarui.';

            return redirect(PurchasingNavigation::backUrl('purchasing.requirements.index'))->with('success', $message);

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withInput()->with('error', 'Terjadi kesalahan sistem saat menyimpan data: ' . $e->getMessage());
        }
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
