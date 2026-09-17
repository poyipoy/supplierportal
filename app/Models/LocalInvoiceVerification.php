<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LocalInvoiceVerification extends Model
{
    public const CHECK_OK = 'OK';
    public const CHECK_NOT_OK = 'NOT_OK';
    public const CHECK_NOT_APPLICABLE = 'NOT_APPLICABLE';

    public const PPN_SESUAI = 'SESUAI';
    public const PPN_TIDAK_SESUAI = 'TIDAK_SESUAI';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'revision_number' => 'integer',
            'submitted_ppn' => 'decimal:2',
            'verified_ppn' => 'decimal:2',
            'pph_23_applicable' => 'boolean',
            'pph_23_base' => 'decimal:2',
            'pph_23_rate' => 'decimal:2',
            'pph_23_amount' => 'decimal:2',
            'pph_4_2_applicable' => 'boolean',
            'pph_4_2_amount' => 'decimal:2',
            'pph_21_applicable' => 'boolean',
            'pph_21_amount' => 'decimal:2',
            'is_section_a_passed' => 'boolean',
            'is_section_b_passed' => 'boolean',
            'is_locked' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(LocalInvoice::class, 'local_invoice_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * Authoritative total withholding as an exact decimal string.
     */
    public function totalWithholdingExact(): string
    {
        return Money::sum([
            $this->pph_23_applicable ? $this->pph_23_amount : Money::ZERO,
            $this->pph_4_2_applicable ? $this->pph_4_2_amount : Money::ZERO,
            $this->pph_21_applicable ? $this->pph_21_amount : Money::ZERO,
        ]);
    }

    /**
     * Authoritative net payable as an exact decimal string.
     *
     * Every financial write path (DRP items, vouchers, batch totals) must use
     * this method. The float wrapper below exists only for display callers.
     */
    public function netPayableExact(string|int|float $dpp): string
    {
        return Money::subtract(
            Money::add($dpp, $this->verified_ppn),
            $this->totalWithholdingExact()
        );
    }

    /**
     * @deprecated Use totalWithholdingExact() in any path that persists money.
     */
    public function totalWithholding(): float
    {
        return (float) $this->totalWithholdingExact();
    }

    /**
     * @deprecated Use netPayableExact() in any path that persists money.
     */
    public function calculateNetPayable(float $dpp): float
    {
        return (float) $this->netPayableExact($dpp);
    }

    public function isSectionAComplete(): bool
    {
        return (bool) $this->is_section_a_passed;
    }

    public function isSectionBComplete(): bool
    {
        return (bool) $this->is_section_b_passed;
    }
}
