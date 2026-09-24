<?php

namespace App\Services;

use App\Models\Supplier;
use App\Models\SupplierBankAccount;
use App\Models\SupplierMasterDocument;
use App\Models\SupplierRegistrationAccess;
use App\Models\SupplierRegistrationAttempt;
use App\Models\SupplierRegistrationAudit;
use App\Models\User;
use App\Support\NotificationCategory;
use App\Support\NotificationDomain;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SupplierRegistrationService
{
    public function __construct(
        protected NotificationService $notificationService,
    ) {}

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
     * Throws generic ValidationException without disclosing existing account ownership.
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
                    throw ValidationException::withMessages([
                        'email' => __('The provided registration details cannot be processed. Please verify your information or contact support.'),
                    ]);
                }
            } else {
                if (! in_array($existingUser->account_status, [User::ACCOUNT_STATUS_REJECTED, User::ACCOUNT_STATUS_REVISION], true)) {
                    throw ValidationException::withMessages([
                        'email' => __('The provided registration details cannot be processed. Please verify your information or contact support.'),
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
                throw ValidationException::withMessages([
                    'nib' => __('The provided registration details cannot be processed. Please verify your information or contact support.'),
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
                throw ValidationException::withMessages([
                    'npwp' => __('The provided registration details cannot be processed. Please verify your information or contact support.'),
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
        return DB::transaction(function () use ($data, $files, $ip, $userAgent) {
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
                    throw ValidationException::withMessages([
                        'email' => __('The provided registration details cannot be processed. Please verify your information or contact support.'),
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
            $documentMetadata = $this->storeRegistrationDocuments($user, $bankAccount, $files);

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
            $ttlDays = (int) config('supplier_registration.access_token_ttl_days', 30);

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
                title: 'New Supplier Registration',
                message: "Supplier {$data['company_name']} has submitted registration {$reference}.",
                url: route('supplier-registrations.show', $attempt->hash),
                attempt: $attempt,
            );

            return [
                'attempt' => $attempt,
                'reference' => $reference,
                'access_key' => $plainAccessKey,
            ];
        });
    }

    /**
     * Resubmit a registration that was in REVISION status.
     *
     * @return array{attempt: SupplierRegistrationAttempt, reference: string}
     */
    public function resubmitRevision(User $user, array $data, array $files, ?string $ip = null, ?string $userAgent = null): array
    {
        return DB::transaction(function () use ($user, $data, $files, $ip, $userAgent) {
            $user = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            if ($user->account_status !== User::ACCOUNT_STATUS_REVISION) {
                throw new \DomainException(__('Only registrations in revision status can be resubmitted.'));
            }

            $lastAttempt = $user->registrationAttempts()->orderByDesc('attempt_number')->lockForUpdate()->first();
            if (! $lastAttempt || $lastAttempt->status !== SupplierRegistrationAttempt::STATUS_REVISION) {
                throw new \DomainException(__('Active attempt is not in revision status.'));
            }

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
            $documentMetadata = $this->storeRegistrationDocuments($user, $bankAccount, $files, preserveExisting: true);

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
                    'expires_at' => now()->addDays((int) config('supplier_registration.access_token_ttl_days', 30)),
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
                title: 'Supplier Registration Resubmitted',
                message: "Supplier {$data['company_name']} has resubmitted their registration with revisions.",
                url: route('supplier-registrations.show', $newAttempt->hash),
                attempt: $newAttempt,
            );

            return [
                'attempt' => $newAttempt,
                'reference' => $access?->registration_reference ?? '',
            ];
        });
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
                throw new \DomainException(__('Only pending registrations can be requested for revision.'));
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
                title: 'Supplier Registration Revision Requested',
                message: "Revision requested for supplier {$user->name} by {$reviewer->name}.",
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
                throw new \DomainException(__('This registration cannot be rejected in its current status.'));
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
                title: 'Supplier Registration Rejected',
                message: "Registration for supplier {$user->name} was rejected by {$reviewer->name}.",
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
                throw new \DomainException(__('Only pending registrations can be approved.'));
            }

            $validScopes = array_values(array_intersect(['import', 'local'], $scopes));
            if (empty($validScopes)) {
                throw ValidationException::withMessages([
                    'scopes' => __('At least one valid supplier scope (import or local) must be assigned upon approval.'),
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
                title: 'Supplier Registration Approved',
                message: "Supplier {$user->name} has been approved with scope(s): ".implode(', ', $validScopes).'.',
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

        $access = SupplierRegistrationAccess::query()
            ->with(['user'])
            ->where('registration_reference', $reference)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $access) {
            return null;
        }

        $providedHash = hash('sha256', $key);
        if (! hash_equals($access->token_hash, $providedHash)) {
            return null;
        }

        $access->update(['last_used_at' => now()]);

        return $access;
    }

    /**
     * Store registration document uploads safely on the private disk.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function storeRegistrationDocuments(User $user, SupplierBankAccount $bankAccount, array $files, bool $preserveExisting = false): array
    {
        $metadata = [];
        $allowedTypes = [
            'nib_file' => SupplierMasterDocument::TYPE_NIB,
            'npwp_file' => SupplierMasterDocument::TYPE_NPWP,
            'sknr_file' => SupplierMasterDocument::TYPE_SURAT_PERNYATAAN_REKENING,
            'sppkp_file' => SupplierMasterDocument::TYPE_SPPKP,
            'skd_file' => SupplierMasterDocument::TYPE_SKD,
        ];

        foreach ($allowedTypes as $inputKey => $docType) {
            /** @var UploadedFile|null $file */
            $file = $files[$inputKey] ?? null;

            if ($file instanceof UploadedFile && $file->isValid()) {
                $ext = strtolower($file->getClientOriginalExtension());
                $randomName = Str::random(40).'.'.$ext;
                $storageDir = 'attachments/supplier-registrations/'.now()->format('Y/m');
                $relativePath = $storageDir.'/'.$randomName;

                // Windows-safe stream upload to private disk
                $stream = fopen($file->getPathname(), 'r');
                try {
                    Storage::disk('private')->put($relativePath, $stream);
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }

                $bankAccountId = ($docType === SupplierMasterDocument::TYPE_SURAT_PERNYATAAN_REKENING)
                    ? $bankAccount->id
                    : null;

                $doc = SupplierMasterDocument::create([
                    'supplier_id' => $user->id,
                    'supplier_bank_account_id' => $bankAccountId,
                    'document_type' => $docType,
                    'file_path' => $relativePath,
                    'original_filename' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                    'file_size' => $file->getSize(),
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
                // Keep the most recent document metadata if not re-uploaded
                $existingDoc = SupplierMasterDocument::where('supplier_id', $user->id)
                    ->where('document_type', $docType)
                    ->latest()
                    ->first();

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
        $year = now()->format('Y');

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
        );
    }
}
