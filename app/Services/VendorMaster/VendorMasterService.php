<?php

namespace App\Services\VendorMaster;

use App\Models\Supplier;
use App\Models\SupplierBankAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class VendorMasterService
{
    public const CATEGORIES = [
        'Barang',
        'Jasa Konsultan',
        'Jasa Konstruksi',
        'Jasa Teknik & Manajemen',
        'Jasa Sewa',
        'Lainnya',
    ];

    /**
     * Determine if a supplier category requires a delivery note (Surat Jalan).
     */
    public function requiresSuratJalan(Supplier $supplier): bool
    {
        $category = $supplier->vendor_category ?: $supplier->category;

        return strcasecmp(trim((string) $category), 'Barang') === 0;
    }

    /**
     * Determine if a supplier requires a tax invoice (Faktur Pajak).
     */
    public function requiresFakturPajak(Supplier $supplier): bool
    {
        return (bool) $supplier->is_pkp;
    }

    /**
     * Add a versioned bank account for a supplier.
     */
    public function addBankAccount(User $supplierUser, array $data, ?User $actor = null, bool $autoVerify = false): SupplierBankAccount
    {
        return DB::transaction(function () use ($supplierUser, $data, $actor, $autoVerify) {
            $status = $autoVerify ? SupplierBankAccount::STATUS_VERIFIED : SupplierBankAccount::STATUS_PENDING;

            if ($autoVerify) {
                // Deactivate previously verified bank accounts
                SupplierBankAccount::where('supplier_id', $supplierUser->id)
                    ->where('status', SupplierBankAccount::STATUS_VERIFIED)
                    ->update([
                        'status' => SupplierBankAccount::STATUS_INACTIVE,
                        'deactivated_at' => now(),
                    ]);
            }

            return SupplierBankAccount::create([
                'supplier_id' => $supplierUser->id,
                'bank_name' => trim($data['bank_name']),
                'account_number' => trim($data['account_number']),
                'account_holder_name' => trim($data['account_holder_name']),
                'status' => $status,
                'verified_by' => $autoVerify && $actor ? $actor->id : null,
                'verified_at' => $autoVerify ? now() : null,
                'activated_at' => $autoVerify ? now() : null,
            ]);
        });
    }

    /**
     * Verify and activate a supplier bank account.
     */
    public function verifyBankAccount(SupplierBankAccount $bankAccount, User $reviewer): SupplierBankAccount
    {
        if ($bankAccount->supplier_id === $reviewer->id) {
            throw new InvalidArgumentException('Supplier cannot verify their own bank account.');
        }

        if (! $reviewer->isFinance() && ! $reviewer->isPurchasing() && ! $reviewer->isAdmin()) {
            throw new InvalidArgumentException('Unauthorized to verify bank account.');
        }

        return DB::transaction(function () use ($bankAccount, $reviewer) {
            // Lock and refresh
            $account = SupplierBankAccount::where('id', $bankAccount->id)->lockForUpdate()->firstOrFail();

            // Deactivate previous active bank accounts for this supplier
            SupplierBankAccount::where('supplier_id', $account->supplier_id)
                ->where('id', '!=', $account->id)
                ->where('status', SupplierBankAccount::STATUS_VERIFIED)
                ->update([
                    'status' => SupplierBankAccount::STATUS_INACTIVE,
                    'deactivated_at' => now(),
                ]);

            $account->update([
                'status' => SupplierBankAccount::STATUS_VERIFIED,
                'verified_by' => $reviewer->id,
                'verified_at' => now(),
                'activated_at' => now(),
            ]);

            return $account;
        });
    }

    /**
     * Internal direct update for authorized Finance/Admin.
     */
    public function updateMaster(User $supplierUser, array $data, User $actor): Supplier
    {
        if (! $actor->isFinance() && ! $actor->isAdmin()) {
            throw new InvalidArgumentException('Unauthorized to directly update vendor master.');
        }

        return DB::transaction(function () use ($supplierUser, $data, $actor) {
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

            $updates = array_intersect_key($data, array_flip($fillable));
            if (isset($updates['vendor_category']) && ! isset($updates['category'])) {
                $updates['category'] = $updates['vendor_category'];
            }

            $supplier->fill($updates);
            $supplier->save();

            // Handle bank account if provided
            if (! empty($data['bank_name']) && ! empty($data['account_number']) && ! empty($data['account_holder_name'])) {
                $currentActive = $supplierUser->activeSupplierBankAccount;
                $isDifferent = ! $currentActive
                    || $currentActive->bank_name !== trim($data['bank_name'])
                    || $currentActive->account_number !== trim($data['account_number'])
                    || $currentActive->account_holder_name !== trim($data['account_holder_name']);

                if ($isDifferent) {
                    $this->addBankAccount($supplierUser, [
                        'bank_name' => $data['bank_name'],
                        'account_number' => $data['account_number'],
                        'account_holder_name' => $data['account_holder_name'],
                    ], $actor, autoVerify: true);
                }
            }

            return $supplier->fresh();
        });
    }
}
