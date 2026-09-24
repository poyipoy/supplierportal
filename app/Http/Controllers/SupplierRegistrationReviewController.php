<?php

namespace App\Http\Controllers;

use App\Http\Requests\SupplierRegistration\ApproveRegistrationRequest;
use App\Http\Requests\SupplierRegistration\RejectRegistrationRequest;
use App\Http\Requests\SupplierRegistration\RevisionRequest;
use App\Models\SupplierMasterDocument;
use App\Models\SupplierRegistrationAttempt;
use App\Services\SupplierRegistrationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Yajra\DataTables\Facades\DataTables;

class SupplierRegistrationReviewController extends Controller
{
    public function __construct(
        protected SupplierRegistrationService $registrationService,
    ) {}

    /**
     * Display a listing of supplier registration attempts.
     */
    public function index(Request $request)
    {
        $statusFilter = strtoupper((string) $request->input('status', 'ALL'));

        if ($request->ajax()) {
            $query = SupplierRegistrationAttempt::query()
                ->with(['user.supplier', 'reviewer'])
                ->orderByDesc('submitted_at');

            if ($statusFilter !== 'ALL' && in_array($statusFilter, [
                SupplierRegistrationAttempt::STATUS_PENDING,
                SupplierRegistrationAttempt::STATUS_REVISION,
                SupplierRegistrationAttempt::STATUS_APPROVED,
                SupplierRegistrationAttempt::STATUS_REJECTED,
            ], true)) {
                $query->where('status', $statusFilter);
            }

            return DataTables::eloquent($query)
                ->addIndexColumn()
                ->addColumn('company_name', fn ($attempt) => e($attempt->user->supplier?->company_name ?? $attempt->user->name))
                ->addColumn('reference', fn ($attempt) => e($attempt->user->registrationAccesses->first()?->registration_reference ?? '-'))
                ->addColumn('nib', fn ($attempt) => e($attempt->user->supplier?->nib ?? '-'))
                ->addColumn('npwp', fn ($attempt) => e($attempt->user->supplier?->npwp ?? '-'))
                ->addColumn('pic', function ($attempt) {
                    $supplier = $attempt->user->supplier;
                    if (! $supplier) {
                        return '-';
                    }

                    return e($supplier->pic_name).'<br><small class="text-muted">'.e($supplier->pic_email).' | '.e($supplier->pic_phone).'</small>';
                })
                ->addColumn('status_badge', function ($attempt) {
                    return match ($attempt->status) {
                        SupplierRegistrationAttempt::STATUS_PENDING => '<span class="ui-status-chip ui-status-chip--warning">Pending</span>',
                        SupplierRegistrationAttempt::STATUS_REVISION => '<span class="ui-status-chip ui-status-chip--info">Revision</span>',
                        SupplierRegistrationAttempt::STATUS_APPROVED => '<span class="ui-status-chip ui-status-chip--success">Approved</span>',
                        SupplierRegistrationAttempt::STATUS_REJECTED => '<span class="ui-status-chip ui-status-chip--danger">Rejected</span>',
                        default => '<span class="ui-status-chip ui-status-chip--neutral">'.e($attempt->status).'</span>',
                    };
                })
                ->addColumn('submitted_date', fn ($attempt) => $attempt->submitted_at?->format('d M Y H:i') ?? '-')
                ->addColumn('reviewer_name', fn ($attempt) => e($attempt->reviewer?->name ?? '-'))
                ->addColumn('action', function ($attempt) {
                    $url = route('supplier-registrations.show', $attempt->hash);

                    return '<a href="'.$url.'" class="ui-data-action ui-data-action--primary ui-focus-ring">Review</a>';
                })
                ->rawColumns(['pic', 'status_badge', 'action'])
                ->make(true);
        }

        // Standard server-side pagination for non-AJAX requests
        $query = SupplierRegistrationAttempt::query()
            ->with(['user.supplier', 'reviewer', 'user.registrationAccesses'])
            ->orderByDesc('submitted_at');

        if ($statusFilter !== 'ALL' && in_array($statusFilter, [
            SupplierRegistrationAttempt::STATUS_PENDING,
            SupplierRegistrationAttempt::STATUS_REVISION,
            SupplierRegistrationAttempt::STATUS_APPROVED,
            SupplierRegistrationAttempt::STATUS_REJECTED,
        ], true)) {
            $query->where('status', $statusFilter);
        }

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('user.supplier', function ($sq) use ($search) {
                    $sq->where('company_name', 'like', "%{$search}%")
                        ->orWhere('nib', 'like', "%{$search}%")
                        ->orWhere('npwp', 'like', "%{$search}%")
                        ->orWhere('pic_name', 'like', "%{$search}%");
                })->orWhereHas('user.registrationAccesses', function ($aq) use ($search) {
                    $aq->where('registration_reference', 'like', "%{$search}%");
                });
            });
        }

        $attempts = $query->paginate(15)->withQueryString();

        $counts = [
            'ALL' => SupplierRegistrationAttempt::count(),
            'PENDING' => SupplierRegistrationAttempt::where('status', SupplierRegistrationAttempt::STATUS_PENDING)->count(),
            'REVISION' => SupplierRegistrationAttempt::where('status', SupplierRegistrationAttempt::STATUS_REVISION)->count(),
            'APPROVED' => SupplierRegistrationAttempt::where('status', SupplierRegistrationAttempt::STATUS_APPROVED)->count(),
            'REJECTED' => SupplierRegistrationAttempt::where('status', SupplierRegistrationAttempt::STATUS_REJECTED)->count(),
        ];

        return view('supplier-registrations.index', [
            'attempts' => $attempts,
            'statusFilter' => $statusFilter,
            'counts' => $counts,
        ]);
    }

    /**
     * Display the registration attempt details for review.
     */
    public function show(SupplierRegistrationAttempt $attempt)
    {
        $attempt->load([
            'user.supplier',
            'user.supplierBankAccounts',
            'user.supplierMasterDocuments',
            'user.supplierScopes',
            'audits' => fn ($q) => $q->orderByDesc('created_at'),
            'reviewer',
        ]);

        $history = SupplierRegistrationAttempt::query()
            ->with('reviewer')
            ->where('user_id', $attempt->user_id)
            ->orderByDesc('attempt_number')
            ->get();

        $activeAccess = $attempt->user->registrationAccesses()
            ->whereNull('revoked_at')
            ->latest()
            ->first();

        return view('supplier-registrations.show', [
            'attempt' => $attempt,
            'user' => $attempt->user,
            'supplier' => $attempt->user->supplier,
            'bankAccount' => $attempt->user->supplierBankAccounts->last(),
            'documents' => $attempt->user->supplierMasterDocuments->keyBy('document_type'),
            'history' => $history,
            'audits' => $attempt->audits,
            'activeAccess' => $activeAccess,
        ]);
    }

    /**
     * Request revision on the registration attempt.
     */
    public function requestRevision(RevisionRequest $request, SupplierRegistrationAttempt $attempt)
    {
        try {
            $this->registrationService->requestRevision(
                attempt: $attempt,
                reviewer: $request->user(),
                reason: $request->validated('reason'),
            );

            return redirect()->route('supplier-registrations.show', $attempt->hash)
                ->with('success', __('Revision requested successfully.'));
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Reject the registration attempt.
     */
    public function reject(RejectRegistrationRequest $request, SupplierRegistrationAttempt $attempt)
    {
        try {
            $this->registrationService->rejectRegistration(
                attempt: $attempt,
                reviewer: $request->user(),
                reason: $request->validated('reason'),
            );

            return redirect()->route('supplier-registrations.show', $attempt->hash)
                ->with('success', __('Supplier registration has been rejected.'));
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Atomically approve the registration attempt and assign scope.
     */
    public function approve(ApproveRegistrationRequest $request, SupplierRegistrationAttempt $attempt)
    {
        try {
            $this->registrationService->approveRegistration(
                attempt: $attempt,
                reviewer: $request->user(),
                scopes: $request->validated('scopes'),
                notes: $request->validated('notes'),
            );

            return redirect()->route('supplier-registrations.show', $attempt->hash)
                ->with('success', __('Supplier registration has been approved, activated, and assigned scope successfully.'));
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Securely download registration document for reviewer.
     */
    public function downloadDocument(SupplierRegistrationAttempt $attempt, SupplierMasterDocument $document)
    {
        if ((int) $document->supplier_id !== (int) $attempt->user_id) {
            abort(403, 'Unauthorized access to document.');
        }

        if (! Storage::disk('private')->exists($document->file_path)) {
            abort(404, 'Document file not found.');
        }

        return Storage::disk('private')->download($document->file_path, $document->original_filename);
    }
}
