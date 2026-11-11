<?php

namespace App\Models;

use App\Traits\HasHashids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentVoucher extends Model
{
    use HasHashids;

    public const STATUS_FINAL = 'FINAL';

    public const STATUS_CANCELLED = 'CANCELLED';

    public const STATUSES = [
        self::STATUS_FINAL,
        self::STATUS_CANCELLED,
    ];

    public const METHOD_BANK = 'BANK';

    public const METHOD_KAS = 'KAS';

    public const METHODS = [
        self::METHOD_BANK,
        self::METHOD_KAS,
    ];

    public const PAYEE_SUPPLIER = 'SUPPLIER';

    public const PAYEE_EMPLOYEE = 'EMPLOYEE';

    public const PAYEE_MANUAL = 'MANUAL';

    public const PAYEE_TYPES = [
        self::PAYEE_SUPPLIER,
        self::PAYEE_EMPLOYEE,
        self::PAYEE_MANUAL,
    ];

    public const DEPT_FINANCE = 'FINANCE';

    public const DEPT_PURCHASING = 'PURCHASING';

    public const DEPT_ADMIN = 'ADMIN';

    public const DEPARTMENTS = [
        self::DEPT_FINANCE,
        self::DEPT_PURCHASING,
        self::DEPT_ADMIN,
    ];

    protected $fillable = [
        'voucher_number',
        'voucher_date',
        'source_department',
        'status',
        'payment_method',
        'payee_type',
        'payee_supplier_id',
        'payee_employee_id',
        'payee_name_snapshot',
        'payee_department_snapshot',
        'bank_name_snapshot',
        'bank_account_snapshot',
        'bank_account_holder_snapshot',
        'npwp_snapshot',
        'reference_number',
        'description',
        'notes',
        'dpp_amount',
        'ppn_amount',
        'pph_amount',
        'bank_fee',
        'net_amount',
        'terbilang_snapshot',
        'created_by',
        'issued_at',
        'voided_by',
        'voided_at',
        'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'voucher_date' => 'date',
            'dpp_amount' => 'decimal:2',
            'ppn_amount' => 'decimal:2',
            'pph_amount' => 'decimal:2',
            'bank_fee' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'issued_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function supplierUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payee_supplier_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'payee_employee_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(PaymentVoucherDocument::class)->orderBy('id');
    }

    public function isFinal(): bool
    {
        return $this->status === self::STATUS_FINAL;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isBankMethod(): bool
    {
        return $this->payment_method === self::METHOD_BANK;
    }

    public function hasTaxOrFeeBreakdown(): bool
    {
        return bccomp((string) ($this->ppn_amount ?? '0'), '0', 2) > 0
            || bccomp((string) ($this->pph_amount ?? '0'), '0', 2) > 0
            || bccomp((string) ($this->bank_fee ?? '0'), '0', 2) > 0;
    }

    public static function departmentForUser(User $user): string
    {
        if ($user->isPurchasing()) {
            return self::DEPT_PURCHASING;
        }
        if ($user->isFinance()) {
            return self::DEPT_FINANCE;
        }

        return self::DEPT_ADMIN;
    }

    public static function departmentLabel(string $department): string
    {
        return match ($department) {
            self::DEPT_FINANCE => __('payment_voucher.departments.finance'),
            self::DEPT_PURCHASING => __('payment_voucher.departments.purchasing'),
            self::DEPT_ADMIN => __('payment_voucher.departments.admin'),
            default => $department,
        };
    }

    public static function payeeTypeLabel(string $payeeType): string
    {
        return match ($payeeType) {
            self::PAYEE_SUPPLIER => __('payment_voucher.payee_types.supplier'),
            self::PAYEE_EMPLOYEE => __('payment_voucher.payee_types.employee'),
            self::PAYEE_MANUAL => __('payment_voucher.payee_types.manual'),
            default => $payeeType,
        };
    }
}
