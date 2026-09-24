<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SupplierRegistrationRequest;
use App\Http\Requests\SupplierRegistration\ResubmitRegistrationRequest;
use App\Models\SupplierMasterDocument;
use App\Models\SupplierRegistrationAccess;
use App\Models\SupplierRegistrationAttempt;
use App\Services\Auth\TurnstileVerifier;
use App\Services\SupplierRegistrationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SupplierRegistrationController extends Controller
{
    public function __construct(
        protected SupplierRegistrationService $registrationService,
    ) {}

    /**
     * Show public supplier self-registration form.
     */
    public function create()
    {
        return view('auth.supplier-register');
    }

    /**
     * Handle public supplier self-registration submission.
     */
    public function store(
        SupplierRegistrationRequest $request,
        TurnstileVerifier $turnstile,
    ) {
        $request->validateTurnstile($turnstile);

        $safeData = $request->safeRegistrationData();
        $files = $request->allFiles();

        $result = $this->registrationService->submitInitialRegistration(
            data: $safeData,
            files: $files,
            ip: $request->ip(),
            userAgent: $request->userAgent(),
        );

        // Flash credentials for one-time display on success page
        return redirect()->route('supplier.registration.success')->with([
            'registration_reference' => $result['reference'],
            'registration_access_key' => $result['access_key'],
            'company_name' => $safeData['company_name'],
        ]);
    }

    /**
     * Show registration success page with one-time credentials.
     */
    public function success()
    {
        if (! session()->has('registration_reference') || ! session()->has('registration_access_key')) {
            return redirect()->route('supplier.registration.access-form')
                ->with('info', __('If you have already submitted your registration, please check your status here.'));
        }

        return view('auth.supplier-register-success', [
            'reference' => session('registration_reference'),
            'accessKey' => session('registration_access_key'),
            'companyName' => session('company_name'),
        ]);
    }

    /**
     * Show registration access / status login form.
     */
    public function showAccessForm()
    {
        return view('auth.supplier-registration-access');
    }

    /**
     * Authenticate applicant with registration reference + access key.
     */
    public function authenticateAccess(Request $request)
    {
        $request->validate([
            'reference' => ['required', 'string', 'max:50'],
            'access_key' => ['required', 'string', 'max:100'],
        ]);

        $ip = $request->ip();
        $throttleKey = 'reg_access:'.Str::lower(trim($request->reference)).'|'.$ip;

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return back()->withInput()->with('error', __("Too many attempts. Please try again in {$seconds} seconds."));
        }

        $access = $this->registrationService->authenticateAccess(
            reference: $request->input('reference'),
            key: $request->input('access_key'),
        );

        if (! $access) {
            RateLimiter::hit($throttleKey, 60);

            return back()->withInput()->with('error', __('Invalid registration reference or access key.'));
        }

        RateLimiter::clear($throttleKey);

        // Establish isolated registration session (never Auth::login)
        $request->session()->regenerate();
        $request->session()->put('registration_access_id', $access->id);
        $request->session()->put('registration_user_id', $access->user_id);

        return redirect()->route('supplier.registration.status');
    }

    /**
     * Display registration status and attempt history.
     */
    public function status(Request $request)
    {
        /** @var SupplierRegistrationAccess $access */
        $access = $request->attributes->get('registrationAccess');
        $user = $access->user->load([
            'supplier',
            'supplierBankAccounts',
            'supplierMasterDocuments',
            'registrationAttempts' => fn ($q) => $q->orderByDesc('attempt_number'),
        ]);

        $latestAttempt = $user->registrationAttempts->first();

        return view('auth.supplier-registration-status', [
            'access' => $access,
            'user' => $user,
            'supplier' => $user->supplier,
            'latestAttempt' => $latestAttempt,
            'attempts' => $user->registrationAttempts,
        ]);
    }

    /**
     * Show form to edit registration data during REVISION.
     */
    public function edit(Request $request)
    {
        /** @var SupplierRegistrationAccess $access */
        $access = $request->attributes->get('registrationAccess');
        $user = $access->user->load(['supplier', 'supplierBankAccounts', 'supplierMasterDocuments']);
        $latestAttempt = $user->registrationAttempts()->orderByDesc('attempt_number')->first();

        if (! $latestAttempt || $latestAttempt->status !== SupplierRegistrationAttempt::STATUS_REVISION) {
            return redirect()->route('supplier.registration.status')
                ->with('info', __('Your registration is not currently open for revision.'));
        }

        return view('auth.supplier-registration-edit', [
            'access' => $access,
            'user' => $user,
            'supplier' => $user->supplier,
            'bankAccount' => $user->supplierBankAccounts()->latest()->first(),
            'attempt' => $latestAttempt,
            'documents' => $user->supplierMasterDocuments->keyBy('document_type'),
        ]);
    }

    /**
     * Resubmit registration with revised details and documents.
     */
    public function resubmit(ResubmitRegistrationRequest $request)
    {
        /** @var SupplierRegistrationAccess $access */
        $access = $request->attributes->get('registrationAccess');
        $user = $access->user;

        $safeData = $request->safeResubmitData();
        $files = $request->allFiles();

        $this->registrationService->resubmitRevision(
            user: $user,
            data: $safeData,
            files: $files,
            ip: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return redirect()->route('supplier.registration.status')
            ->with('success', __('Your revised registration has been submitted successfully for review.'));
    }

    /**
     * Download registration document for applicant within valid session.
     */
    public function downloadDocument(Request $request, SupplierMasterDocument $document)
    {
        /** @var SupplierRegistrationAccess $access */
        $access = $request->attributes->get('registrationAccess');

        if ((int) $document->supplier_id !== (int) $access->user_id) {
            abort(403, 'Unauthorized access to document.');
        }

        if (! Storage::disk('private')->exists($document->file_path)) {
            abort(404, 'Document file not found.');
        }

        return Storage::disk('private')->download($document->file_path, $document->original_filename);
    }

    /**
     * Terminate the registration workflow session.
     */
    public function logoutAccess(Request $request)
    {
        $request->session()->forget(['registration_access_id', 'registration_user_id']);

        return redirect()->route('supplier.registration.access-form')
            ->with('info', __('You have exited your registration session.'));
    }
}
