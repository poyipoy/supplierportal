<?php

namespace App\Http\Controllers\Supplier;

use App\Http\Controllers\Controller;
use App\Models\ExchangeRate;
use App\Models\Period;
use App\Models\PurchaseRequirement;
use App\Models\Quotation;
use App\Models\Conversation;
use App\Support\NotificationCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Yajra\DataTables\Facades\DataTables;

class QuotationController extends Controller
{
    /**
     * Tampilkan daftar periode yang open.
     */
    public function index(Request $request)
    {
        $supplierId = auth()->id();

        $periods = Period::where('status', 'open')
            ->whereHas('purchaseRequirements', function ($query) use ($supplierId) {
                $query->visibleToSupplier($supplierId);
            })
            ->orWhereHas('purchaseRequirements.quotations', function ($query) use ($supplierId) {
                $query->where('supplier_id', $supplierId);
            })
            ->orderBy('year', 'desc')
            ->orderBy('month', 'desc')
            ->get();

        // Count PRs for each period
        foreach ($periods as $period) {
            $activePrs = PurchaseRequirement::where('period_id', $period->id)
                ->whereIn('status', ['submitted', 'bidding'])
                ->visibleToSupplier($supplierId)
                ->get();

            $quotedPrIds = Quotation::where('supplier_id', $supplierId)
                ->whereHas('purchaseRequirement', function ($query) use ($period) {
                    $query->where('period_id', $period->id);
                })
                ->pluck('pr_id');

            $period->total_prs = $activePrs->pluck('id')
                ->merge($quotedPrIds)
                ->unique()
                ->count();
            
            // Berapa PR yang sudah dikirim penawaran oleh user ini (termasuk draft/submitted/rejected/accepted)
            $respondedCount = Quotation::where('supplier_id', auth()->id())
                ->whereIn('pr_id', $quotedPrIds)
                ->count();

            $rejectedCount = Quotation::where('supplier_id', auth()->id())
                ->whereIn('pr_id', $quotedPrIds)
                ->where('status', 'rejected')
                ->count();
                
            $period->responded_prs = $respondedCount;
            $period->rejected_prs = $rejectedCount;
            $period->unresponded_prs = $activePrs->filter(function ($pr) use ($supplierId) {
                return ! Quotation::where('supplier_id', $supplierId)
                    ->where('pr_id', $pr->id)
                    ->exists();
            })->count();
        }

        return view('supplier.quotations.index', compact('periods'));
    }

    /**
     * Tampilkan daftar PR pada periode tertentu.
     */
    public function period(Request $request, $period_id)
    {
        $period = Period::findOrFail($period_id);
        $supplierId = auth()->id();
        
        $query = PurchaseRequirement::with(['items', 'quotations' => function($query) use ($supplierId) {
                $query->where('supplier_id', $supplierId);
            }])
            ->where('period_id', $period_id)
            ->visibleToSupplier($supplierId)
            ->where(function ($query) use ($supplierId) {
                $query->whereIn('status', ['submitted', 'bidding'])
                    ->orWhereHas('quotations', function ($q) use ($supplierId) {
                        $q->where('supplier_id', $supplierId);
                    });
            });

        if ($request->filled('pr_number')) {
            $query->where('pr_number', 'like', '%' . $request->pr_number . '%');
        }

        if ($request->filled('status')) {
            if ($request->status === 'unresponded') {
                $query->whereIn('status', ['submitted', 'bidding'])
                    ->whereDoesntHave('quotations', function ($q) use ($supplierId) {
                        $q->where('supplier_id', $supplierId);
                    });
            } else {
                $query->whereHas('quotations', function ($q) use ($request, $supplierId) {
                    $q->where('supplier_id', $supplierId)
                        ->where('status', $request->status);
                });
            }
        }

        if ($request->ajax()) {
            return DataTables::eloquent($query->orderByDesc('updated_at'))
                ->addIndexColumn()
                ->addColumn('pr_number_display', fn($pr) => $pr->pr_number ?? '-')
                ->addColumn('updated_date', fn($pr) => $pr->updated_at->format('d M Y, H:i'))
                ->addColumn('item_count', fn($pr) => $pr->items->count() . ' Item')
                ->addColumn('status_badge', function ($pr) {
                    $quotation = $pr->quotations->first();
                    $status = $quotation ? $quotation->status : 'unresponded';
                    return match($status) {
                        'unresponded' => '<span class="badge bg-danger">Belum Direspons</span>',
                        'draft' => '<span class="badge bg-secondary">Draft</span>',
                        'revision_requested' => '<span class="badge bg-warning text-dark">Perlu Revisi</span>',
                        'submitted' => '<span class="badge bg-success">Terkirim (' . ($quotation->submitted_at?->format('d M Y H:i') ?? '-') . ')</span>',
                        'accepted' => '<span class="badge bg-primary">Diterima</span>',
                        'rejected' => '<span class="badge bg-dark">Ditolak</span>',
                        default => '<span class="badge bg-secondary">' . ucwords($status) . '</span>',
                    };
                })
                ->addColumn('action', function ($pr) {
                    $quotation = $pr->quotations->first();
                    $status = $quotation ? $quotation->status : 'unresponded';
                    return match($status) {
                        'unresponded' => '<a href="' . route('supplier.quotations.create', $pr->id) . '" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil-square me-1"></i> Buat Penawaran</a>',
                        'draft' => '<a href="' . route('supplier.quotations.create', $pr->id) . '" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil me-1"></i> Lanjutkan</a>',
                        'revision_requested' => '<a href="' . route('supplier.quotations.create', $pr->id) . '" class="btn btn-sm btn-warning text-dark"><i class="bi bi-arrow-repeat me-1"></i> Revisi Penawaran</a>',
                        default => $quotation ? '<a href="' . route('supplier.quotations.show', $quotation->id) . '" class="btn btn-sm btn-outline-success"><i class="bi bi-eye me-1"></i> Lihat</a>' : '-',
                    };
                })
                ->rawColumns(['status_badge', 'action'])
                ->make(true);
        }

        return view('supplier.quotations.period', compact('period'));
    }

    /**
     * Tampilkan form untuk membuat/edit penawaran untuk PR tertentu.
     */
    public function create($pr_id)
    {
        $pr = PurchaseRequirement::with(['items', 'invitedSuppliers'])->findOrFail($pr_id);

        if (!in_array($pr->status, ['submitted', 'bidding'])) {
            return redirect()->route('supplier.quotations.index')->with('error', 'Permintaan ini tidak tersedia untuk penawaran.');
        }

        if (! $pr->isVisibleToSupplier(auth()->id())) {
            abort(403, 'Anda tidak diundang untuk mengirim penawaran pada permintaan ini.');
        }

        // Cari quotation yang sudah ada
        $quotation = Quotation::with('items.attachments')
            ->where('pr_id', $pr_id)
            ->where('supplier_id', auth()->id())
            ->first();

        // Jika sudah final, redirect ke show. Draft dan revision_requested boleh diedit.
        if ($quotation && ! $quotation->canBeRevisedBySupplier()) {
            return redirect()->route('supplier.quotations.show', $quotation->id)
                ->with('info', 'Anda sudah mengirim penawaran untuk permintaan ini.');
        }

        $currencyOptions = ExchangeRate::CURRENCIES;
        $supplierCurrency = old('currency', $quotation?->currency);
        if (! in_array($supplierCurrency, $currencyOptions, true)) {
            $supplierCurrency = '';
        }

        $supplierRate = $supplierCurrency ? ExchangeRate::latestRate($supplierCurrency) : null;
        $currencyRates = ExchangeRate::query()
            ->whereIn('currency', $currencyOptions)
            ->orderByDesc('valid_from')
            ->get()
            ->unique('currency')
            ->mapWithKeys(fn ($rate) => [$rate->currency => (float) $rate->rate_to_idr])
            ->all();

        return view('supplier.quotations.create', compact('pr', 'quotation', 'supplierCurrency', 'supplierRate', 'currencyOptions', 'currencyRates'));
    }

    /**
     * Simpan penawaran (Draft atau Submit).
     */
    public function store(Request $request, $pr_id)
    {
        $pr = PurchaseRequirement::with('invitedSuppliers', 'items')->findOrFail($pr_id);

        if (!in_array($pr->status, ['submitted', 'bidding'])) {
            return redirect()->route('supplier.quotations.index')->with('error', 'Permintaan ini tidak tersedia untuk penawaran.');
        }

        if (! $pr->isVisibleToSupplier(auth()->id())) {
            abort(403, 'Anda tidak diundang untuk mengirim penawaran pada permintaan ini.');
        }

        $validated = $request->validate([
            'action' => 'required|in:draft,submitted',
            'currency' => ['required', Rule::in(ExchangeRate::CURRENCIES)],
            'estimated_delivery' => 'required|date',
            'payment_terms' => 'required|string|max:100',
            'validity_period' => $request->action === 'submitted'
                ? 'required|date|after_or_equal:today'
                : 'nullable|date',
            'general_notes' => 'nullable|string',
            'items' => 'required|array',
            'items.*.pr_item_id' => 'required|exists:pr_items,id',
            'items.*.price_per_kg' => 'required|numeric|min:0.01',
            'items.*.notes' => 'nullable|string',
            'items.*.mtc_file' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ], [
            'currency.required' => 'Mata uang wajib dipilih.',
            'currency.in' => 'Mata uang tidak valid.',
            'payment_terms.required' => 'Syarat pembayaran wajib diisi.',
            'validity_period.required' => 'Masa berlaku penawaran wajib diisi saat mengirim penawaran final.',
            'validity_period.after_or_equal' => 'Masa berlaku penawaran tidak boleh kurang dari hari ini.',
            'items.*.mtc_file.mimes' => 'File MTC harus berupa PDF, JPG, JPEG, atau PNG.',
            'items.*.mtc_file.max' => 'Ukuran file MTC maksimal 5MB.',
        ]);
        $supplierCurrency = $validated['currency'];

        $quotation = Quotation::where('pr_id', $pr_id)
            ->where('supplier_id', auth()->id())
            ->first();

        $wasRevisionRequested = $quotation?->status === Quotation::STATUS_REVISION_REQUESTED;

        if ($quotation && ! $quotation->canBeRevisedBySupplier()) {
            return redirect()->route('supplier.quotations.show', $quotation->id)
                ->with('error', 'Penawaran ini sudah diajukan dan tidak bisa diubah.');
        }

        try {
            DB::beginTransaction();

            $nextStatus = $request->action === 'submitted'
                ? Quotation::STATUS_SUBMITTED
                : ($wasRevisionRequested ? Quotation::STATUS_REVISION_REQUESTED : Quotation::STATUS_DRAFT);

            // Hitung exchange rate jika disubmit
            $exchangeRateId = $quotation?->currency === $supplierCurrency
                ? $quotation?->exchange_rate_id
                : null;
            if ($request->action === 'submitted') {
                $rate = ExchangeRate::latestRate($supplierCurrency);
                if (! $rate) {
                    DB::rollBack();
                    return back()
                        ->withInput()
                        ->with('error', 'Kurs ' . $supplierCurrency . ' belum tersedia. Hubungi Admin sebelum mengirim penawaran final.');
                }

                $exchangeRateId = $rate->id;
            }

            if (!$quotation) {
                $quotation = Quotation::create([
                    'pr_id' => $pr_id,
                    'supplier_id' => auth()->id(),
                    'currency' => $supplierCurrency,
                    'status' => $nextStatus,
                    'submitted_at' => $request->action === 'submitted' ? now() : null,
                    'exchange_rate_id' => $exchangeRateId,
                    'estimated_delivery' => $request->estimated_delivery,
                    'payment_terms' => $validated['payment_terms'],
                    'validity_period' => $request->validity_period,
                    'general_notes' => $request->general_notes,
                ]);
            } else {
                $quotation->update([
                    'currency' => $supplierCurrency,
                    'status' => $nextStatus,
                    'submitted_at' => $request->action === 'submitted' ? now() : $quotation->submitted_at,
                    'exchange_rate_id' => $exchangeRateId,
                    'estimated_delivery' => $request->estimated_delivery,
                    'payment_terms' => $validated['payment_terms'],
                    'validity_period' => $request->validity_period,
                    'general_notes' => $request->general_notes,
                ]);
            }

            $existingItemAttachments = $quotation->items()
                ->with('attachments')
                ->get()
                ->keyBy('pr_item_id')
                ->map(fn ($item) => $item->attachments);

            $quotation->items()->delete(); // Hapus yang lama

            // Simpan items
            foreach ($request->items as $index => $itemData) {
                $prItem = $pr->items->firstWhere('id', (int) $itemData['pr_item_id']);
                if ($prItem) {
                    $amount = $itemData['price_per_kg'] * $prItem->total_weight;
                    
                    $quotationItem = $quotation->items()->create([
                        'pr_item_id' => $prItem->id,
                        'price_per_kg' => $itemData['price_per_kg'],
                        'amount' => $amount,
                        'notes' => $itemData['notes'] ?? null,
                    ]);

                    $mtcFile = $request->file("items.{$index}.mtc_file");
                    if ($mtcFile && $mtcFile->isValid()) {
                        $this->storeMtcAttachment($quotationItem, $mtcFile);
                    } elseif ($existingItemAttachments->has($prItem->id)) {
                        foreach ($existingItemAttachments->get($prItem->id) as $attachment) {
                            $attachment->update([
                                'attachable_id' => $quotationItem->id,
                            ]);
                        }
                    }
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
                $title = $wasRevisionRequested ? 'Revisi Penawaran Masuk' : 'Penawaran Baru Masuk';
                $message = $wasRevisionRequested
                    ? 'Supplier :name mengirim ulang penawaran revisi untuk PR :pr_number'
                    : 'Supplier :name mengirim penawaran untuk PR :pr_number';

                foreach ($purchasingUsers as $pUser) {
                    /** @var \App\Models\User $pUser */
                    $pUser->notify(new \App\Notifications\SystemNotification(
                        $title,
                        $message,
                        route('purchasing.requirements.show', $pr->id),
                        'bi-envelope-check text-success',
                        ['category' => NotificationCategory::QUOTATION],
                        ['name' => auth()->user()->name, 'pr_number' => $pr->pr_number]
                    ));
                }
            }

            $msg = $request->action === 'submitted'
                ? ($wasRevisionRequested ? 'Revisi penawaran berhasil dikirim ulang.' : 'Penawaran berhasil dikirim.')
                : ($wasRevisionRequested ? 'Revisi penawaran sementara berhasil disimpan.' : 'Draft penawaran berhasil disimpan.');
            return redirect()->route('supplier.quotations.period', $pr->period_id)->with('success', $msg);

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withInput()->with('error', 'Gagal menyimpan penawaran: ' . $e->getMessage());
        }
    }

    /**
     * Tampilkan detail penawaran.
     */
    public function show($id)
    {
        $quotation = Quotation::with(['items.prItem', 'items.attachments', 'purchaseRequirement.period', 'exchange_rate'])
            ->findOrFail($id);

        Gate::authorize('view', $quotation);

        $conversation = Conversation::where('conversable_type', PurchaseRequirement::class)
            ->where('conversable_id', $quotation->pr_id)
            ->where('supplier_user_id', auth()->id())
            ->first();

        return view('supplier.quotations.show', compact('quotation', 'conversation'));
    }

    private function storeMtcAttachment(\App\Models\QuotationItem $quotationItem, \Illuminate\Http\UploadedFile $file): void
    {
        // Gunakan getPathname() untuk menghindari getRealPath() yang bernilai false di Windows
        $fileName = $file->hashName();
        $path = 'attachments/' . now()->format('Y/m') . '/' . $fileName;

        $stream = fopen($file->getPathname(), 'r');
        if ($stream) {
            \Illuminate\Support\Facades\Storage::disk('private')->put($path, $stream);
            fclose($stream);

            $quotationItem->attachments()->create([
                'file_path' => $path,
                'file_name' => $file->getClientOriginalName(),
                'file_type' => $file->getMimeType(),
                'uploaded_by' => auth()->id(),
            ]);
        }
    }
}
