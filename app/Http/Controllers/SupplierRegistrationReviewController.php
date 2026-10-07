<?php

namespace App\Http\Controllers;

use App\Http\Requests\SupplierRegistration\ApproveRegistrationRequest;
use App\Http\Requests\SupplierRegistration\RejectRegistrationRequest;
use App\Http\Requests\SupplierRegistration\RevisionRequest;
use App\Models\SupplierMasterDocument;
use App\Models\SupplierRegistrationAttempt;
use App\Services\SupplierRegistrationService;
use App\Support\BusinessTime;
use App\Support\StatusHelper;
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
                    $tone = StatusHelper::registrationTone($attempt->status);
                    $label = StatusHelper::registrationLabel($attempt->status);

                    return '<span class="ui-status-chip ui-status-chip--'.$tone.'">'.e($label).'</span>';
                })
                ->addColumn('submitted_date', fn ($attempt) => $attempt->submitted_at ? BusinessTime::format($attempt->submitted_at, 'd M Y H:i') : '-')
                ->addColumn('reviewer_name', fn ($attempt) => e($attempt->reviewer?->name ?? '-'))
                ->addColumn('action', function ($attempt) {
                    $url = route('supplier-registrations.show', $attempt->hash);

                    return '<a href="'.$url.'" class="ui-data-action ui-data-action--primary ui-focus-ring">'.e(__('local_procurement.registration.review')).'</a>';
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
                ->with('success', __('local_procurement.registration.revision_requested'));
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', __('local_procurement.registration.unexpected'));
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
                ->with('success', __('local_procurement.registration.rejected_feedback'));
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', __('local_procurement.registration.unexpected'));
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
                ->with('success', __('local_procurement.registration.approved_feedback'));
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', __('local_procurement.registration.unexpected'));
        }
    }

    /**
     * Securely download registration document for reviewer.
     */
    public function downloadDocument(SupplierRegistrationAttempt $attempt, SupplierMasterDocument $document)
    {
        if ((int) $document->supplier_id !== (int) $attempt->user_id) {
            abort(403, __('local_procurement.registration.document_unauthorized'));
        }

        if (! Storage::disk('private')->exists($document->file_path)) {
            abort(404, __('local_procurement.registration.document_missing'));
        }

        return Storage::disk('private')->download(
            $document->file_path,
            $document->original_filename,
            [
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'no-store, private',
                'Pragma' => 'no-cache',
            ]
        );
    }
}
