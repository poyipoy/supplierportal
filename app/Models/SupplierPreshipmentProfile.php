<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierPreshipmentProfile extends Model
{
    public const EXPORTER_FIELDS = [
        'exporter_name',
        'exporter_contact',
        'exporter_address',
        'exporter_city',
        'exporter_country',
        'exporter_zip_code',
        'exporter_telephone',
        'exporter_mobile',
        'exporter_fax',
        'exporter_email',
        'exporter_port_of_loading',
    ];

    public const LOCATION_FIELDS = [
        'supplier_name',
        'supplier_contact',
        'supplier_address',
        'supplier_country',
        'supplier_city',
        'supplier_telephone',
        'supplier_mobile',
        'supplier_fax',
        'supplier_email',
    ];

    /**
     * Shared fields copied when "Same as Exporter" is enabled (excludes zip_code and port_of_loading).
     */
    public const SHARED_PARTY_SUFFIXES = [
        'name',
        'contact',
        'address',
        'city',
        'country',
        'telephone',
        'mobile',
        'fax',
        'email',
    ];

    public const FIELDS = [
        ...self::EXPORTER_FIELDS,
        ...self::LOCATION_FIELDS,
    ];

    protected $fillable = [
        'supplier_id',
        ...self::FIELDS,
        'updated_by',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supplier_id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function hasExporterData(): bool
    {
        foreach (self::EXPORTER_FIELDS as $field) {
            if (trim((string) ($this->{$field} ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }
}
