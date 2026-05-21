<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Quotation;
use App\Models\User;
use App\Models\ExchangeRate;
use App\Models\PurchaseRequirement;
use App\Notifications\SystemNotification;
use App\Support\NotificationCategory;
use App\Support\PurchasingNavigation;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QuotationListController extends Controller
{
    /**
     * Daftar semua penawaran masuk untuk Purchasing.
     */
    public function index(Request $request)
    {
        $request->validate([
            'date_from' => 'nullable|date_format:Y-m',
            'date_to' => 'nullable|date_format:Y-m',
        ]);

        if ($request->filled('date_from') && $request->filled('date_to') && $request->date_to < $request->date_from) {
            return back()
                ->withInput()
                ->withErrors(['date_to' => 'Tanggal akhir tidak boleh sebelum tanggal awal']);
        }

        $query = Quotation::with(['supplier', 'purchaseRequirement.period', 'items'])
            ->whereIn('status', ['submitted', 'revision_requested', 'accepted', 'rejected']);

        // Filter: Nomor PR
        if ($request->filled('pr_number')) {
            $query->whereHas('purchaseRequirement', function ($q) use ($request) {
                $q->where('pr_number', 'like', '%' . trim($request->pr_number) . '%');
            });
        }

        // Filter: Range tanggal penawaran diajukan
        if ($request->filled('date_from')) {
            $from = Carbon::createFromFormat('Y-m', $request->date_from)->startOfMonth();
            $query->where('submitted_at', '>=', $from);
        }

        if ($request->filled('date_to')) {
            $to = Carbon::createFromFormat('Y-m', $request->date_to)->endOfMonth();
            $query->where('submitted_at', '<=', $to);
        }

        // Filter: Supplier
        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }

        // Filter: Status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter: Mata uang
        if ($request->filled('currency')) {
            $query->where('currency', $request->currency);
        }

        $quotations = $query->orderByDesc('submitted_at')
            ->paginate(20)
            ->appends($request->except(PurchasingNavigation::RETURN_URL_KEY));

        $suppliers = User::where('role', 'supplier')->orderBy('name')->get();

        return view('purchasing.quotations.index', compact('quotations', 'suppliers'));
    }

    /**
     * Detail satu penawaran.
     */
    public function show($id)
    {
        $quotation = Quotation::with([
            'supplier.supplier',
            'purchaseRequirement.period',
            'items.prItem',
            'exchange_rate',
            'attachments',
            'purchaseOrder',
        ])->findOrFail($id);

        // Kurs penawaran dipakai untuk konversi agar konsisten dengan histori.
        $quotationRate = $quotation->exchange_rate;
        $latestRate = ExchangeRate::latestRate($quotation->currency);

        // Cek apakah bisa buat PO (quotation submitted, belum ada PO, PR belum completed)
        $canCreatePo = $quotation->status === 'submitted'
            && !$quotation->purchaseOrder
            && $quotation->purchaseRequirement->status !== 'completed'
            && !$quotation->isExpired();

        $canRequestRevision = $quotation->canRequestRevision()
            && $quotation->purchaseRequirement->status !== 'completed';
        $chatAvailable = in_array($quotation->status, ['submitted', 'revision_requested', 'accepted'], true);
        $supplierDisplayName = $quotation->supplier->supplier->company_name
            ?? $quotation->supplier->name
            ?? 'Supplier';

        return view('purchasing.quotations.show', compact(
            'quotation',
            'quotationRate',
            'latestRate',
            'canCreatePo',
            'canRequestRevision',
            'chatAvailable',
            'supplierDisplayName'
        ));
    }

    /**
     * Minta supplier merevisi penawaran yang masa berlakunya sudah lewat.
     */
    public function requestRevision(Request $request, $id)
    {
        $request->validate([
            'revision_note' => 'nullable|string|max:1000',
        ]);

        $quotation = Quotation::with([
            'supplier.supplier',
            'purchaseRequirement',
            'purchaseOrder',
        ])->findOrFail($id);

        if ($quotation->purchaseRequirement->status === 'completed') {
            return back()->with('error', 'PR sudah selesai. Penawaran tidak bisa diminta revisi.');
        }

        if (!$quotation->canRequestRevision()) {
            return back()->with('error', 'Revisi hanya bisa diminta untuk penawaran submitted yang sudah melewati masa berlaku dan belum dibuat PO.');
        }

        $revisionNote = trim((string) $request->input('revision_note', ''));
        $supplierName = $quotation->supplier->supplier->company_name
            ?? $quotation->supplier->name
            ?? 'Supplier';

        DB::transaction(function () use ($quotation, $revisionNote, $supplierName) {
            $quotation->update([
                'status' => Quotation::STATUS_REVISION_REQUESTED,
            ]);

            $conversation = Conversation::firstOrCreate([
                'conversable_type' => PurchaseRequirement::class,
                'conversable_id' => $quotation->pr_id,
                'purchasing_user_id' => auth()->id(),
                'supplier_user_id' => $quotation->supplier_id,
            ]);

            $message = 'Mohon revisi penawaran untuk PR '
                . ($quotation->purchaseRequirement->pr_number ?? '#' . $quotation->pr_id)
                . ' karena masa berlaku penawaran sudah lewat.';

            if ($revisionNote !== '') {
                $message .= "\n\nCatatan revisi: " . $revisionNote;
            }

            $conversation->messages()->create([
                'sender_id' => auth()->id(),
                'body' => $message,
            ]);

            $quotation->supplier->notify(new SystemNotification(
                'Revisi Penawaran Diminta',
                'Purchasing meminta revisi penawaran untuk PR :pr_number.',
                route('supplier.quotations.show', $quotation->id),
                'bi-arrow-repeat text-warning',
                [
                    'category' => NotificationCategory::QUOTATION,
                    'quotation_id' => $quotation->id,
                    'pr_id' => $quotation->pr_id,
                    'pr_number' => $quotation->purchaseRequirement->pr_number,
                    'revision_note' => $revisionNote,
                ],
                [
                    'pr_number' => $quotation->purchaseRequirement->pr_number ?? '-',
                    'supplier' => $supplierName,
                ]
            ));
        });

        $showParameters = [$quotation->id];
        if (PurchasingNavigation::isSafeUrl($request->input('return_url'))) {
            $showParameters['return_url'] = $request->input('return_url');
        }

        return redirect()->route('purchasing.quotations.show', $showParameters)
            ->with('success', 'Permintaan revisi penawaran sudah dikirim ke supplier.');
    }
}
