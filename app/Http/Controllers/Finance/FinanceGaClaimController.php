<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\GaClaim;
use App\Services\Ga\GaVerificationService;
use Illuminate\Http\Request;

class FinanceGaClaimController extends Controller
{
    public function index(Request $request)
    {
        $query = GaClaim::with('employee');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('claim_number', 'like', "%{$search}%")
                    ->orWhereHas('employee', fn ($e) => $e->where('name', 'like', "%{$search}%")->orWhere('department', 'like', "%{$search}%"));
            });
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($employeeId = $request->input('employee_id')) {
            $query->where('employee_id', $employeeId);
        }

        $claims = $query->latest('id')->paginate(20)->withQueryString();
        $employees = Employee::orderBy('name')->get();

        return view('finance.ga-claims.index', compact('claims', 'employees'));
    }

    public function show(GaClaim $claim)
    {
        $claim->load(['employee', 'documents', 'statusHistories.actor', 'receipt']);

        return view('finance.ga-claims.show', compact('claim'));
    }

    public function verify(GaClaim $claim, Request $request, GaVerificationService $service)
    {
        $request->validate([
            'approve' => 'required|boolean',
            'reason' => 'required_if:approve,0|nullable|string|max:1000',
        ]);

        $service->financeVerify(
            $claim,
            $request->user(),
            (bool) $request->input('approve'),
            $request->input('reason')
        );

        $actionStr = $request->boolean('approve') ? 'disetujui (Ready to Pay)' : 'dimintakan revisi ke GA';

        return back()->with('success', "Klaim GA [{$claim->claim_number}] berhasil {$actionStr}.");
    }
}
