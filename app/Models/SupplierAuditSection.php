<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierAuditSection extends Model
{
    protected $fillable = ['supplier_audit_template_id', 'parent_id', 'code', 'title', 'level', 'sheet_number', 'sort_order'];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'sheet_number' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(SupplierAuditTemplate::class, 'supplier_audit_template_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    public function criteria(): HasMany
    {
        return $this->hasMany(SupplierAuditCriterion::class)->orderBy('number');
    }
}
