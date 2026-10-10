<?php

namespace App\Services\SupplierAudit;

use App\Models\SupplierAudit;
use App\Models\User;
use App\Services\RegionalDisplayFormatter;
use Illuminate\Validation\ValidationException;

/**
 * D13: supplier local yang melewati deadline Supplier Audit (belum submit) tidak boleh
 * mengajukan invoice baru. Resubmit revisi dan pembatalan invoice tidak terpengaruh.
 *
 * Didaftarkan sebagai scoped binding sehingga hasil dimemoize per request.
 */
class SupplierAuditInvoiceGate
{
    /** @var array<int, SupplierAudit|false> */
    private array $memo = [];

    public function blockingAudit(?User $user): ?SupplierAudit
    {
        if ($user === null || ! $user->isSupplier() || ! $user->hasSupplierScope('local')) {
            return null;
        }

        $key = (int) $user->id;
        if (! array_key_exists($key, $this->memo)) {
            $this->memo[$key] = SupplierAudit::query()
                ->ownedBy($user)
                ->late()
                ->orderBy('due_date')
                ->first() ?? false;
        }

        return $this->memo[$key] ?: null;
    }

    public function assertCanSubmitNewInvoice(User $user): void
    {
        $audit = $this->blockingAudit($user);
        if ($audit !== null) {
            throw ValidationException::withMessages(['supplier_audit' => $this->message($audit)]);
        }
    }

    public function message(SupplierAudit $audit): string
    {
        return __('supplier_audit.invoice_block.message', [
            'period' => $audit->period_label,
            'date' => app(RegionalDisplayFormatter::class)->date($audit->due_date) ?? $audit->due_date?->toDateString(),
        ]);
    }

    public function forget(): void
    {
        $this->memo = [];
    }
}
