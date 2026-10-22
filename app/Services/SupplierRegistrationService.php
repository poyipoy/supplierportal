<?php

namespace App\Services;

use App\Exceptions\DuplicateSupplierRegistration;
use App\Models\Supplier;
use App\Models\SupplierBankAccount;
use App\Models\SupplierMasterDocument;
use App\Models\SupplierRegistrationAccess;
use App\Models\SupplierRegistrationAttempt;
use App\Models\SupplierRegistrationAudit;
use App\Models\User;
use App\Services\FileSecurity\FileAccessGuard;
use App\Services\FileSecurity\FileInspectionService;
use App\Support\BusinessTime;
use App\Support\NotificationCategory;
use App\Support\NotificationDomain;
use App\Support\SupplierComplianceQuestionnaire;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SupplierRegistrationService
{
    private const DOCUMENT_TYPES = [
        'nib_file' => SupplierMasterDocument::TYPE_NIB,
        'npwp_file' => SupplierMasterDocument::TYPE_NPWP,
        'sknr_file' => SupplierMasterDocument::TYPE_SURAT_PERNYATAAN_REKENING,
        'sppkp_file' => SupplierMasterDocument::TYPE_SPPKP,
        'skd_file' => SupplierMasterDocument::TYPE_SKD,
        'company_profile_file' => SupplierMasterDocument::TYPE_COMPANY_PROFILE,
    ];

    public function __construct(
        protected NotificationService $notificationService,
    ) {}

    /** Attempts after the last rejection belong to the applicant's current cycle. */
    public function currentCycleAttempts(User $user): Collection
    {
        $lastRejected = $user->registrationAttempts()
            ->where('status', SupplierRegistrationAttempt::STATUS_REJECTED)
            ->max('attempt_number') ?? 0;

        return $user->registrationAttempts()->where('attempt_number', '>', $lastRejected)
            ->orderByDesc('attempt_number')->get();
    }

    public function currentCycleDocuments(User $user, ?Collection $attempts = null): Collection
    {
        $ids = ($attempts ?? $this->currentCycleAttempts($user))
            ->flatMap(fn (SupplierRegistrationAttempt $attempt) => collect($attempt->submission_snapshot['documents'] ?? [])->pluck('document_id'))
            ->map(fn ($id) => (int) $id)->filter(fn ($id) => $id > 0)->unique()->values()->all();

        return $user->supplierMasterDocuments()->whereKey($ids)->orderBy('id')->get();
    }

    public function hasReusableSppkp(?User $user): bool
    {
        $document = $user ? $this->currentCycleDocuments($user)->where('document_type', SupplierMasterDocument::TYPE_SPPKP)->last() : null;
        if (! $document) {
            return false;
        }

        try {
            app(FileAccessGuard::class)->assertReadable($document);

            return true;
        } catch (HttpException) {
            return false;
        }
    }

    private function assertPkpDocument(array $data, array $files, ?User $user = null): void
    {
        if (empty($data['is_pkp'])) {
            return;
        }

        $file = $files['sppkp_file'] ?? null;
        if ($file instanceof UploadedFile && $file->isValid()) {
            return;
        }

        if ($file !== null || ! $this->hasReusableSppkp($user)) {
            throw ValidationException::withMessages(['sppkp_file' => __('registration.sppkp_required')]);
        }
    }

    /**
     * Compute normalized SHA-256 fingerprint for identity uniqueness.
     */
    public function normalizeFingerprint(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $normalized = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $value) ?? '');

        return $normalized === '' ? null : hash('sha256', $normalized);
    }

    /**
     * Check duplicate identities against active/in-progress suppliers.
     * Reject duplicate identities on the matching input field.
     */
    public function checkDuplicates(string $email, string $nib, string $npwp, ?int $ignoreUserId = null): void
    {
        $normalizedEmail = Str::lower(trim($email));

        $existingUser = User::query()
            ->where('email', $normalizedEmail)
            ->first();

        if ($existingUser) {
            $isSameUser = $ignoreUserId && (int) $existingUser->id === (int) $ignoreUserId;
            if (! $isSameUser) {
                if ($existingUser->account_status !== User::ACCOUNT_STATUS_REJECTED) {
                    throw DuplicateSupplierRegistration::withMessages([
                        'email' => __('registration.duplicate.email'),
                    ]);
                }
            } else {
                if (! in_array($existingUser->account_status, [User::ACCOUNT_STATUS_REJECTED, User::ACCOUNT_STATUS_REVISION], true)) {
                    throw DuplicateSupplierRegistration::withMessages([
                        'email' => __('registration.duplicate.email'),
                    ]);
                }
            }
        }

        $nibFingerprint = $this->normalizeFingerprint($nib);
        if ($nibFingerprint) {
            $existingNib = Supplier::query()
                ->where('nib_fingerprint', $nibFingerprint)
                ->when($ignoreUserId, fn ($q) => $q->where('user_id', '!=', $ignoreUserId))
                ->exists();

            if ($existingNib) {
                throw DuplicateSupplierRegistration::withMessages([
                    'nib' => __('registration.duplicate.nib'),
                ]);
            }
        }

        $taxFingerprint = $this->normalizeFingerprint($npwp);
        if ($taxFingerprint) {
            $existingNpwp = Supplier::query()
                ->where('tax_identity_fingerprint', $taxFingerprint)
                ->when($ignoreUserId, fn ($q) => $q->where('user_id', '!=', $ignoreUserId))
                ->exists();

            if ($existingNpwp) {
                throw DuplicateSupplierRegistration::withMessages([
                    'npwp' => __('registration.duplicate.npwp'),
                ]);
            }
        }
    }

    /**
     * Submit an initial public supplier registration.
     *
     * @return array{attempt: SupplierRegistrationAttempt, reference: string, access_key: string}
     */
    public function submitInitialRegistration(array $data, array $files, ?string $ip = null, ?string $userAgent = null): array
    {
        $this->assertPkpDocument($data, $files);
        // File errors must be identical whether or not an identity already exists.
        foreach (self::DOCUMENT_TYPES as $inputKey => $documentType) {
            if (($files[$inputKey] ?? null) instanceof UploadedFile) {
                app(FileInspectionService::class)->inspectUpload(
                    $files[$inputKey], $inputKey === 'company_profile_file' ? 'registration_company' : 'registration', $inputKey
                );
            }
        }

        $written = [];
        try {
            return DB::transaction(function () use ($data, $files, $ip, $userAgent, &$written) {
                // A deadlock retry starts after rollback; remove files from the rolled-back attempt.
                foreach ($written as $path) {
                    Storage::disk('private')->delete($path);
                }
                $written = [];
                $normalizedEmail = Str::lower(trim($data['email']));
                $nibFingerprint = $this->normalizeFingerprint($data['nib']);
                $taxFingerprint = $this->normalizeFingerprint($data['npwp']);

                // Lock potential existing user for safe re-registration if rejected
                $existingUser = User::query()
                    ->where('email', $normalizedEmail)
                    ->lockForUpdate()
                    ->first();

                if ($existingUser) {
                    if ($existingUser->account_status !== User::ACCOUNT_STATUS_REJECTED) {
                        throw DuplicateSupplierRegistration::withMessages([
                            'email' => __('registration.duplicate.email'),
                        ]);
                    }
                    $this->checkDuplicates($data['email'], $data['nib'], $data['npwp'], $existingUser->id);
                } else {
                    $this->checkDuplicates($data['email'], $data['nib'], $data['npwp'], null);
                }

                if ($existingUser && $existingUser->account_status === User::ACCOUNT_STATUS_REJECTED) {
                    $user = $existingUser;
                    $user->update([
                        'name' => $data['company_name'],
                        'password' => Hash::make($data['password']),
                        'account_status' => User::ACCOUNT_STATUS_PENDING,
                        'is_active' => false,
                    ]);
                } else {
                    $user = User::create([
                        'name' => $data['company_name'],
                        'email' => $normalizedEmail,
                        'password' => Hash::make($data['password']),
                        'role' => 'supplier',
                        'is_active' => false,
                        'account_status' => User::ACCOUNT_STATUS_PENDING,
                    ]);
                }

                // Create or update Supplier master
                $supplier = Supplier::updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'company_title' => $data['company_title'] ?? null,
                        'company_name' => $data['company_name'],
                        'address' => $data['address'],
                        'phone' => $data['phone'],
                        'nib' => $data['nib'],
                        'nib_fingerprint' => $nibFingerprint,
                        'npwp' => $data['npwp'],
                        'tax_identity_fingerprint' => $taxFingerprint,
                        'category' => $data['category'] ?? 'Supplier',
                        'vendor_category' => $data['vendor_category'] ?? null,
                        'is_pkp' => ! empty($data['is_pkp']),
                        'pic_name' => $data['pic_name'],
                        'pic_email' => $data['pic_email'],
                        'pic_phone' => $data['pic_phone'],
                        'compliance_questionnaire' => SupplierComplianceQuestionnaire::payload($data['questionnaire'] ?? []),
                    ]
                );

                // Bank Account
                $bankAccount = SupplierBankAccount::create([
                    'supplier_id' => $user->id,
                    'bank_name' => $data['bank_name'],
                    'account_number' => $data['account_number'],
                    'account_holder_name' => $data['account_holder_name'],
                    'status' => SupplierBankAccount::STATUS_PENDING,
                ]);

                // Save Documents
                $documentMetadata = $this->storeRegistrationDocuments($user, $bankAccount, $files, written: $written);

                // Determine attempt number
                $previousAttemptCount = SupplierRegistrationAttempt::where('user_id', $user->id)->count();
                $attemptNumber = $previousAttemptCount + 1;

                // Build submission snapshot (strictly excluding sensitive credentials)
                $snapshot = [
                    'company' => [
                        'company_title' => $data['company_title'] ?? null,
                        'company_name' => $data['company_name'],
                        'address' => $data['address'],
                        'phone' => $data['phone'],
                        'category' => $data['category'] ?? 'Supplier',
                        'vendor_category' => $data['vendor_category'] ?? null,
                        'is_pkp' => ! empty($data['is_pkp']),
                    ],
                    'pic' => [
                        'pic_name' => $data['pic_name'],
                        'pic_email' => $data['pic_email'],
                        'pic_phone' => $data['pic_phone'],
                    ],
                    'tax' => [
                        'nib' => $data['nib'],
                        'npwp' => $data['npwp'],
                    ],
                    'bank' => [
                        'bank_name' => $data['bank_name'],
                        'account_number' => $data['account_number'],
                        'account_holder_name' => $data['account_holder_name'],
                    ],
                    'documents' => $documentMetadata,
                    'questionnaire' => SupplierComplianceQuestionnaire::normalize($data['questionnaire'] ?? []),
                ];

                $checksum = hash('sha256', json_encode($snapshot));

                $attempt = SupplierRegistrationAttempt::create([
                    'user_id' => $user->id,
                    'attempt_number' => $attemptNumber,
                    'status' => SupplierRegistrationAttempt::STATUS_PENDING,
                    'submission_snapshot' => $snapshot,
                    'submission_checksum' => $checksum,
                    'submitted_at' => now(),
                ]);

                // Generate registration access credentials
                $reference = $this->generateRegistrationReference();
                $plainAccessKey = Str::random(32);
                $tokenHash = hash('sha256', $plainAccessKey);
                $ttlDays = $this->accessTtlDays();

                $access = SupplierRegistrationAccess::updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'registration_reference' => $reference,
                        'token_hash' => $tokenHash,
                        'issued_at' => now(),
                        'expires_at' => now()->addDays($ttlDays),
                        'revoked_at' => null,
                        'last_used_at' => null,
                    ]
                );

                // Record audit trail
                SupplierRegistrationAudit::record(
                    attemptId: $attempt->id,
                    userId: $user->id,
                    event: 'registration_submitted',
                    actorId: $user->id,
                    actorRole: 'applicant',
                    notes: 'Initial supplier registration submitted.',
                    metadata: [
                        'ip' => $ip,
                        'user_agent' => $userAgent,
                        'reference' => $reference,
                        'attempt_number' => $attemptNumber,
                    ],
                );

                // Send notification to reviewers (Admin, Finance, Purchasing)
                $this->notifyReviewers(
                    event: 'supplier_registration.submitted',
                    eventKey: "supplier_reg_{$attempt->id}_submitted",
                    title: 'notifications.registration.submitted.title',
                    message: 'notifications.registration.submitted.message',
                    replace: ['company' => $data['company_name'], 'reference' => $reference],
                    url: route('supplier-registrations.show', $attempt->hash),
                    attempt: $attempt,
                );

                return [
                    'attempt' => $attempt,
                    'reference' => $reference,
                    'access_key' => $plainAccessKey,
                ];
            }, 3);
        } catch (\Throwable $exception) {
            foreach ($written as $path) {
                Storage::disk('private')->delete($path);
            }
            // A concurrent insert may reach the unique constraint after the duplicate check.
            if ($exception instanceof QueryException && (int) ($exception->errorInfo[1] ?? 0) === 1062
                && preg_match('/(users_email_unique|suppliers_nib_fingerprint_unique|suppliers_tax_identity_fingerprint_unique)/', $exception->getMessage(), $matches)) {
                $field = match ($matches[1]) {
                    'users_email_unique' => 'email',
                    'suppliers_nib_fingerprint_unique' => 'nib',
                    'suppliers_tax_identity_fingerprint_unique' => 'npwp',
                };
                throw DuplicateSupplierRegistration::withMessages([
                    $field => __('registration.duplicate.'.$field),
                ]);
            }
            throw $exception;
        }
    }

    /**
     * Resubmit a registration that was in REVISION status.
     *
     * @return array{attempt: SupplierRegistrationAttempt, reference: string}
     */
    public function resubmitRevision(User $user, array $data, array $files, ?string $ip = null, ?string $userAgent = null, ?string $registrationReference = null): array
    {
        $written = [];
        try {
            return DB::transaction(function () use ($user, $data, $files, $ip, $userAgent, $registrationReference, &$written) {
                $user = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

                if ($registrationReference !== null) {
                    $access = SupplierRegistrationAccess::where('user_id', $user->id)
                        ->where('registration_reference', $registrationReference)->lockForUpdate()->first();
                    if (! $access?->isValid()) {
                        throw ValidationException::withMessages(['registration' => __('auth.registration_session.expired')]);
                    }
                }

                if ($user->account_status !== User::ACCOUNT_STATUS_REVISION) {
                    throw new \DomainException(__('local_procurement.registration.resubmit_revision'));
                }

                $lastAttempt = $user->registrationAttempts()->orderByDesc('attempt_number')->lockForUpdate()->first();
                if (! $lastAttempt || $lastAttempt->status !== SupplierRegistrationAttempt::STATUS_REVISION) {
                    throw new \DomainException(__('local_procurement.registration.attempt_revision'));
                }

                $this->assertPkpDocument($data, $files, $user);

                $this->checkDuplicates($data['email'] ?? $user->email, $data['nib'], $data['npwp'], $user->id);

                $nibFingerprint = $this->normalizeFingerprint($data['nib']);
                $taxFingerprint = $this->normalizeFingerprint($data['npwp']);

                // Update Supplier master record
                $user->supplier()->update([
                    'company_title' => $data['company_title'] ?? null,
                    'company_name' => $data['company_name'],
                    'address' => $data['address'],
                    'phone' => $data['phone'],
                    'nib' => $data['nib'],
                    'nib_fingerprint' => $nibFingerprint,
                    'npwp' => $data['npwp'],
                    'tax_identity_fingerprint' => $taxFingerprint,
                    'category' => $data['category'] ?? 'Supplier',
                    'vendor_category' => $data['vendor_category'] ?? null,
                    'is_pkp' => ! empty($data['is_pkp']),
                    'pic_name' => $data['pic_name'],
                    'pic_email' => $data['pic_email'],
                    'pic_phone' => $data['pic_phone'],
                    'compliance_questionnaire' => SupplierComplianceQuestionnaire::payload($data['questionnaire'] ?? []),
                ]);

                // Update Bank Account
                $bankAccount = $user->supplierBankAccounts()->latest()->first();
                if ($bankAccount) {
                    $bankAccount->update([
                        'bank_name' => $data['bank_name'],
                        'account_number' => $data['account_number'],
                        'account_holder_name' => $data['account_holder_name'],
                        'status' => SupplierBankAccount::STATUS_PENDING,
                    ]);
                } else {
                    $bankAccount = SupplierBankAccount::create([
                        'supplier_id' => $user->id,
                        'bank_name' => $data['bank_name'],
                        'account_number' => $data['account_number'],
                        'account_holder_name' => $data['account_holder_name'],
                        'status' => SupplierBankAccount::STATUS_PENDING,
                    ]);
                }

                // Store any updated/replaced documents
                $documentMetadata = $this->storeRegistrationDocuments($user, $bankAccount, $files, preserveExisting: true, written: $written);

                // Create new attempt
                $attemptNumber = $lastAttempt->attempt_number + 1;
                $snapshot = [
                    'company' => [
                        'company_title' => $data['company_title'] ?? null,
                        'company_name' => $data['company_name'],
                        'address' => $data['address'],
                        'phone' => $data['phone'],
                        'category' => $data['category'] ?? 'Supplier',
                        'vendor_category' => $data['vendor_category'] ?? null,
                        'is_pkp' => ! empty($data['is_pkp']),
                    ],
                    'pic' => [
                        'pic_name' => $data['pic_name'],
                        'pic_email' => $data['pic_email'],
                        'pic_phone' => $data['pic_phone'],
                    ],
                    'tax' => [
                        'nib' => $data['nib'],
                        'npwp' => $data['npwp'],
                    ],
                    'bank' => [
                        'bank_name' => $data['bank_name'],
                        'account_number' => $data['account_number'],
                        'account_holder_name' => $data['account_holder_name'],
                    ],
                    'documents' => $documentMetadata,
                    'questionnaire' => SupplierComplianceQuestionnaire::normalize($data['questionnaire'] ?? []),
                    'revision_notes' => $data['revision_notes'] ?? null,
                ];

                $checksum = hash('sha256', json_encode($snapshot));

                $newAttempt = SupplierRegistrationAttempt::create([
                    'user_id' => $user->id,
                    'attempt_number' => $attemptNumber,
                    'status' => SupplierRegistrationAttempt::STATUS_PENDING,
                    'submission_snapshot' => $snapshot,
                    'submission_checksum' => $checksum,
                    'submitted_at' => now(),
                ]);

                // Update user status to PENDING
                $user->update(['account_status' => User::ACCOUNT_STATUS_PENDING]);

                // Ensure registration access expiration is renewed for the resubmission
                SupplierRegistrationAccess::query()
                    ->where('user_id', $user->id)
                    ->whereNull('revoked_at')
                    ->update([
                        'expires_at' => now()->addDays($this->accessTtlDays()),
                    ]);

                // Audit
                SupplierRegistrationAudit::record(
                    attemptId: $newAttempt->id,
                    userId: $user->id,
                    event: 'registration_resubmitted',
                    actorId: $user->id,
                    actorRole: 'applicant',
                    notes: 'Registration resubmitted with revisions.',
                    metadata: [
                        'ip' => $ip,
                        'user_agent' => $userAgent,
                        'attempt_number' => $attemptNumber,
                        'previous_attempt_id' => $lastAttempt->id,
                    ],
                );

                // Notify reviewers
                $this->notifyReviewers(
                    event: 'supplier_registration.resubmitted',
                    eventKey: "supplier_reg_{$newAttempt->id}_resubmitted",
                    title: 'notifications.registration.resubmitted.title',
                    message: 'notifications.registration.resubmitted.message',
                    replace: ['company' => $data['company_name']],
                    url: route('supplier-registrations.show', $newAttempt->hash),
                    attempt: $newAttempt,
                );

                return [
                    'attempt' => $newAttempt,
                    'reference' => $access?->registration_reference ?? '',
                ];
            });
        } catch (\Throwable $exception) {
            foreach ($written as $path) {
                Storage::disk('private')->delete($path);
            }
            throw $exception;
        }
    }

    /**
     * Request revision on a pending registration attempt.
     */
    public function requestRevision(SupplierRegistrationAttempt $attempt, User $reviewer, string $reason): SupplierRegistrationAttempt
    {
        return DB::transaction(function () use ($attempt, $reviewer, $reason) {
            $attempt = SupplierRegistrationAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            $user = User::query()->whereKey($attempt->user_id)->lockForUpdate()->firstOrFail();

            if ($attempt->status !== SupplierRegistrationAttempt::STATUS_PENDING) {
                throw new \DomainException(__('local_procurement.registration.request_pending'));
            }

            $attempt->update([
                'status' => SupplierRegistrationAttempt::STATUS_REVISION,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'revision_reason' => $reason,
            ]);

            $user->update(['account_status' => User::ACCOUNT_STATUS_REVISION]);

            SupplierRegistrationAudit::record(
                attemptId: $attempt->id,
                userId: $user->id,
                event: 'revision_requested',
                actorId: $reviewer->id,
                actorRole: $reviewer->role,
                notes: $reason,
                metadata: ['reviewer_name' => $reviewer->name],
            );

            $this->notifyReviewers(
                event: 'supplier_registration.revision_requested',
                eventKey: "supplier_reg_{$attempt->id}_revision_requested",
                title: 'notifications.registration.revision_requested.title',
                message: 'notifications.registration.revision_requested.message',
                replace: ['company' => $user->name, 'reviewer' => $reviewer->name],
                url: route('supplier-registrations.show', $attempt->hash),
                attempt: $attempt,
            );

            return $attempt;
        });
    }

    /**
     * Reject a registration attempt.
     */
    public function rejectRegistration(SupplierRegistrationAttempt $attempt, User $reviewer, string $reason): SupplierRegistrationAttempt
    {
        return DB::transaction(function () use ($attempt, $reviewer, $reason) {
            $attempt = SupplierRegistrationAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            $user = User::query()->whereKey($attempt->user_id)->lockForUpdate()->firstOrFail();

            if (! in_array($attempt->status, [SupplierRegistrationAttempt::STATUS_PENDING, SupplierRegistrationAttempt::STATUS_REVISION], true)) {
                throw new \DomainException(__('local_procurement.registration.reject_status'));
            }

            $attempt->update([
                'status' => SupplierRegistrationAttempt::STATUS_REJECTED,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'rejection_reason' => $reason,
                'rejected_at' => now(),
            ]);

            $user->update([
                'account_status' => User::ACCOUNT_STATUS_REJECTED,
                'is_active' => false,
            ]);

            // Release active uniqueness fingerprints to allow future re-registration
            $user->supplier?->update([
                'nib_fingerprint' => null,
                'tax_identity_fingerprint' => null,
            ]);

            // Revoke active registration credentials
            SupplierRegistrationAccess::query()
                ->where('user_id', $user->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            SupplierRegistrationAudit::record(
                attemptId: $attempt->id,
                userId: $user->id,
                event: 'registration_rejected',
                actorId: $reviewer->id,
                actorRole: $reviewer->role,
                notes: $reason,
                metadata: ['reviewer_name' => $reviewer->name],
            );

            $this->notifyReviewers(
                event: 'supplier_registration.rejected',
                eventKey: "supplier_reg_{$attempt->id}_rejected",
                title: 'notifications.registration.rejected.title',
                message: 'notifications.registration.rejected.message',
                replace: ['company' => $user->name, 'reviewer' => $reviewer->name],
                url: route('supplier-registrations.show', $attempt->hash),
                attempt: $attempt,
            );

            return $attempt;
        });
    }

    /**
     * Atomically approve a pending registration attempt, assign scopes, and activate user.
     *
     * @param  list<string>  $scopes  ('import', 'local', or both)
     */
    public function approveRegistration(SupplierRegistrationAttempt $attempt, User $reviewer, array $scopes, ?string $notes = null): SupplierRegistrationAttempt
    {
        return DB::transaction(function () use ($attempt, $reviewer, $scopes, $notes) {
            $attempt = SupplierRegistrationAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            $user = User::query()->whereKey($attempt->user_id)->lockForUpdate()->firstOrFail();

            if ($attempt->status !== SupplierRegistrationAttempt::STATUS_PENDING) {
                throw new \DomainException(__('local_procurement.registration.approve_pending'));
            }

            $validScopes = array_values(array_intersect(['import', 'local'], $scopes));
            if (empty($validScopes)) {
                throw ValidationException::withMessages([
                    'scopes' => __('local_procurement.registration.scope_required'),
                ]);
            }

            // 1. Transition attempt to APPROVED
            $attempt->update([
                'status' => SupplierRegistrationAttempt::STATUS_APPROVED,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'approved_at' => now(),
            ]);

            // 2. Activate user
            $user->update([
                'account_status' => User::ACCOUNT_STATUS_ACTIVE,
                'is_active' => true,
            ]);

            // 3. Assign scopes
            $user->supplierScopes()->whereNotIn('scope', $validScopes)->delete();
            foreach ($validScopes as $scope) {
                $user->supplierScopes()->firstOrCreate(['scope' => $scope]);
            }
            $user->forgetSupplierScopes();

            // 4. Revoke registration access credential
            SupplierRegistrationAccess::query()
                ->where('user_id', $user->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            // 5. Audit trail
            SupplierRegistrationAudit::record(
                attemptId: $attempt->id,
                userId: $user->id,
                event: 'registration_approved',
                actorId: $reviewer->id,
                actorRole: $reviewer->role,
                notes: $notes,
                metadata: ['reviewer_name' => $reviewer->name, 'scopes' => $validScopes],
            );

            SupplierRegistrationAudit::record(
                attemptId: $attempt->id,
                userId: $user->id,
                event: 'scope_assigned',
                actorId: $reviewer->id,
                actorRole: $reviewer->role,
                metadata: ['scopes' => $validScopes],
            );

            SupplierRegistrationAudit::record(
                attemptId: $attempt->id,
                userId: $user->id,
                event: 'account_activated',
                actorId: $reviewer->id,
                actorRole: $reviewer->role,
            );

            // 6. Notify internal reviewers
            $this->notifyReviewers(
                event: 'supplier_registration.approved',
                eventKey: "supplier_reg_{$attempt->id}_approved",
                title: 'notifications.registration.approved.title',
                message: 'notifications.registration.approved.message',
                replace: ['company' => $user->name],
                localizedReplace: ['scopes' => 'notifications.registration.scopes.'.(count($validScopes) > 1 ? 'both' : $validScopes[0])],
                url: route('supplier-registrations.show', $attempt->hash),
                attempt: $attempt,
            );

            return $attempt;
        });
    }

    /**
     * Authenticate an applicant via registration reference and secret key.
     */
    public function authenticateAccess(string $reference, string $key): ?SupplierRegistrationAccess
    {
        $reference = trim($reference);
        $key = trim($key);

        if ($reference === '' || $key === '') {
            return null;
        }

        return DB::transaction(function () use ($reference, $key) {
            $access = SupplierRegistrationAccess::query()
                ->with(['user', 'latestAttempt'])
                ->where('registration_reference', $reference)
                ->lockForUpdate()
                ->first();

            if (! $access || ! $access->canViewStatus()
                || ! hash_equals($access->token_hash, hash('sha256', $key))) {
                return null;
            }

            $access->update(['last_used_at' => now()]);

            return $access;
        });
    }

    /**
     * Authenticate an applicant via registered email + password so a lost access key
     * does not lock them out of status tracking. Never authenticates the web guard.
     *
     * Pending/revision access must not be revoked. Final decisions allow result-only
     * tracking even though approval/rejection has revoked workflow access.
     */
    public function authenticateWithCredentials(string $email, string $password): ?SupplierRegistrationAccess
    {
        $user = User::query()
            ->where('email', $email)
            ->where('role', 'supplier')
            ->first();

        $latestAttempt = $user?->registrationAttempts()->orderByDesc('attempt_number')->first();

        $eligible = $user instanceof User
            && $latestAttempt instanceof SupplierRegistrationAttempt
            && in_array($latestAttempt->status, [
                SupplierRegistrationAttempt::STATUS_PENDING,
                SupplierRegistrationAttempt::STATUS_REVISION,
                SupplierRegistrationAttempt::STATUS_APPROVED,
                SupplierRegistrationAttempt::STATUS_REJECTED,
            ], true);

        // Constant-time: always run one bcrypt comparison, even for unknown/ineligible accounts.
        $targetHash = $eligible
            ? (string) $user->password
            : (string) config('auth_security.dummy_hash', '$2y$12$e80yq9M2XvH0WJ1aR8P1eeS4xLgXk2.mK6uV4C7W1A4rK5eS8V1ee');

        if (! Hash::check($password, $targetHash) || ! $eligible) {
            return null;
        }

        return DB::transaction(function () use ($user, $latestAttempt) {
            $lockedUser = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $currentAttempt = $lockedUser->registrationAttempts()->orderByDesc('attempt_number')->first();
            if ($currentAttempt?->id !== $latestAttempt->id
                || $currentAttempt->status !== $latestAttempt->status
                || ! hash_equals($user->password, $lockedUser->password)) {
                return null;
            }

            $access = SupplierRegistrationAccess::query()
                ->with(['user', 'latestAttempt'])
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if (! $access || ($access->isRevoked() && ! $access->hasFinalResult())) {
                return null;
            }

            // Password just proved ownership, so an expired (non-revoked) window may be renewed.
            if ($access->isExpired()) {
                $access->expires_at = now()->addDays($this->accessTtlDays());
            }

            $access->last_used_at = now();
            $access->save();

            SupplierRegistrationAudit::record(
                attemptId: $latestAttempt->id,
                userId: $user->id,
                event: 'status_accessed_credentials',
                actorRole: 'applicant',
                metadata: ['via' => 'password'],
            );

            return $access->load('user');
        });
    }

    protected function accessTtlDays(): int
    {
        return (int) config('supplier_registration.access.token_ttl_days', 30);
    }

    /**
     * Store registration document uploads safely on the private disk.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function storeRegistrationDocuments(User $user, SupplierBankAccount $bankAccount, array $files, bool $preserveExisting = false, array &$written = []): array
    {
        $metadata = [];
        $retainedDocuments = $preserveExisting ? $this->currentCycleDocuments($user)->keyBy('document_type') : collect();

        foreach (self::DOCUMENT_TYPES as $inputKey => $docType) {
            /** @var UploadedFile|null $file */
            $file = $files[$inputKey] ?? null;

            if ($file instanceof UploadedFile && $file->isValid()) {
                $ext = strtolower($file->getClientOriginalExtension());
                $randomName = Str::random(40).'.'.$ext;
                $storageDir = 'attachments/supplier-registrations/'.now()->format('Y/m'); // biz-time:ignore storage path
                $relativePath = $storageDir.'/'.$randomName;

                $stored = app(FileInspectionService::class)->storeUpload(
                    $file, $inputKey === 'company_profile_file' ? 'registration_company' : 'registration', $relativePath, $inputKey
                );
                $written[] = $stored['file_path'];

                $bankAccountId = ($docType === SupplierMasterDocument::TYPE_SURAT_PERNYATAAN_REKENING)
                    ? $bankAccount->id
                    : null;

                $doc = SupplierMasterDocument::create([
                    'supplier_id' => $user->id,
                    'supplier_bank_account_id' => $bankAccountId,
                    'document_type' => $docType,
                    'file_path' => $stored['file_path'],
                    'original_filename' => $stored['file_name'],
                    'mime_type' => $stored['file_type'],
                    'file_size' => $stored['file_size'],
                    'file_inspection_id' => $stored['file_inspection_id'],
                    'uploaded_by' => $user->id,
                ]);

                $metadata[$docType] = [
                    'document_id' => $doc->id,
                    'hash' => $doc->hash,
                    'original_filename' => $doc->original_filename,
                    'file_size' => $doc->file_size,
                    'mime_type' => $doc->mime_type,
                    'uploaded_at' => now()->toISOString(),
                ];
            } elseif ($preserveExisting) {
                $existingDoc = $retainedDocuments->get($docType);

                if ($existingDoc) {
                    $metadata[$docType] = [
                        'document_id' => $existingDoc->id,
                        'hash' => $existingDoc->hash,
                        'original_filename' => $existingDoc->original_filename,
                        'file_size' => $existingDoc->file_size,
                        'mime_type' => $existingDoc->mime_type,
                        'uploaded_at' => $existingDoc->created_at->toISOString(),
                    ];
                }
            }
        }

        return $metadata;
    }

    /**
     * Generate unique registration reference.
     */
    protected function generateRegistrationReference(): string
    {
        $year = BusinessTime::now()->format('Y');

        do {
            $random = strtoupper(Str::random(8));
            $reference = "REG-{$year}-{$random}";
        } while (SupplierRegistrationAccess::where('registration_reference', $reference)->exists());

        return $reference;
    }

    /**
     * Notify internal reviewers (Admin, Finance, Purchasing) via NotificationService.
     */
    protected function notifyReviewers(
        string $event,
        string $eventKey,
        string $title,
        string $message,
        string $url,
        SupplierRegistrationAttempt $attempt,
        array $replace = [],
        array $localizedReplace = [],
    ): void {
        $reviewers = User::query()
            ->whereIn('role', ['admin', 'finance', 'purchasing'])
            ->where('is_active', true)
            ->where('account_status', User::ACCOUNT_STATUS_ACTIVE)
            ->get();

        $this->notificationService->send(
            recipients: $reviewers,
            event: $event,
            eventKey: $eventKey,
            title: $title,
            message: $message,
            url: $url,
            icon: 'building',
            data: [
                'attempt_id' => $attempt->id,
                'user_id' => $attempt->user_id,
                'category' => NotificationCategory::OTHER,
                'domain' => NotificationDomain::GLOBAL,
            ],
            replace: $replace,
            localizedReplace: $localizedReplace,
        );
    }
}
