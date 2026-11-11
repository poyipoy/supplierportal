<?php

namespace App\Services\Payment;

use App\Exceptions\BusinessRuleArgumentException;
use App\Models\Employee;
use App\Models\PaymentVoucher;
use App\Models\PaymentVoucherDocument;
use App\Models\User;
use App\Services\FileSecurity\FileInspectionService;
use App\Services\LocalInvoice\LocalFinanceAuditService;
use App\Support\BankList;
use App\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class ManualPaymentVoucherService
{
    public function __construct(
        private PaymentVoucherService $numbers,
        private LocalFinanceAuditService $audit,
        private FileInspectionService $files,
    ) {}

    /**
     * Issue a standalone FINAL payment voucher atomically.
     *
     * @param  array<string, mixed>  $data
     */
    public function issue(User $actor, array $data, ?UploadedFile $supportingFile = null): PaymentVoucher
    {
        if (! $actor->is_active || (! $actor->isFinance() && ! $actor->isPurchasing() && ! $actor->isAdmin())) {
            throw new BusinessRuleArgumentException(__('payment_voucher.validation.role'));
        }

        $dpp = Money::normalize($data['dpp_amount'] ?? '0');
        $ppn = Money::normalize($data['ppn_amount'] ?? '0');
        $pph = Money::normalize($data['pph_amount'] ?? '0');
        $bankFee = Money::normalize($data['bank_fee'] ?? '0');

        if (Money::compare($dpp, '0.01') < 0) {
            throw ValidationException::withMessages([
                'dpp_amount' => __('payment_voucher.validation.amount_positive'),
            ]);
        }

        $net = Money::subtract(
            Money::subtract(Money::add($dpp, $ppn), $pph),
            $bankFee
        );

        if (Money::compare($net, '0.01') < 0) {
            throw ValidationException::withMessages([
                'dpp_amount' => __('payment_voucher.validation.net_positive'),
            ]);
        }

        $payeeType = (string) ($data['payee_type'] ?? '');
        $method = (string) ($data['payment_method'] ?? '');
        $supplierId = null;

        if ($payeeType === PaymentVoucher::PAYEE_SUPPLIER) {
            $hash = (string) ($data['payee_supplier_hash'] ?? '');
            if ($hash === '' || ctype_digit($hash)) {
                throw ValidationException::withMessages([
                    'payee_supplier_hash' => __('payment_voucher.validation.supplier_invalid'),
                ]);
            }
            $supplier = (new User)->resolveRouteBinding($hash);
            if (! $supplier instanceof User) {
                throw ValidationException::withMessages([
                    'payee_supplier_hash' => __('payment_voucher.validation.supplier_invalid'),
                ]);
            }
            $supplierId = $supplier->id;
        }

        if ($supportingFile !== null) {
            $this->files->inspectUpload($supportingFile, 'payment_voucher', 'supporting');
        }

        $written = [];

        try {
            return DB::transaction(function () use (
                $actor,
                $data,
                $payeeType,
                $method,
                $supplierId,
                $dpp,
                $ppn,
                $pph,
                $bankFee,
                $net,
                $supportingFile,
                &$written
            ): PaymentVoucher {
                $payee = $this->resolveLockedPayee($payeeType, $method, $supplierId, $data);

                $voucherNumber = $this->numbers->nextVoucherNumber();
                $terbilang = $this->numbers->terbilangFromDecimal($net);

                $voucher = PaymentVoucher::create([
                    'voucher_number' => $voucherNumber,
                    'voucher_date' => $data['voucher_date'],
                    'source_department' => PaymentVoucher::departmentForUser($actor),
                    'status' => PaymentVoucher::STATUS_FINAL,
                    'payment_method' => $method,
                    'payee_type' => $payeeType,
                    'payee_supplier_id' => $payee['supplier_id'],
                    'payee_employee_id' => $payee['employee_id'],
                    'payee_name_snapshot' => $payee['name'],
                    'payee_department_snapshot' => $payee['department'],
                    'bank_name_snapshot' => $payee['bank_name'],
                    'bank_account_snapshot' => $payee['account_number'],
                    'bank_account_holder_snapshot' => $payee['account_holder_name'],
                    'npwp_snapshot' => $payee['npwp'],
                    'reference_number' => filled($data['reference_number'] ?? null)
                        ? trim((string) $data['reference_number'])
                        : null,
                    'description' => trim((string) $data['description']),
                    'notes' => filled($data['notes'] ?? null)
                        ? trim((string) $data['notes'])
                        : null,
                    'dpp_amount' => $dpp,
                    'ppn_amount' => $ppn,
                    'pph_amount' => $pph,
                    'bank_fee' => $bankFee,
                    'net_amount' => $net,
                    'terbilang_snapshot' => $terbilang,
                    'created_by' => $actor->id,
                    'issued_at' => now(),
                ]);

                if ($supportingFile !== null) {
                    $path = 'payment-vouchers/'.$voucher->id.'/'.$supportingFile->hashName(); // biz-time:ignore storage path
                    $written[] = $path;

                    $stored = $this->files->storeUpload($supportingFile, 'payment_voucher', $path, 'supporting');

                    PaymentVoucherDocument::create([
                        'payment_voucher_id' => $voucher->id,
                        'document_type' => 'supporting',
                        'file_path' => $stored['file_path'],
                        'original_filename' => $stored['file_name'],
                        'mime_type' => $stored['file_type'],
                        'file_size' => $stored['file_size'],
                        'file_inspection_id' => $stored['file_inspection_id'],
                        'uploaded_by' => $actor->id,
                    ]);
                }

                $fresh = $voucher->fresh(['documents', 'creator']);
                $this->audit->record($voucher, 'manual_voucher_issued', $actor, null, $fresh->toArray());

                return $fresh;
            });
        } catch (Throwable $e) {
            foreach ($written as $path) {
                Storage::disk('private')->delete($path);
            }

            throw $e;
        }
    }

    /**
     * Void an issued FINAL payment voucher inside a row-locked transaction.
     */
    public function void(PaymentVoucher $voucher, User $actor, string $reason): PaymentVoucher
    {
        $cleanReason = trim($reason);
        if ($cleanReason === '') {
            throw ValidationException::withMessages([
                'void_reason' => __('payment_voucher.validation.void_reason'),
            ]);
        }

        return DB::transaction(function () use ($voucher, $actor, $cleanReason): PaymentVoucher {
            /** @var PaymentVoucher $locked */
            $locked = PaymentVoucher::whereKey($voucher->id)->lockForUpdate()->firstOrFail();

            Gate::forUser($actor)->authorize('void', $locked);

            if (! $locked->isFinal()) {
                throw ValidationException::withMessages([
                    'void_reason' => __('payment_voucher.validation.void_status'),
                ]);
            }

            $before = $locked->only(['status', 'voided_by', 'voided_at', 'void_reason']);

            $locked->update([
                'status' => PaymentVoucher::STATUS_CANCELLED,
                'voided_by' => $actor->id,
                'voided_at' => now(),
                'void_reason' => $cleanReason,
            ]);

            $after = $locked->only(array_keys($before));
            $this->audit->record($locked, 'manual_voucher_voided', $actor, $before, $after);

            return $locked->fresh(['documents', 'creator', 'voider']);
        });
    }

    /**
     * Build searchable-select data and prefill state for the create form.
     *
     * @return array<string, mixed>
     */
    public function formOptions(?PaymentVoucher $duplicate = null): array
    {
        $suppliers = User::query()
            ->where('role', 'supplier')
            ->where('account_status', User::ACCOUNT_STATUS_ACTIVE)
            ->where('is_active', true)
            ->whereHas('supplierScopes')
            ->with(['supplier', 'activeSupplierBankAccount', 'supplierScopes'])
            ->orderBy('name')
            ->get();

        $supplierOptions = [];
        $supplierMap = [];

        foreach ($suppliers as $user) {
            $profile = $user->supplier;
            $verifiedBank = $user->activeSupplierBankAccount;
            $scopes = $user->supplierScopes->pluck('scope')->map(fn ($s) => strtoupper((string) $s))->implode('/');
            $displayName = $profile?->company_name ?: $user->name;

            $sublabelParts = array_filter([
                $scopes !== '' ? $scopes : null,
                $verifiedBank
                    ? $verifiedBank->bank_name.' · '.$verifiedBank->account_number
                    : __('payment_voucher.labels.no_verified_bank'),
            ]);

            $supplierOptions[] = [
                'value' => $user->hash,
                'label' => $displayName,
                'sublabel' => implode(' · ', $sublabelParts),
                'badge' => $verifiedBank ? __('payment_voucher.labels.verified_bank') : __('payment_voucher.methods.kas'),
                'badgeTone' => $verifiedBank ? 'info' : 'warning',
                'searchKeywords' => strtolower(implode(' ', array_filter([
                    $displayName,
                    $user->name,
                    $profile?->npwp,
                    $verifiedBank?->bank_name,
                    $verifiedBank?->account_number,
                    $verifiedBank?->account_holder_name,
                    $scopes,
                ]))),
            ];

            $supplierMap[$user->hash] = [
                'name' => $displayName,
                'npwp' => $profile?->npwp ?: '-',
                'has_verified_bank' => $verifiedBank !== null,
                'bank_name' => $verifiedBank?->bank_name,
                'account_number' => $verifiedBank?->account_number,
                'account_holder_name' => $verifiedBank?->account_holder_name,
            ];
        }

        $employees = Employee::active()->orderBy('name')->get();
        $employeeOptions = [];
        $employeeMap = [];

        foreach ($employees as $emp) {
            $accountDetail = __('finance.drp_ui.bank_details', [
                'bank' => $emp->bank_name,
                'account' => $emp->account_number,
                'holder' => $emp->account_holder_name,
            ]);

            $employeeOptions[] = [
                'value' => (string) $emp->id,
                'label' => $emp->name,
                'sublabel' => $emp->department.' · '.$accountDetail,
                'badge' => $emp->bank_name,
                'badgeTone' => $emp->isBca() ? 'neutral' : 'warning',
                'searchKeywords' => strtolower(implode(' ', array_filter([
                    $emp->name,
                    $emp->department,
                    $emp->bank_name,
                    $emp->account_number,
                    $emp->account_holder_name,
                ]))),
            ];

            $employeeMap[(string) $emp->id] = [
                'name' => $emp->name,
                'department' => $emp->department,
                'bank_name' => $emp->bank_name,
                'account_number' => $emp->account_number,
                'account_holder_name' => $emp->account_holder_name,
            ];
        }

        $duplicatePrefill = [];
        if ($duplicate !== null) {
            $duplicate->loadMissing('supplierUser');
            $duplicatePrefill = [
                'payee_type' => $duplicate->payee_type,
                'payee_supplier_hash' => $duplicate->payee_type === PaymentVoucher::PAYEE_SUPPLIER
                    ? $duplicate->supplierUser?->hash
                    : null,
                'payee_employee_id' => $duplicate->payee_type === PaymentVoucher::PAYEE_EMPLOYEE
                    ? (string) $duplicate->payee_employee_id
                    : null,
                'payee_name' => $duplicate->payee_type === PaymentVoucher::PAYEE_MANUAL
                    ? $duplicate->payee_name_snapshot
                    : null,
                'npwp' => $duplicate->payee_type === PaymentVoucher::PAYEE_MANUAL
                    ? $duplicate->npwp_snapshot
                    : null,
                'bank_name' => $duplicate->payee_type === PaymentVoucher::PAYEE_MANUAL
                    ? $duplicate->bank_name_snapshot
                    : null,
                'account_number' => $duplicate->payee_type === PaymentVoucher::PAYEE_MANUAL
                    ? $duplicate->bank_account_snapshot
                    : null,
                'account_holder_name' => $duplicate->payee_type === PaymentVoucher::PAYEE_MANUAL
                    ? $duplicate->bank_account_holder_snapshot
                    : null,
                'payment_method' => $duplicate->payment_method,
                'reference_number' => $duplicate->reference_number,
                'description' => $duplicate->description,
                'notes' => $duplicate->notes,
                'dpp_amount' => $duplicate->dpp_amount,
                'ppn_amount' => $duplicate->ppn_amount,
                'pph_amount' => $duplicate->pph_amount,
                'bank_fee' => $duplicate->bank_fee,
            ];
        }

        return [
            'supplierOptions' => $supplierOptions,
            'supplierMap' => $supplierMap,
            'employeeOptions' => $employeeOptions,
            'employeeMap' => $employeeMap,
            'bankOptions' => BankList::options(old('bank_name', $duplicatePrefill['bank_name'] ?? null)),
            'duplicatePrefill' => $duplicatePrefill,
        ];
    }

    /**
     * Resolve authoritative payee + bank snapshot inside a transaction.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function resolveLockedPayee(string $payeeType, string $method, ?int $supplierId, array $data): array
    {
        if ($payeeType === PaymentVoucher::PAYEE_SUPPLIER) {
            /** @var User|null $supplier */
            $supplier = User::whereKey($supplierId)
                ->where('role', 'supplier')
                ->where('is_active', true)
                ->where('account_status', User::ACCOUNT_STATUS_ACTIVE)
                ->whereHas('supplierScopes')
                ->with(['supplier', 'activeSupplierBankAccount'])
                ->lockForUpdate()
                ->first();

            if (! $supplier) {
                throw ValidationException::withMessages([
                    'payee_supplier_hash' => __('payment_voucher.validation.supplier_invalid'),
                ]);
            }

            $bankName = null;
            $accountNumber = null;
            $accountHolder = null;

            if ($method === PaymentVoucher::METHOD_BANK) {
                $bank = $supplier->activeSupplierBankAccount()->lockForUpdate()->first();
                if (! $bank) {
                    throw ValidationException::withMessages([
                        'payee_supplier_hash' => __('payment_voucher.validation.supplier_bank_required'),
                    ]);
                }
                $bankName = trim((string) $bank->bank_name);
                $accountNumber = trim((string) $bank->account_number);
                $accountHolder = trim((string) $bank->account_holder_name);
            }

            return [
                'supplier_id' => $supplier->id,
                'employee_id' => null,
                'name' => trim((string) ($supplier->supplier?->company_name ?: $supplier->name)),
                'department' => null,
                'npwp' => $supplier->supplier?->npwp,
                'bank_name' => $bankName,
                'account_number' => $accountNumber,
                'account_holder_name' => $accountHolder,
            ];
        }

        if ($payeeType === PaymentVoucher::PAYEE_EMPLOYEE) {
            /** @var Employee|null $employee */
            $employee = Employee::whereKey($data['payee_employee_id'] ?? null)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if (! $employee) {
                throw ValidationException::withMessages([
                    'payee_employee_id' => __('payment_voucher.validation.employee_invalid'),
                ]);
            }

            $bankName = null;
            $accountNumber = null;
            $accountHolder = null;

            if ($method === PaymentVoucher::METHOD_BANK) {
                $bankDetails = $employee->bankDetails();
                if (in_array('', $bankDetails, true)) {
                    throw ValidationException::withMessages([
                        'payee_employee_id' => __('payment_voucher.validation.bank_required'),
                    ]);
                }
                $bankName = $bankDetails['bank_name'];
                $accountNumber = $bankDetails['account_number'];
                $accountHolder = $bankDetails['account_holder_name'];
            }

            return [
                'supplier_id' => null,
                'employee_id' => $employee->id,
                'name' => trim((string) $employee->name),
                'department' => trim((string) $employee->department),
                'npwp' => null,
                'bank_name' => $bankName,
                'account_number' => $accountNumber,
                'account_holder_name' => $accountHolder,
            ];
        }

        $isBank = $method === PaymentVoucher::METHOD_BANK;

        return [
            'supplier_id' => null,
            'employee_id' => null,
            'name' => trim((string) $data['payee_name']),
            'department' => null,
            'npwp' => filled($data['npwp'] ?? null) ? trim((string) $data['npwp']) : null,
            'bank_name' => $isBank ? trim((string) $data['bank_name']) : null,
            'account_number' => $isBank ? trim((string) $data['account_number']) : null,
            'account_holder_name' => $isBank ? trim((string) $data['account_holder_name']) : null,
        ];
    }
}
