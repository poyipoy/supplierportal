<?php

namespace App\Http\Controllers\Auth;

use App\Enums\TurnstileStatus;
use App\Events\AuthSecurityEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SupplierRegistrationRequest;
use App\Http\Requests\SupplierRegistration\RegistrationCredentialAccessRequest;
use App\Http\Requests\SupplierRegistration\ResubmitRegistrationRequest;
use App\Models\SupplierMasterDocument;
use App\Models\SupplierRegistrationAccess;
use App\Models\SupplierRegistrationAttempt;
use App\Services\Auth\LoginRateLimiter;
use App\Services\Auth\TurnstileVerifier;
use App\Services\FileSecurity\FileAccessGuard;
use App\Services\SupplierRegistrationService;
use App\Support\BusinessTime;
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
        $flash = [
            'registration_reference' => $result['reference'],
            'registration_access_key' => $result['access_key'],
            'company_name' => $safeData['company_name'],
            'registration_submitted_at' => BusinessTime::now()->toIso8601String(),
        ];

        // Async (file-preserving) submit: credentials stay in the session flash, never in the JSON body.
        if ($request->expectsJson()) {
            foreach ($flash as $key => $value) {
                $request->session()->flash($key, $value);
            }

            return response()->json(['redirect' => route('supplier.registration.success')]);
        }

        return redirect()->route('supplier.registration.success')->with($flash);
    }

    /**
     * Show registration success page with one-time credentials.
     */
    public function success()
    {
        if (! session()->has('registration_reference') || ! session()->has('registration_access_key')) {
            return redirect()->route('supplier.registration.access-form')
                ->with('info', __('registration.feedback.check_status'));
        }

        return view('auth.supplier-register-success', [
            'reference' => session('registration_reference'),
            'accessKey' => session('registration_access_key'),
            'companyName' => session('company_name'),
            'submittedAt' => BusinessTime::format(session('registration_submitted_at') ?? BusinessTime::now()),
        ]);
    }

    /**
     * Show registration access / status login form.
     */
    public function showAccessForm()
    {
        return view('auth.supplier-registration-access', [
            'turnstileRequired' => (bool) session('auth_turnstile_required', false),
            'turnstileSiteKey' => config('auth_security.turnstile.site_key'),
        ]);
    }

    /**
     * Authenticate applicant with registered email + password (lost access key fallback).
     *
     * Mirrors the normal sign-in defences (shared rate-limit budget, Turnstile, constant-time
     * check, tarpit, audit) but only opens the isolated registration session.
     */
    public function authenticateCredentials(
        RegistrationCredentialAccessRequest $request,
        LoginRateLimiter $limiter,
        TurnstileVerifier $turnstile,
    ) {
        $email = $limiter->normalizedEmail($request->string('email')->toString());
        $limiter->ensureNotLimited($request, $email);

        $routeMeta = ['route' => 'supplier.registration.access.credentials'];

        if ($limiter->requiresTurnstile($request, $email) && $turnstile->configured()) {
            if ($turnstile->verify($request) === TurnstileStatus::Invalid) {
                $limiter->hit($request, $email);
                event(new AuthSecurityEvent('captcha_failed', email: $email, metadata: $routeMeta));
                $this->applyTarpit($limiter, $request, $email);

                return $this->credentialFailure($email, true);
            }
        }

        $access = $this->registrationService->authenticateWithCredentials(
            $email,
            $request->string('password')->toString(),
        );

        if (! $access) {
            $limiter->hit($request, $email);
            event(new AuthSecurityEvent('login_failed', email: $email, metadata: $routeMeta));
            $this->applyTarpit($limiter, $request, $email);

            return $this->credentialFailure($email, $limiter->requiresTurnstile($request, $email));
        }

        $limiter->clearAfterSuccess($request, $email);

        // Isolated registration session only (never Auth::login)
        $request->session()->regenerate();
        $request->session()->put('registration_access_id', $access->id);
        $request->session()->put('registration_user_id', $access->user_id);

        return redirect()->route('supplier.registration.status');
    }

    private function credentialFailure(string $email, bool $turnstileRequired)
    {
        return back()
            ->withInput(['email' => $email])
            ->with('access_tab', 'credentials')
            ->with('auth_turnstile_required', $turnstileRequired)
            ->withErrors(['email' => __('registration.feedback.invalid_credentials')], 'credentials');
    }

    private function applyTarpit(LoginRateLimiter $limiter, Request $request, string $email): void
    {
        $tarpit = config('auth_security.login.tarpit');

        if (! ($tarpit['enabled'] ?? false)) {
            return;
        }

        $failures = $limiter->currentFailureCount($request, $email);
        $threshold = (int) ($tarpit['threshold'] ?? 3);

        if ($failures < $threshold) {
            return;
        }

        $delayMs = min(
            ($failures - $threshold + 1) * (int) ($tarpit['delay_step_ms'] ?? 1000),
            (int) ($tarpit['max_delay_ms'] ?? 2000),
        );

        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }
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

            return back()->withInput()->with('error', __('registration.feedback.too_many', ['seconds' => $seconds]));
        }

        $access = $this->registrationService->authenticateAccess(
            reference: $request->input('reference'),
            key: $request->input('access_key'),
        );

        if (! $access) {
            RateLimiter::hit($throttleKey, 60);

            return back()->withInput()->with('error', __('registration.feedback.invalid_access'));
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
                ->with('info', __('registration.feedback.not_revision'));
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

        if ($request->expectsJson()) {
            $request->session()->flash('success', __('registration.feedback.resubmitted'));

            return response()->json(['redirect' => route('supplier.registration.status')]);
        }

        return redirect()->route('supplier.registration.status')
            ->with('success', __('registration.feedback.resubmitted'));
    }

    /**
     * Download registration document for applicant within valid session.
     */
    public function downloadDocument(Request $request, SupplierMasterDocument $document)
    {
        /** @var SupplierRegistrationAccess $access */
        $access = $request->attributes->get('registrationAccess');

        if ((int) $document->supplier_id !== (int) $access->user_id) {
            abort(403, __('registration.feedback.document_unauthorized'));
        }

        if (! Storage::disk('private')->exists($document->file_path)) {
            abort(404, __('registration.feedback.document_missing'));
        }

        app(FileAccessGuard::class)->assertReadable($document);

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

    /**
     * Terminate the registration workflow session.
     */
    public function logoutAccess(Request $request)
    {
        $request->session()->forget(['registration_access_id', 'registration_user_id']);

        return redirect()->route('supplier.registration.access-form')
            ->with('info', __('registration.feedback.exited'));
    }
}
