<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ExchangeRate;
use App\Models\MaterialClaim;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    public function dashboard()
    {
        $usersByRole = User::where('is_active', true)->selectRaw('role, COUNT(*) as total')
            ->groupBy('role')->pluck('total', 'role')->toArray();
        $totalUsersActive = array_sum($usersByRole);
        $transaksiBulanIni = \App\Models\PurchaseOrder::whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)->count();
        $supplierCount = Supplier::count();
        $klaimAktif = MaterialClaim::whereIn('status', ['pending', 'responded'])->count();

        $recentActivities = DatabaseNotification::orderBy('created_at', 'desc')->take(10)->get();

        $latestRates = collect(ExchangeRate::CURRENCIES)
            ->mapWithKeys(fn($currency) => [$currency => ExchangeRate::latestRate($currency)]);
        $riwayatKurs = ExchangeRate::orderBy('valid_from', 'desc')->take(30)->get();
        $riwayatKursTotal = ExchangeRate::count();

        return view('admin.dashboard', compact(
            'usersByRole', 'totalUsersActive', 'transaksiBulanIni', 'supplierCount',
            'klaimAktif', 'recentActivities', 'latestRates', 'riwayatKurs', 'riwayatKursTotal'
        ));
    }

    public function updateKurs(Request $request)
    {
        $request->validate([
            'currency' => ['required', Rule::in(ExchangeRate::CURRENCIES)],
            'rate_to_idr' => 'required|numeric|min:0.01',
        ]);
        ExchangeRate::create([
            'currency' => $request->currency,
            'rate_to_idr' => $request->rate_to_idr,
            'valid_from' => now(),
            'created_by' => auth()->id(),
        ]);
        return back()->with('success', "{$request->currency} exchange rate successfully updated.");
    }
}
