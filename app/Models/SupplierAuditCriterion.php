<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierAuditCriterion extends Model
{
    protected $table = 'supplier_audit_criteria';

    protected $fillable = ['supplier_audit_section_id', 'number', 'sort_order', 'text'];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(SupplierAuditSection::class, 'supplier_audit_section_id');
    }
}
