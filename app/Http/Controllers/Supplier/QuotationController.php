<?php

namespace App\Http\Controllers\Supplier;

use App\Http\Controllers\Controller;
use App\Models\ExchangeRate;
use App\Models\Period;
use App\Models\PurchaseRequirement;
use App\Models\Quotation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class QuotationController extends Controller
{
    /**
     * Tampilkan daftar periode yang open.
     */
    public function index()
    {
        $periods = Period::where('status', 'open')->orderBy('created_at', 'desc')->get();

        // Count PRs for each period
        foreach ($periods as $period) {
            $allPrs = PurchaseRequirement::where('period_id', $period->id)
                ->whereIn('status', ['submitted', 'bidding'])
                ->get();

            $period->total_prs = $allPrs->count();
            
            // Berapa PR yang sudah dikirim penawaran oleh user ini (termasuk draft/submitted)
            $respondedCount = Quotation::where('supplier_id', auth()->id())
                ->whereIn('pr_id', $allPrs->pluck('id'))
                ->count();
                
            $period->responded_prs = $respondedCount;
            $period->unresponded_prs = $period->total_prs - $respondedCount;
        }

        return view('supplier.quotations.index', compact('periods'));
    }

    /**
     * Tampilkan daftar PR pada periode tertentu.
     */
    public function period($period_id)
    {
        $period = Period::findOrFail($period_id);
        
        $requirements = PurchaseRequirement::with(['items', 'quotations' => function($query) {
                $query->where('supplier_id', auth()->id());
            }])
            ->where('period_id', $period_id)
            ->whereIn('status', ['submitted', 'bidding'])
            ->get();

        return view('supplier.quotations.period', compact('period', 'requirements'));
    }

    /**
     * Tampilkan form untuk membuat/edit penawaran untuk PR tertentu.
     */
    public function create($pr_id)
    {
        $pr = PurchaseRequirement::with('items')->findOrFail($pr_id);

        if (!in_array($pr->status, ['submitted', 'bidding'])) {
            return redirect()->route('supplier.quotations.index')->with('error', __('Permintaan ini tidak tersedia untuk penawaran.'));
        }

        // Cari quotation yang sudah ada
        $quotation = Quotation::with('items')
            ->where('pr_id', $pr_id)
            ->where('supplier_id', auth()->id())
            ->first();

        // Jika sudah submitted, redirect ke show
        if ($quotation && $quotation->status !== 'draft') {
            return redirect()->route('supplier.quotations.show', $quotation->id)
                ->with('info', __('Anda sudah mengirim penawaran untuk permintaan ini.'));
        }

        $usdRate = ExchangeRate::where('currency', 'USD')->orderBy('valid_from', 'desc')->first();
        $jpyRate = ExchangeRate::where('currency', 'JPY')->orderBy('valid_from', 'desc')->first();

        return view('supplier.quotations.create', compact('pr', 'quotation', 'usdRate', 'jpyRate'));
    }

    /**
     * Simpan penawaran (Draft atau Submit).
     */
    public function store(Request $request, $pr_id)
    {
        $pr = PurchaseRequirement::findOrFail($pr_id);

        if (!in_array($pr->status, ['submitted', 'bidding'])) {
            return redirect()->route('supplier.quotations.index')->with('error', __('Permintaan ini tidak tersedia untuk penawaran.'));
        }

        $request->validate([
            'action' => 'required|in:draft,submitted',
            'currency' => 'required|in:USD,JPY',
            'estimated_delivery' => 'required|date',
            'payment_terms' => 'nullable|string',
            'validity_period' => 'nullable|date',
            'general_notes' => 'nullable|string',
            'items' => 'required|array',
            'items.*.pr_item_id' => 'required|exists:pr_items,id',
            'items.*.price_per_kg' => 'required|numeric|min:0.01',
            'items.*.notes' => 'nullable|string'
        ]);

        $quotation = Quotation::where('pr_id', $pr_id)
            ->where('supplier_id', auth()->id())
            ->first();

        if ($quotation && $quotation->status !== 'draft') {
            return redirect()->route('supplier.quotations.show', $quotation->id)
                ->with('error', __('Penawaran ini sudah diajukan dan tidak bisa diubah.'));
        }

        try {
            DB::beginTransaction();

            // Hitung exchange rate jika disubmit
            $exchangeRateId = null;
            if ($request->action === 'submitted') {
                $rate = ExchangeRate::where('currency', $request->currency)->orderBy('valid_from', 'desc')->first();
                if ($rate) {
                    $exchangeRateId = $rate->id;
                }
            }

            if (!$quotation) {
                $quotation = Quotation::create([
                    'pr_id' => $pr_id,
                    'supplier_id' => auth()->id(),
                    'currency' => $request->currency,
                    'status' => $request->action,
                    'submitted_at' => $request->action === 'submitted' ? now() : null,
                    'exchange_rate_id' => $exchangeRateId,
                    'estimated_delivery' => $request->estimated_delivery,
                    'payment_terms' => $request->payment_terms,
                    'validity_period' => $request->validity_period,
                    'general_notes' => $request->general_notes,
                ]);
            } else {
                $quotation->update([
                    'currency' => $request->currency,
                    'status' => $request->action,
                    'submitted_at' => $request->action === 'submitted' ? now() : null,
                    'exchange_rate_id' => $exchangeRateId,
                    'estimated_delivery' => $request->estimated_delivery,
                    'payment_terms' => $request->payment_terms,
                    'validity_period' => $request->validity_period,
                    'general_notes' => $request->general_notes,
                ]);
                $quotation->items()->delete(); // Hapus yang lama
            }

            // Simpan items
            foreach ($request->items as $itemData) {
                $prItem = $pr->items()->where('id', $itemData['pr_item_id'])->first();
                if ($prItem) {
                    $amount = $itemData['price_per_kg'] * $prItem->weight_needed;
                    
                    $quotation->items()->create([
                        'pr_item_id' => $prItem->id,
                        'price_per_kg' => $itemData['price_per_kg'],
                        'amount' => $amount,
                        'notes' => $itemData['notes'] ?? null,
                    ]);
                }
            }

            // Jika ada penawaran disubmit, pastikan status PR = bidding jika tadinya submitted
            if ($request->action === 'submitted' && $pr->status === 'submitted') {
                $pr->update(['status' => 'bidding']);
            }

            DB::commit();

            // Notify purchasing when quotation submitted
            if ($request->action === 'submitted') {
                $purchasingUsers = \App\Models\User::where('role', 'purchasing')->get();
                foreach ($purchasingUsers as $pUser) {
                    /** @var \App\Models\User $pUser */
                    $pUser->notify(new \App\Notifications\SystemNotification(
                        'Penawaran Baru Masuk',
                        'Supplier :name mengirim penawaran untuk PR :pr_number',
                        route('purchasing.requirements.show', $pr->id),
                        'bi-envelope-check text-success',
                        [],
                        ['name' => auth()->user()->name, 'pr_number' => $pr->pr_number]
                    ));
                }
            }

            $msg = $request->action === 'submitted' ? __('Penawaran berhasil dikirim.') : __('Draft penawaran berhasil disimpan.');
            return redirect()->route('supplier.quotations.period', $pr->period_id)->with('success', $msg);

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withInput()->with('error', __('Gagal menyimpan penawaran: :message', ['message' => $e->getMessage()]));
        }
    }

    /**
     * Tampilkan detail penawaran.
     */
    public function show($id)
    {
        $quotation = Quotation::with(['items.prItem', 'purchaseRequirement.period', 'exchange_rate'])
            ->findOrFail($id);

        Gate::authorize('view', $quotation);

        return view('supplier.quotations.show', compact('quotation'));
    }
}
