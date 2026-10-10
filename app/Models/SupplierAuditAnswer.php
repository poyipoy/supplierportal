<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierAuditAnswer extends Model
{
    public const ANSWER_YES = 'YES';

    public const ANSWER_NO = 'NO';

    public const ANSWERS = [self::ANSWER_YES, self::ANSWER_NO];

    public const SCORE_MIN = 1;

    public const SCORE_MAX = 5;

    protected $fillable = [
        'supplier_audit_id',
        'supplier_audit_criterion_id',
        'parent_section_code_snapshot',
        'parent_section_title_snapshot',
        'section_code_snapshot',
        'section_title_snapshot',
        'sheet_number_snapshot',
        'criterion_number_snapshot',
        'sort_order_snapshot',
        'criterion_text_snapshot',
        'answer',
        'score',
    ];

    protected function casts(): array
    {
        return [
            'sheet_number_snapshot' => 'integer',
            'criterion_number_snapshot' => 'integer',
            'sort_order_snapshot' => 'integer',
            'score' => 'integer',
        ];
    }

    public function audit(): BelongsTo
    {
        return $this->belongsTo(SupplierAudit::class, 'supplier_audit_id');
    }

    public function criterion(): BelongsTo
    {
        return $this->belongsTo(SupplierAuditCriterion::class, 'supplier_audit_criterion_id');
    }

    /** Ya tanpa Score belum lengkap untuk submit (D3). */
    public function isComplete(): bool
    {
        return $this->answer === self::ANSWER_NO
            || ($this->answer === self::ANSWER_YES && $this->score !== null);
    }
}
