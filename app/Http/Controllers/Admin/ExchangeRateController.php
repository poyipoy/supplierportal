<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ExchangeRate;
use Illuminate\Http\Request;

class ExchangeRateController extends Controller
{
    /**
     * Display a listing of the exchange rates.
     */
    public function index(Request $request)
    {
        $query = ExchangeRate::with('creator')->orderBy('valid_from', 'desc');

        if ($request->filled('currency')) {
            $query->where('currency', $request->currency);
        }

        $rates = $query->paginate(20);

        return view('admin.exchange-rates.index', compact('rates'));
    }

    /**
     * Store a newly created exchange rate in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'currency' => 'required|in:USD,JPY',
            'rate_to_idr' => 'required|numeric|min:1',
            'valid_from' => 'required|date',
        ]);

        ExchangeRate::create([
            'currency' => $request->currency,
            'rate_to_idr' => $request->rate_to_idr,
            'valid_from' => $request->valid_from,
            'created_by' => auth()->id(),
        ]);

        return back()->with('success', "Kurs {$request->currency} baru berhasil ditambahkan.");
    }
}
