<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShipmentPreshipmentInspection extends Model
{
    public const REQUIRED_EXPORTER_FIELDS = [
        'exporter_name',
        'exporter_address',
        'exporter_city',
        'exporter_country',
        'exporter_port_of_loading',
    ];

    public const REQUIRED_LOCATION_FIELDS = [
        'supplier_name',
        'supplier_address',
        'supplier_city',
        'supplier_country',
    ];

    protected $fillable = [
        'shipment_id',
        'goods_location_id',
        ...SupplierPreshipmentProfile::FIELDS,
        'supplier_same_as_exporter',
        'revision_count',
        'last_revised_at',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'supplier_same_as_exporter' => 'boolean',
            'revision_count' => 'integer',
            'last_revised_at' => 'datetime',
        ];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class, 'shipment_id');
    }

    public function goodsLocation(): BelongsTo
    {
        return $this->belongsTo(SupplierGoodsLocation::class, 'goods_location_id')->withTrashed();
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function isCompleteForSubmission(): bool
    {
        foreach ([...self::REQUIRED_EXPORTER_FIELDS, ...self::REQUIRED_LOCATION_FIELDS] as $field) {
            if (trim((string) ($this->{$field} ?? '')) === '') {
                return false;
            }
        }

        return true;
    }
}
