<?php

namespace App\Models;

use App\Traits\HasHashids;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupplierGoodsLocation extends Model
{
    use HasHashids, SoftDeletes;

    public const MAX_ACTIVE_PER_SUPPLIER = 20;

    public const FIELDS = [
        'name',
        'contact',
        'address',
        'country',
        'city',
        'telephone',
        'mobile',
        'fax',
        'email',
    ];

    protected $fillable = [
        'supplier_id',
        ...self::FIELDS,
        'is_default',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supplier_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function shipmentInspections(): HasMany
    {
        return $this->hasMany(ShipmentPreshipmentInspection::class, 'goods_location_id');
    }

    public function scopeForSupplier(Builder $query, int $supplierId): Builder
    {
        return $query->where('supplier_id', $supplierId);
    }

    /**
     * Map this location's attributes onto the `supplier_*` snapshot keys.
     *
     * @return array<string, string|null>
     */
    public function toSnapshotAttributes(): array
    {
        return [
            'supplier_name' => $this->name,
            'supplier_contact' => $this->contact,
            'supplier_address' => $this->address,
            'supplier_country' => $this->country,
            'supplier_city' => $this->city,
            'supplier_telephone' => $this->telephone,
            'supplier_mobile' => $this->mobile,
            'supplier_fax' => $this->fax,
            'supplier_email' => $this->email,
        ];
    }
}
