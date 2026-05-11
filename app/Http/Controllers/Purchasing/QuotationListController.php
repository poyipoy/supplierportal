<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\Quotation;
use App\Models\Period;
use App\Models\User;
use App\Models\ExchangeRate;
use Illuminate\Http\Request;

class QuotationListController extends Controller
{
    /**
     * Daftar semua penawaran masuk untuk Purchasing.
     */
    public function index(Request $request)
    {
        $query = Quotation::with(['supplier', 'purchaseRequirement.period', 'items'])
            ->whereIn('status', ['submitted', 'accepted', 'rejected']);

        // Filter: Periode
        if ($request->filled('period_id')) {
            $query->whereHas('purchaseRequirement', fn($q) => $q->where('period_id', $request->period_id));
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

        $quotations = $query->orderByDesc('submitted_at')->paginate(20)->withQueryString();

        $periods = Period::orderByDesc('year')->orderByDesc('month')->get();
        $suppliers = User::where('role', 'supplier')->orderBy('name')->get();

        return view('purchasing.quotations.index', compact('quotations', 'periods', 'suppliers'));
    }

    /**
     * Detail satu penawaran.
     */
    public function show($id)
    {
        $quotation = Quotation::with([
            'supplier',
            'purchaseRequirement.period',
            'items.prItem',
            'exchange_rate',
            'attachments',
            'purchaseOrder',
        ])->findOrFail($id);

        // Kurs terbaru untuk konversi
        $latestRate = ExchangeRate::latestRate($quotation->currency);

        // Cek apakah bisa buat PO (quotation submitted, belum ada PO, PR belum completed)
        $canCreatePo = $quotation->status === 'submitted'
            && !$quotation->purchaseOrder
            && $quotation->purchaseRequirement->status !== 'completed';

        return view('purchasing.quotations.show', compact('quotation', 'latestRate', 'canCreatePo'));
    }
}
