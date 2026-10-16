<?php

namespace App\Models;

use App\Traits\HasHashids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Master Reference Price (Master Harga). Evaluation-only; never read by
 * transactional code. Mission 06B owns the write rules (append-only versions,
 * overlap check under a material_masters row lock).
 */
class MaterialReferencePrice extends Model
{
    // Appendix A-4: hashed route keys; raw integer ids are rejected (404).
    use HasHashids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_VOID = 'void';

    /** Decision 18: the key dimension for each shape (database CHECK mirrors this). */
    public const DIMENSION_FIELD_BY_SHAPE = [
        PrItem::SHAPE_FLAT => 'thickness',
        PrItem::SHAPE_ROUND => 'd_outer',
        PrItem::SHAPE_HOLLOW => 'd_outer',
    ];

    protected $fillable = [
        'material_master_id',
        'shape',
        'dimension_field',
        'dimension_min',
        'dimension_max',
        'currency',
        'price_per_kg',
        'valid_from',
        'valid_until',
        'status',
        'supersedes_id',
        'version_no',
        'notes',
        'voided_by',
        'voided_at',
        'void_reason',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'dimension_min' => 'decimal:4',
            'dimension_max' => 'decimal:4',
            'price_per_kg' => 'decimal:4',
            'valid_from' => 'date',
            'valid_until' => 'date',
            'version_no' => 'integer',
            'voided_at' => 'datetime',
        ];
    }

    public function materialMaster(): BelongsTo
    {
        return $this->belongsTo(MaterialMaster::class);
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
