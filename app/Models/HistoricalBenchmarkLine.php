<?php

namespace App\Models;

use App\Traits\HasHashids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HistoricalBenchmarkLine extends Model
{
    use HasHashids;

    protected $fillable = [
        'import_id',
        'po_number',
        'po_number_normalized',
        'line_no',
        'po_date',
        'po_datetime_utc',
        'po_year',
        'supplier_name',
        'supplier_id',
        'material_master_id',
        'material_code_snapshot',
        'shape',
        'key_dimension',
        'rounded_dimension',
        'ordered_weight_kg',
        'currency',
        'price_per_kg',
        'pr_number',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'po_date' => 'date:Y-m-d',
            'po_datetime_utc' => 'datetime',
            'po_year' => 'integer',
            'key_dimension' => 'decimal:4',
            'rounded_dimension' => 'integer',
            'ordered_weight_kg' => 'decimal:4',
            'price_per_kg' => 'decimal:4',
        ];
    }

    public static function normalizePoNumber(string $poNumber): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($poNumber)) ?? '';

        return mb_strtoupper($collapsed);
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(ReferencePriceImport::class, 'import_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supplier_id');
    }

    public function materialMaster(): BelongsTo
    {
        return $this->belongsTo(MaterialMaster::class, 'material_master_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}

