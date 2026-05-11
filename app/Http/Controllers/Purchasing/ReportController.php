<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\Period;
use App\Models\User;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    /**
     * Tampilkan halaman utama Laporan (Dashboard Export).
     */
    public function index()
    {
        // Data master untuk dropdown filter form export
        $periods = Period::orderByDesc('year')->orderByDesc('month')->get();
        $suppliers = User::where('role', 'supplier')->orderBy('name')->get();

        return view('purchasing.reports.index', compact('periods', 'suppliers'));
    }
}
