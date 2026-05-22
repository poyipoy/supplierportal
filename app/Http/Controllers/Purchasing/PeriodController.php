<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\Period;
use Illuminate\Http\Request;
use Yajra\DataTables\Facades\DataTables;

class PeriodController extends Controller
{
    /**
     * Display a listing of the periods.
     */
    public function index(Request $request)
    {
        if ($request->ajax()) {
            $query = Period::with('creator')->orderByDesc('year')->orderByDesc('month');

            return DataTables::eloquent($query)
                ->addColumn('name_display', fn($p) => $p->name)
                ->addColumn('month_display', fn($p) => date('F', mktime(0, 0, 0, $p->month, 1)) . ' (' . $p->month . ')')
                ->addColumn('year_display', fn($p) => $p->year)
                ->addColumn('status_badge', fn($p) => $p->status === 'open'
                    ? '<span class="badge bg-success text-uppercase">Open</span>'
                    : '<span class="badge bg-secondary text-uppercase">Closed</span>')
                ->addColumn('creator_name', fn($p) => $p->creator->name ?? '-')
                ->addColumn('action', function ($p) {
                    return '<button class="btn btn-sm btn-outline-primary btn-edit" 
                        data-id="' . $p->id . '" 
                        data-name="' . e($p->name) . '" 
                        data-month="' . $p->month . '" 
                        data-year="' . $p->year . '" 
                        data-status="' . $p->status . '"><i class="bi bi-pencil"></i> Edit</button>';
                })
                ->rawColumns(['status_badge', 'action'])
                ->make(true);
        }

        return view('purchasing.periods.index');
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
