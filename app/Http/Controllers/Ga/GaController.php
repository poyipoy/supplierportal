<?php

namespace App\Http\Controllers\Ga;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\GaClaim;
use App\Models\PaymentBatch;
use App\Models\PaymentItem;
use App\Services\Ga\GaClaimService;
use App\Services\Payment\PaymentBatchService;
use Illuminate\Http\Request;

class GaController extends Controller
{
    public function dashboard()
    {
        $kpis = [
            'submitted' => GaClaim::where('status', GaClaim::STATUS_SUBMITTED)->count(),
            'basic_verified' => GaClaim::where('status', GaClaim::STATUS_BASIC_VERIFIED)->count(),
            'ready_to_pay' => GaClaim::where('status', GaClaim::STATUS_READY_TO_PAY)->count(),
            'need_revision' => GaClaim::where('status', GaClaim::STATUS_NEED_REVISION)->count(),
            'paid' => GaClaim::where('status', GaClaim::STATUS_PAID)->count(),
        ];

        $recentClaims = GaClaim::with('employee')->latest('id')->limit(10)->get();

        return view('ga.dashboard', compact('kpis', 'recentClaims'));
    }

    public function index()
    {
        $claims = GaClaim::with(['employee', 'receipt'])->latest('id')->paginate(20);

        return view('ga.claims.index', compact('claims'));
    }

    public function create()
    {
        $employees = Employee::active()->orderBy('name')->get();
        $claimTypes = GaClaim::CLAIM_TYPES;

        return view('ga.claims.create', compact('employees', 'claimTypes'));
    }

    public function store(Request $request, GaClaimService $service)
    {
        $data = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'claim_type' => 'required|in:'.implode(',', GaClaim::CLAIM_TYPES),
            'claim_date' => 'required|date',
            'amount' => 'required|numeric|min:1',
            'description' => 'nullable|string|max:1000',
        ]);

        $files = $request->allFiles();
        $claim = $service->submitClaim($request->user(), $data, $files);

        return redirect()->route('ga.claims.show', $claim)->with('success', "GA Claim [{$claim->claim_number}] submitted with Tanda Terima.");
    }

    public function show(GaClaim $claim)
    {
        $claim->load(['employee', 'receipt', 'documents', 'statusHistories.actor']);

        return view('ga.claims.show', compact('claim'));
    }

    public function receipt(GaClaim $claim)
    {
        $claim->load(['employee', 'receipt']);

        return view('ga.claims.receipt', compact('claim'));
    }

    public function basicVerify(GaClaim $claim, Request $request, GaClaimService $service)
    {
        $service->basicVerify($claim, $request->user(), $request->input('notes'));

        return back()->with('success', 'GA Basic Verification recorded.');
    }

    public function drpDraft()
    {
        $eligibleClaims = GaClaim::where('status', GaClaim::STATUS_READY_TO_PAY)
            ->whereDoesntHave('paymentItem', function ($q) {
                $q->where('status', PaymentItem::STATUS_ACTIVE)
                    ->whereHas('group.batch', fn ($b) => $b->whereIn('status', [PaymentBatch::STATUS_DRAFT, PaymentBatch::STATUS_FINALIZED]));
            })
            ->with('employee')
            ->latest('id')
            ->get();

        return view('ga.drp.draft', compact('eligibleClaims'));
    }

    public function createDrpDraft(Request $request, PaymentBatchService $service)
    {
        $request->validate([
            'claim_ids' => 'required|array|min:1',
            'claim_ids.*' => 'required|integer|exists:ga_claims,id',
            'notes' => 'nullable|string|max:1000',
        ]);

        $batch = $service->createGaBatch($request->user(), $request->input('claim_ids'), $request->input('notes'));

        return redirect()->route('ga.claims.index')->with('success', "DRP GA Draft [{$batch->batch_number}] prepared for Finance review.");
    }
}
