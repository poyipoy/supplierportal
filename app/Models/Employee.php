<?php

namespace App\Models;

use App\Services\LocalInvoice\LocalFinanceAuditService;
use App\Support\BankTransferMapping;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Employee extends Model
{
    use HasFactory;

    public const BANK_FIELDS = ['bank_name', 'account_number', 'account_holder_name'];

    public ?User $bankChangeActor = null;

    private ?array $bankChangeBefore = null;

    protected static function booted(): void
    {
        static::updating(function (Employee $employee): void {
            $employee->bankChangeBefore = null;
            if (! $employee->isDirty(self::BANK_FIELDS)) {
                return;
            }
            $actor = $employee->bankChangeActor;
            if (DB::transactionLevel() < 1 || ! $actor?->is_active || (! $actor->isGa() && ! $actor->isAdmin())) {
                throw ValidationException::withMessages(['bank_name' => __('finance_controls.bank_actor')]);
            }
            $current = self::whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $blocked = $current->claims()->whereIn('status', [GaClaim::STATUS_BASIC_VERIFIED, GaClaim::STATUS_UNDER_VERIFICATION, GaClaim::STATUS_READY_TO_PAY])->lockForUpdate()->first(['id']);
            if ($blocked) {
                throw ValidationException::withMessages(['bank_name' => __('finance_controls.bank_frozen')]);
            }
            $employee->bankChangeBefore = $current->only(self::BANK_FIELDS);
        });
        static::updated(function (Employee $employee): void {
            if ($employee->bankChangeBefore !== null && $employee->bankChangeActor) {
                app(LocalFinanceAuditService::class)->record($employee, 'employee_bank_changed', $employee->bankChangeActor, $employee->bankChangeBefore, $employee->only(self::BANK_FIELDS));
                $employee->bankChangeBefore = null;
            }
        });
    }

    public function bankDetails(): array
    {
        return array_map(static fn ($value) => trim((string) $value), $this->only(self::BANK_FIELDS));
    }

    public function bankFingerprint(): string
    {
        return hash('sha256', json_encode($this->bankDetails(), JSON_THROW_ON_ERROR));
    }

    protected $fillable = [
        'name',
        'department',
        'bank_name',
        'account_number',
        'account_holder_name',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function claims(): HasMany
    {
        return $this->hasMany(GaClaim::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function isBca(): bool
    {
        return BankTransferMapping::isBca((string) $this->bank_name);
    }
}
