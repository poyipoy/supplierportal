<?php

namespace App\Services\VendorMaster;

use App\Enums\SupplierChangeRequestStatus;
use App\Events\AuthSecurityEvent;
use App\Models\Supplier;
use App\Models\SupplierChangeRequest;
use App\Models\SupplierMasterDocument;
use App\Models\User;
use App\Services\FileSecurity\FileAccessGuard;
use App\Services\LocalInvoice\LocalFinanceAuditService;
use App\Services\NotificationService;
use App\Support\NotificationDomain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class VendorChangeRequestService
{
    public function __construct(
        protected VendorMasterService $vendorMasterService
    ) {}

    /**
     * Submit a new change request by Supplier.
     */
    public function submitChangeRequest(User $supplierUser, array $proposedData, string $changeType = 'profile'): SupplierChangeRequest
    {
        return DB::transaction(function () use ($supplierUser, $proposedData, $changeType) {
            $supplier = $supplierUser->supplier;
            $activeBank = $supplierUser->activeSupplierBankAccount;

            $currentSnapshot = [
                'company_name' => $supplier?->company_name,
                'address' => $supplier?->address,
                'phone' => $supplier?->phone,
                'npwp' => $supplier?->npwp,
                'category' => $supplier?->category,
                'vendor_category' => $supplier?->vendor_category,
                'is_pkp' => $supplier?->is_pkp,
                'pic_name' => $supplier?->pic_name,
                'pic_email' => $supplier?->pic_email,
                'pic_phone' => $supplier?->pic_phone,
                'payment_term_days' => $supplier?->payment_term_days,
                'bank_name' => $activeBank?->bank_name,
                'account_number' => $activeBank?->account_number,
                'account_holder_name' => $activeBank?->account_holder_name,
            ];

            return SupplierChangeRequest::create([
                'supplier_id' => $supplierUser->id,
                'change_type' => $changeType,
                'current_data_snapshot' => $currentSnapshot,
                'proposed_data' => $proposedData,
                'status' => SupplierChangeRequest::STATUS_PENDING,
                'requested_by' => $supplierUser->id,
                'requested_at' => now(),
            ]);
        });
    }

    /**
     * Contact-person proposal from the Import portal. A newer proposal supersedes
     * the pending one so approval can never apply an outdated contact.
     */
    public function submitContactRequest(User $supplierUser, array $contact): SupplierChangeRequest
    {
        $contact = $this->contactFields($contact);

        return DB::transaction(function () use ($supplierUser, $contact) {
            $user = User::whereKey($supplierUser->id)->lockForUpdate()->firstOrFail();
            if (! $user->isImportEligible()) {
                throw new InvalidArgumentException(__('supplier_contact.validation.import_supplier'));
            }

            $this->cancelPendingContactRequests($user);

            $request = SupplierChangeRequest::create([
                'supplier_id' => $user->id,
                'change_type' => SupplierChangeRequest::TYPE_CONTACT,
                'current_data_snapshot' => $this->contactSnapshot($user->supplier, array_keys($contact)),
                'proposed_data' => $contact,
                'status' => SupplierChangeRequest::STATUS_PENDING,
                'requested_by' => $user->id,
                'requested_at' => now(),
            ]);

            app(NotificationService::class)->send(
                User::where('role', 'purchasing')->where('is_active', true)->get(),
                'supplier_contact.requested',
                'supplier-contact:requested:'.$request->id,
                'supplier_contact.notifications.requested_title',
                'supplier_contact.notifications.requested_body',
                route('purchasing.import-suppliers.show', $user),
                'user',
                ['domain' => NotificationDomain::IMPORT],
                ['company' => $this->companyName($user)],
            );

            return $request;
        });
    }

    /**
     * Direct contact update by Purchasing/Admin, e.g. to fill contacts that were never captured.
     */
    public function updateContact(User $supplierUser, array $contact, User $actor, string $reason): Supplier
    {
        $this->assertContactReviewer($actor);
        $companyName = trim((string) ($contact['company_name'] ?? ''));
        $contact = $this->contactFields($contact);
        if ($companyName !== '') {
            $contact['company_name'] = $companyName;
        }

        return DB::transaction(function () use ($supplierUser, $contact, $actor, $reason) {
            $user = User::whereKey($supplierUser->id)->lockForUpdate()->firstOrFail();
            if (! $user->isImportEligible()) {
                throw new InvalidArgumentException(__('supplier_contact.validation.import_supplier'));
            }

            $this->cancelPendingContactRequests($user);

            $supplier = Supplier::where('user_id', $user->id)->lockForUpdate()->first()
                ?? new Supplier(['user_id' => $user->id, 'company_name' => $user->name]);
            $before = $this->contactSnapshot($supplier->exists ? $supplier : null, array_keys($contact));
            $supplier->fill($contact)->save();

            if (isset($contact['company_name']) && $user->name !== $contact['company_name']) {
                $user->forceFill(['name' => $contact['company_name']])->save();
            }

            app(LocalFinanceAuditService::class)->record($supplier, 'vendor_contact_updated', $actor, $before, $contact, ['reason' => trim($reason)]);

            return $supplier;
        });
    }

    /**
     * Purchasing registers a new Import supplier directly. The account is active at once with
     * only the Import scope, and the supplier must replace the initial password on first login.
     */
    public function registerImportSupplier(User $actor, array $data): User
    {
        $this->assertContactReviewer($actor);

        $user = DB::transaction(function () use ($actor, $data) {
            $companyName = trim((string) $data['company_name']);

            $user = User::create([
                'name' => $companyName,
                'email' => Str::lower(trim((string) $data['email'])),
                'password' => $data['password'],
                'role' => 'supplier',
                'is_active' => true,
                'account_status' => User::ACCOUNT_STATUS_ACTIVE,
                'must_change_password' => true,
            ]);

            $supplier = Supplier::create(['user_id' => $user->id, 'company_name' => $companyName]);
            $user->supplierScopes()->create(['scope' => 'import']);
            $user->forgetSupplierScopes();

            app(LocalFinanceAuditService::class)->record($supplier, 'import_supplier_registered', $actor, null, [
                'company_name' => $companyName,
                'email' => $user->email,
                'scope' => 'import',
            ]);

            return $user;
        });

        event(new AuthSecurityEvent('password_changed', $user, metadata: ['actor_user_id' => $actor->id, 'reason' => 'import_supplier_create']));
        app(NotificationService::class)->send($user, 'auth.password_set_by_admin', 'auth:password-set:'.$user->id.':'.$user->auth_session_version,
            'finance_controls.password_notice_title', 'finance_controls.password_notice_body', route('profile.security'), 'shield');

        return $user;
    }

    /**
     * Approve a change request (Purchasing OR Finance).
     */
    public function approve(SupplierChangeRequest $changeRequest, User $reviewer, ?string $notes = null, ?int $bankConfirmationDocumentId = null): SupplierChangeRequest
    {
        if ($changeRequest->supplier_id === $reviewer->id) {
            throw new InvalidArgumentException(__('local_procurement.vendor_validation.self_approve'));
        }

        if (! $reviewer->isFinance() && ! $reviewer->isPurchasing() && ! $reviewer->isAdmin()) {
            throw new InvalidArgumentException(__('local_procurement.vendor_validation.approve_role'));
        }

        return DB::transaction(function () use ($changeRequest, $reviewer, $notes, $bankConfirmationDocumentId) {
            /** @var SupplierChangeRequest $req */
            $req = SupplierChangeRequest::where('id', $changeRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($req->status !== SupplierChangeRequest::STATUS_PENDING) {
                throw new RuntimeException(__('local_procurement.vendor_validation.pending'));
            }

            $proposed = $req->proposed_data;
            $supplierUser = $req->supplier;

            $this->assertReviewable($req, $supplierUser, $reviewer);
            if ($req->isContactChange()) {
                return $this->applyContactRequest($req, $supplierUser, $reviewer, $notes);
            }

            $bankFields = ['bank_name', 'account_number', 'account_holder_name'];
            $hasBankChange = collect($bankFields)->contains(fn ($field) => trim((string) ($proposed[$field] ?? '')) !== '');
            $document = null;

            if ($hasBankChange) {
                if (collect($bankFields)->contains(fn ($field) => trim((string) ($proposed[$field] ?? '')) === '')) {
                    throw ValidationException::withMessages(['bank_confirmation_document_id' => __('finance_controls.bank_fields_required_together')]);
                }

                $docId = $bankConfirmationDocumentId ?? ($proposed['bank_confirmation_document_id'] ?? null);

                $document = SupplierMasterDocument::whereKey($docId)
                    ->where('supplier_id', $supplierUser->id)
                    ->where('document_type', SupplierMasterDocument::TYPE_SURAT_PERNYATAAN_REKENING)
                    ->lockForUpdate()
                    ->first();

                if (! $document) {
                    throw ValidationException::withMessages(['bank_confirmation_document_id' => __('finance_controls.bank_document_missing_for_approval')]);
                }

                try {
                    app(FileAccessGuard::class)->assertReadable($document);
                } catch (HttpException) {
                    throw ValidationException::withMessages(['bank_confirmation_document_id' => __('finance_controls.bank_document')]);
                }

                if (($proposed['bank_confirmation_document_id'] ?? null) !== $document->id) {
                    $proposed['bank_confirmation_document_id'] = $document->id;
                    $req->proposed_data = $proposed;
                }
            }

            // Apply profile updates
            $supplier = $supplierUser->supplier ?? new Supplier(['user_id' => $supplierUser->id]);

            $fillable = [
                'company_name',
                'address',
                'phone',
                'npwp',
                'category',
                'vendor_category',
                'is_pkp',
                'pic_name',
                'pic_email',
                'pic_phone',
                'payment_term_days',
            ];

            $profileUpdates = array_intersect_key($proposed, array_flip($fillable));
            if (isset($profileUpdates['vendor_category']) && ! isset($profileUpdates['category'])) {
                $profileUpdates['category'] = $profileUpdates['vendor_category'];
            }

            if (! empty($profileUpdates)) {
                $supplier->fill($profileUpdates);
                $supplier->save();
            }

            // Apply bank account if proposed
            if (! empty($proposed['bank_name']) && ! empty($proposed['account_number']) && ! empty($proposed['account_holder_name'])) {
                $this->vendorMasterService->addBankAccount($supplierUser, [
                    'bank_name' => $proposed['bank_name'],
                    'account_number' => $proposed['account_number'],
                    'account_holder_name' => $proposed['account_holder_name'],
                ], $reviewer, autoVerify: true);
            }

            $req = app(SupplierChangeRequestStateEngine::class)->transition(
                changeRequest: $req,
                target: SupplierChangeRequestStatus::APPROVED,
                actor: $reviewer,
                reason: $notes,
                attributes: [
                    'reviewed_by' => $reviewer->id,
                    'reviewed_at' => now(),
                    'review_notes' => $notes,
                ],
            );
            app(LocalFinanceAuditService::class)->record($req, 'vendor_change_approved', $reviewer, $req->current_data_snapshot, $proposed, ['bank_confirmation_document_id' => $hasBankChange ? $document->id : null, 'review_notes' => $notes]);

            return $req->fresh();
        });
    }

    /**
     * Reject a change request (Purchasing OR Finance).
     */
    public function reject(SupplierChangeRequest $changeRequest, User $reviewer, string $notes): SupplierChangeRequest
    {
        if ($changeRequest->supplier_id === $reviewer->id) {
            throw new InvalidArgumentException(__('local_procurement.vendor_validation.self_reject'));
        }

        if (! $reviewer->isFinance() && ! $reviewer->isPurchasing() && ! $reviewer->isAdmin()) {
            throw new InvalidArgumentException(__('local_procurement.vendor_validation.reject_role'));
        }

        if (trim($notes) === '') {
            throw new InvalidArgumentException(__('local_procurement.vendor_validation.rejection_notes'));
        }

        return DB::transaction(function () use ($changeRequest, $reviewer, $notes) {
            /** @var SupplierChangeRequest $req */
            $req = SupplierChangeRequest::where('id', $changeRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($req->status !== SupplierChangeRequest::STATUS_PENDING) {
                throw new RuntimeException(__('local_procurement.vendor_validation.pending'));
            }

            $supplierUser = $req->supplier;
            $this->assertReviewable($req, $supplierUser, $reviewer);

            $req = app(SupplierChangeRequestStateEngine::class)->transition(
                changeRequest: $req,
                target: SupplierChangeRequestStatus::REJECTED,
                actor: $reviewer,
                reason: trim($notes),
                attributes: [
                    'reviewed_by' => $reviewer->id,
                    'reviewed_at' => now(),
                    'review_notes' => trim($notes),
                ],
            );

            if ($req->isContactChange()) {
                $this->notifyContactDecision($req, $supplierUser, 'rejected', ['notes' => trim($notes)]);
            }

            return $req->fresh();
        });
    }

    /**
     * Profile requests stay Local-only; contact requests belong to the Import
     * portal and are reviewed by Purchasing/Admin only.
     */
    private function assertReviewable(SupplierChangeRequest $req, mixed $supplierUser, User $reviewer): void
    {
        if (! $req->isContactChange()) {
            if (! $supplierUser instanceof User || ! $supplierUser->isLocalEligible()) {
                throw new InvalidArgumentException(__('local_procurement.vendor_validation.active_supplier'));
            }

            return;
        }

        $this->assertContactReviewer($reviewer);
        if (! $supplierUser instanceof User || ! ($supplierUser->isImportEligible() || $supplierUser->isLocalEligible())) {
            throw new InvalidArgumentException(__('local_procurement.vendor_validation.active_supplier'));
        }
    }

    private function assertContactReviewer(User $actor): void
    {
        if (! $actor->is_active || (! $actor->isPurchasing() && ! $actor->isAdmin())) {
            throw new InvalidArgumentException(__('supplier_contact.validation.reviewer_role'));
        }
    }

    private function applyContactRequest(SupplierChangeRequest $req, User $supplierUser, User $reviewer, ?string $notes): SupplierChangeRequest
    {
        $supplier = Supplier::where('user_id', $supplierUser->id)->lockForUpdate()->first()
            ?? new Supplier(['user_id' => $supplierUser->id]);
        $proposed = $this->contactFields($req->proposed_data ?? []);
        $snapshot = $req->current_data_snapshot ?? [];
        $current = $this->contactSnapshot(
            $supplier->exists ? $supplier : null,
            array_unique([...SupplierChangeRequest::CONTACT_FIELDS, ...array_keys($snapshot), ...array_keys($proposed)]),
        );

        foreach (array_unique([...SupplierChangeRequest::CONTACT_FIELDS, ...array_keys($snapshot)]) as $field) {
            if ((string) ($current[$field] ?? '') !== (string) ($snapshot[$field] ?? '')) {
                throw new RuntimeException(__('supplier_contact.validation.stale'));
            }
        }

        $supplier->fill($proposed)->save();

        $req = app(SupplierChangeRequestStateEngine::class)->transition(
            changeRequest: $req,
            target: SupplierChangeRequestStatus::APPROVED,
            actor: $reviewer,
            reason: $notes,
            attributes: [
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_notes' => $notes,
            ],
        );
        app(LocalFinanceAuditService::class)->record($req, 'vendor_contact_approved', $reviewer, $current, $proposed, ['review_notes' => $notes]);
        $this->notifyContactDecision($req, $supplierUser, 'approved');

        return $req->fresh();
    }

    /** @return array<string, string> contact person always, company details only when filled in */
    private function contactFields(array $input): array
    {
        $contact = [];
        foreach (SupplierChangeRequest::CONTACT_FIELDS as $field) {
            $contact[$field] = trim((string) ($input[$field] ?? ''));
        }
        foreach (SupplierChangeRequest::COMPANY_FIELDS as $field) {
            $value = trim((string) ($input[$field] ?? ''));
            if ($value !== '') {
                $contact[$field] = $value;
            }
        }

        return $contact;
    }

    /** @param list<string>|null $fields */
    private function contactSnapshot(?Supplier $supplier, ?array $fields = null): array
    {
        $snapshot = [];
        foreach ($fields ?? SupplierChangeRequest::CONTACT_FIELDS as $field) {
            $snapshot[$field] = $supplier?->{$field};
        }

        return $snapshot;
    }

    private function cancelPendingContactRequests(User $user): void
    {
        $pendingRequests = SupplierChangeRequest::where('supplier_id', $user->id)
            ->contact()
            ->pending()
            ->lockForUpdate()
            ->get();

        $engine = app(SupplierChangeRequestStateEngine::class);
        foreach ($pendingRequests as $pendingRequest) {
            $engine->transition(
                changeRequest: $pendingRequest,
                target: SupplierChangeRequestStatus::CANCELLED,
                actor: $user,
                attributes: ['reviewed_at' => now()],
            );
        }
    }

    private function notifyContactDecision(SupplierChangeRequest $req, User $supplierUser, string $decision, array $replace = []): void
    {
        app(NotificationService::class)->send(
            $supplierUser,
            'supplier_contact.'.$decision,
            'supplier-contact:'.$decision.':'.$req->id,
            'supplier_contact.notifications.'.$decision.'_title',
            'supplier_contact.notifications.'.$decision.'_body',
            route('supplier.company-profile.show'),
            'user',
            ['domain' => NotificationDomain::IMPORT],
            $replace,
        );
    }

    private function companyName(User $user): string
    {
        return (string) ($user->supplier?->company_name ?: $user->name);
    }
}
