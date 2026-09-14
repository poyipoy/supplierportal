<?php

namespace App\Models;

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

    public function totalWithholding(): float
    {
        $pph23 = $this->pph_23_applicable ? (float) $this->pph_23_amount : 0.0;
        $pph42 = $this->pph_4_2_applicable ? (float) $this->pph_4_2_amount : 0.0;
        $pph21 = $this->pph_21_applicable ? (float) $this->pph_21_amount : 0.0;

        return round($pph23 + $pph42 + $pph21, 2);
    }

    public function calculateNetPayable(float $dpp): float
    {
        $ppn = (float) $this->verified_ppn;

        return round($dpp + $ppn - $this->totalWithholding(), 2);
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
