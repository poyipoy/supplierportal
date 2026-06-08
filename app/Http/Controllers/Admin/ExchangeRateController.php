<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ExchangeRate;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExchangeRateController extends Controller
{
    /**
     * Display a listing of the exchange rates.
     */
    public function index(Request $request)
    {
        $request->validate([
            'currency' => ['nullable', Rule::in(ExchangeRate::CURRENCIES)],
        ]);

        $query = ExchangeRate::with('creator')->orderBy('valid_from', 'desc');

        if ($request->filled('currency')) {
            $query->where('currency', $request->currency);
        }

        $rates = $query->paginate(30)->withQueryString();
        $currencyCounts = ExchangeRate::selectRaw('currency, COUNT(*) as total')
            ->groupBy('currency')
            ->pluck('total', 'currency')
            ->toArray();
        $totalRates = ExchangeRate::count();

        return view('admin.exchange-rates.index', compact('rates', 'currencyCounts', 'totalRates'));
    }

    /**
     * Store a newly created exchange rate in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'currency' => ['required', Rule::in(ExchangeRate::CURRENCIES)],
            'rate_to_idr' => 'required|numeric|min:1',
            'valid_from' => 'required|date',
        ]);

        ExchangeRate::create([
            'currency' => $request->currency,
            'rate_to_idr' => $request->rate_to_idr,
            'valid_from' => $request->valid_from,
            'created_by' => auth()->id(),
        ]);

        return back()->with('success', "New {$request->currency} exchange rate successfully added.");
    }
}
