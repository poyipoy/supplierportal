<?php

namespace App\Services\VendorMaster;

use App\Models\Supplier;
use App\Models\SupplierBankAccount;
use App\Models\SupplierChangeRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

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
     * Approve a change request (Purchasing OR Finance).
     */
    public function approve(SupplierChangeRequest $changeRequest, User $reviewer, ?string $notes = null): SupplierChangeRequest
    {
        if ($changeRequest->supplier_id === $reviewer->id) {
            throw new InvalidArgumentException('Supplier cannot approve their own change request.');
        }

        if (! $reviewer->isFinance() && ! $reviewer->isPurchasing() && ! $reviewer->isAdmin()) {
            throw new InvalidArgumentException('Unauthorized to approve vendor master changes.');
        }

        return DB::transaction(function () use ($changeRequest, $reviewer, $notes) {
            /** @var SupplierChangeRequest $req */
            $req = SupplierChangeRequest::where('id', $changeRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($req->status !== SupplierChangeRequest::STATUS_PENDING) {
                throw new RuntimeException('Change request is not in PENDING status.');
            }

            $proposed = $req->proposed_data;
            $supplierUser = $req->supplier;

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

            $req->update([
                'status' => SupplierChangeRequest::STATUS_APPROVED,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_notes' => $notes,
            ]);

            return $req->fresh();
        });
    }

    /**
     * Reject a change request (Purchasing OR Finance).
     */
    public function reject(SupplierChangeRequest $changeRequest, User $reviewer, string $notes): SupplierChangeRequest
    {
        if ($changeRequest->supplier_id === $reviewer->id) {
            throw new InvalidArgumentException('Supplier cannot reject their own change request.');
        }

        if (! $reviewer->isFinance() && ! $reviewer->isPurchasing() && ! $reviewer->isAdmin()) {
            throw new InvalidArgumentException('Unauthorized to reject vendor master changes.');
        }

        if (trim($notes) === '') {
            throw new InvalidArgumentException('Rejection notes are mandatory.');
        }

        return DB::transaction(function () use ($changeRequest, $reviewer, $notes) {
            /** @var SupplierChangeRequest $req */
            $req = SupplierChangeRequest::where('id', $changeRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($req->status !== SupplierChangeRequest::STATUS_PENDING) {
                throw new RuntimeException('Change request is not in PENDING status.');
            }

            $req->update([
                'status' => SupplierChangeRequest::STATUS_REJECTED,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_notes' => trim($notes),
            ]);

            return $req->fresh();
        });
    }
}
