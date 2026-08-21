<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\Period;
use App\Models\User;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    /**
     * Display the main report and export dashboard.
     */
    public function index()
    {
        // Master data for export filter controls.
        $periods = Period::orderByDesc('year')->orderByRaw('month IS NULL DESC')->orderByDesc('month')->get();
        $suppliers = User::where('role', 'supplier')->orderBy('name')->get();

        return view('purchasing.reports.index', compact('periods', 'suppliers'));
    }
}
