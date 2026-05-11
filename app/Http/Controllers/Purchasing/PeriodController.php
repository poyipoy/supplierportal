<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\Period;
use Illuminate\Http\Request;

class PeriodController extends Controller
{
    /**
     * Display a listing of the periods.
     */
    public function index()
    {
        $periods = Period::with('creator')->orderByDesc('year')->orderByDesc('month')->get();
        return view('purchasing.periods.index', compact('periods'));
    }

    /**
     * Store a newly created period in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'month' => 'required|integer|min:1|max:12',
            'year' => 'required|integer|min:2000',
            'status' => 'required|in:open,closed',
        ]);

        // Check if period already exists
        $exists = Period::where('month', $request->month)
            ->where('year', $request->year)
            ->exists();

        if ($exists) {
            return back()->with('error', 'Periode untuk bulan dan tahun tersebut sudah ada.');
        }

        Period::create([
            'name' => $request->name,
            'month' => $request->month,
            'year' => $request->year,
            'status' => $request->status,
            'created_by' => auth()->id(),
        ]);

        return back()->with('success', 'Periode berhasil ditambahkan.');
    }

    /**
     * Update the specified period in storage.
     */
    public function update(Request $request, string $id)
    {
        $period = Period::findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:255',
            'month' => 'required|integer|min:1|max:12',
            'year' => 'required|integer|min:2000',
            'status' => 'required|in:open,closed',
        ]);

        // Check uniqueness if changed
        if ($request->month != $period->month || $request->year != $period->year) {
            $exists = Period::where('month', $request->month)
                ->where('year', $request->year)
                ->where('id', '!=', $period->id)
                ->exists();

            if ($exists) {
                return back()->with('error', 'Periode untuk bulan dan tahun tersebut sudah ada.');
            }
        }

        $period->update([
            'name' => $request->name,
            'month' => $request->month,
            'year' => $request->year,
            'status' => $request->status,
        ]);

        return back()->with('success', 'Periode berhasil diperbarui.');
    }
}
