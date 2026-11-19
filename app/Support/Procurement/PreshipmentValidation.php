<?php

namespace App\Support\Procurement;

use App\Models\ShipmentPreshipmentInspection;
use App\Models\SupplierGoodsLocation;
use App\Models\SupplierPreshipmentProfile;
use App\Support\CountryCatalog;
use App\Support\HashidCodec;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

/**
 * Single source of truth for Pre-Shipment Inspection (PSI) input normalization
 * and Laravel validation rules across shipment create/edit, post-submit PSI
 * updates, and supplier Goods Location master CRUD.
 */
final class PreshipmentValidation
{
    /** International phone/mobile/fax: digits, +, spaces, hyphens, parentheses, dots; 5..30 chars. */
    public const PHONE_REGEX = '/^[0-9+\-\s().]{5,30}$/';

    /** Rejects ASCII control characters while permitting Latin, CJK, and full UTF-8 text. */
    public const SAFE_TEXT_REGEX = '/^[^\x00-\x08\x0B\x0C\x0E-\x1F\x7F]*$/u';

    public const LOCATION_ACTION_NONE = 'none';

    public const LOCATION_ACTION_UPDATE = 'update';

    public const LOCATION_ACTION_CREATE = 'create';

    public const LOCATION_ACTIONS = [
        self::LOCATION_ACTION_NONE,
        self::LOCATION_ACTION_UPDATE,
        self::LOCATION_ACTION_CREATE,
    ];

    /**
     * Normalize PSI array before validation: when `same_as_exporter` is truthy,
     * server copies Exporter fields into Location of Goods (ignoring client-supplied
     * `supplier_*` values, excluding zip_code and port_of_loading).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalizeInput(array $input): array
    {
        $normalized = $input;

        foreach (['exporter_country', 'supplier_country'] as $countryKey) {
            if (isset($normalized[$countryKey]) && is_string($normalized[$countryKey])) {
                $trimmed = trim($normalized[$countryKey]);
                $normalized[$countryKey] = $trimmed === '' ? null : strtoupper($trimmed);
            }
        }

        $sameAsExporter = self::truthy($normalized['same_as_exporter'] ?? $normalized['supplier_same_as_exporter'] ?? false);
        $normalized['same_as_exporter'] = $sameAsExporter;

        if ($sameAsExporter) {
            foreach (SupplierPreshipmentProfile::SHARED_PARTY_SUFFIXES as $suffix) {
                $normalized['supplier_'.$suffix] = $normalized['exporter_'.$suffix] ?? null;
            }
            // When mirroring Exporter, never mutate an unrelated saved goods location.
            $normalized['location_action'] = self::LOCATION_ACTION_NONE;
            $normalized['goods_location'] = null;
        }

        return $normalized;
    }

    /**
     * Validation rules for the nested `preshipment` payload on shipment forms or
     * the post-submit PSI update endpoint.
     *
     * @return array<string, mixed>
     */
    public static function shipmentRules(bool $requireComplete, int $supplierId, bool $allowMasterControl = true): array
    {
        $requiredOrNullable = $requireComplete ? 'required' : 'nullable';
        $isoCodes = CountryCatalog::codes();

        $rules = [
            'preshipment' => [$requireComplete ? 'required' : 'nullable', 'array'],
            'preshipment.same_as_exporter' => ['nullable', 'boolean'],

            // Block 1: Exporter
            'preshipment.exporter_name' => [$requiredOrNullable, 'string', 'max:191', 'regex:'.self::SAFE_TEXT_REGEX],
            'preshipment.exporter_contact' => ['nullable', 'string', 'max:150', 'regex:'.self::SAFE_TEXT_REGEX],
            'preshipment.exporter_address' => [$requiredOrNullable, 'string', 'max:1000', 'regex:'.self::SAFE_TEXT_REGEX],
            'preshipment.exporter_city' => [$requiredOrNullable, 'string', 'max:120', 'regex:'.self::SAFE_TEXT_REGEX],
            'preshipment.exporter_country' => [$requiredOrNullable, 'string', Rule::in($isoCodes)],
            'preshipment.exporter_zip_code' => ['nullable', 'string', 'max:40', 'regex:'.self::SAFE_TEXT_REGEX],
            'preshipment.exporter_telephone' => ['nullable', 'string', 'min:5', 'max:30', 'regex:'.self::PHONE_REGEX],
            'preshipment.exporter_mobile' => ['nullable', 'string', 'min:5', 'max:30', 'regex:'.self::PHONE_REGEX],
            'preshipment.exporter_fax' => ['nullable', 'string', 'min:5', 'max:30', 'regex:'.self::PHONE_REGEX],
            'preshipment.exporter_email' => ['nullable', 'email:rfc', 'max:191'],
            'preshipment.exporter_port_of_loading' => [$requiredOrNullable, 'string', 'max:150', 'regex:'.self::SAFE_TEXT_REGEX],

            // Block 2: Location of Goods
            'preshipment.supplier_name' => [$requiredOrNullable, 'string', 'max:191', 'regex:'.self::SAFE_TEXT_REGEX],
            'preshipment.supplier_contact' => ['nullable', 'string', 'max:150', 'regex:'.self::SAFE_TEXT_REGEX],
            'preshipment.supplier_address' => [$requiredOrNullable, 'string', 'max:1000', 'regex:'.self::SAFE_TEXT_REGEX],
            'preshipment.supplier_city' => [$requiredOrNullable, 'string', 'max:120', 'regex:'.self::SAFE_TEXT_REGEX],
            'preshipment.supplier_country' => [$requiredOrNullable, 'string', Rule::in($isoCodes)],
            'preshipment.supplier_telephone' => ['nullable', 'string', 'min:5', 'max:30', 'regex:'.self::PHONE_REGEX],
            'preshipment.supplier_mobile' => ['nullable', 'string', 'min:5', 'max:30', 'regex:'.self::PHONE_REGEX],
            'preshipment.supplier_fax' => ['nullable', 'string', 'min:5', 'max:30', 'regex:'.self::PHONE_REGEX],
            'preshipment.supplier_email' => ['nullable', 'email:rfc', 'max:191'],

            // Provenance: hashed ID of supplier's own active goods location
            'preshipment.goods_location' => [
                'nullable',
                'string',
                'max:64',
                function (string $attribute, mixed $value, \Closure $fail) use ($supplierId): void {
                    if ($value === null || trim((string) $value) === '') {
                        return;
                    }
                    $decoded = self::decodeOwnedLocationId((string) $value, $supplierId);
                    if ($decoded === null) {
                        $fail(__('shipments.preshipment.invalid_location'));
                    }
                },
            ],
        ];

        if ($allowMasterControl) {
            $rules['preshipment.save_exporter_default'] = ['nullable', 'boolean'];
            $rules['preshipment.location_action'] = ['nullable', 'string', Rule::in(self::LOCATION_ACTIONS)];
            $rules['preshipment.set_location_default'] = ['nullable', 'boolean'];
        }

        return $rules;
    }

    /**
     * Validation rules for standalone Goods Location master CRUD.
     *
     * @return array<string, mixed>
     */
    public static function locationRules(): array
    {
        $isoCodes = CountryCatalog::codes();

        return [
            'name' => ['required', 'string', 'max:191', 'regex:'.self::SAFE_TEXT_REGEX],
            'contact' => ['nullable', 'string', 'max:150', 'regex:'.self::SAFE_TEXT_REGEX],
            'address' => ['required', 'string', 'max:1000', 'regex:'.self::SAFE_TEXT_REGEX],
            'city' => ['required', 'string', 'max:120', 'regex:'.self::SAFE_TEXT_REGEX],
            'country' => ['required', 'string', Rule::in($isoCodes)],
            'telephone' => ['nullable', 'string', 'min:5', 'max:30', 'regex:'.self::PHONE_REGEX],
            'mobile' => ['nullable', 'string', 'min:5', 'max:30', 'regex:'.self::PHONE_REGEX],
            'fax' => ['nullable', 'string', 'min:5', 'max:30', 'regex:'.self::PHONE_REGEX],
            'email' => ['nullable', 'email:rfc', 'max:191'],
            'is_default' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Decode a hashed goods_location route key and confirm it belongs to this supplier
     * and is not soft-deleted. Rejects raw integer strings.
     */
    public static function decodeOwnedLocationId(?string $hash, int $supplierId): ?int
    {
        if ($hash === null) {
            return null;
        }

        $trimmed = trim($hash);
        if ($trimmed === '' || ctype_digit($trimmed)) {
            return null;
        }

        $id = HashidCodec::decode($trimmed);
        if ($id === null) {
            return null;
        }

        $exists = SupplierGoodsLocation::query()
            ->whereKey($id)
            ->where('supplier_id', $supplierId)
            ->exists();

        return $exists ? $id : null;
    }

    /**
     * When saving a Draft with `location_action` set to `create` or `update` and
     * any Block 2 field populated, ensure all required location fields and
     * active-location quota are satisfied rather than silently skipping master save.
     *
     * @param  array<string, mixed>  $preshipmentInput
     */
    public static function validateMasterLocationActionOnDraft(
        Validator $validator,
        array $preshipmentInput,
        int $supplierId
    ): void {
        if ($preshipmentInput === []) {
            return;
        }

        $normalized = self::normalizeInput($preshipmentInput);
        if ((bool) ($normalized['same_as_exporter'] ?? false)) {
            return;
        }

        $action = (string) ($normalized['location_action'] ?? self::LOCATION_ACTION_NONE);
        if (! in_array($action, [self::LOCATION_ACTION_CREATE, self::LOCATION_ACTION_UPDATE], true)) {
            return;
        }

        $hasAnyLocationInput = false;
        foreach (SupplierPreshipmentProfile::LOCATION_FIELDS as $field) {
            $val = $normalized[$field] ?? null;
            if (is_string($val) && trim($val) !== '') {
                $hasAnyLocationInput = true;
                break;
            }
        }

        if (! $hasAnyLocationInput) {
            return;
        }

        foreach (ShipmentPreshipmentInspection::REQUIRED_LOCATION_FIELDS as $requiredField) {
            $val = $normalized[$requiredField] ?? null;
            if ($val === null || trim((string) $val) === '') {
                $key = "preshipment.{$requiredField}";
                if (! $validator->errors()->has($key)) {
                    $labelKey = substr($requiredField, strlen('supplier_'));
                    $validator->errors()->add($key, __('validation.required', ['attribute' => __("shipments.preshipment.{$labelKey}")]));
                }
            }
        }

        if ($action === self::LOCATION_ACTION_UPDATE && trim((string) ($normalized['goods_location'] ?? '')) === '') {
            $validator->errors()->add('preshipment.location_action', __('shipments.preshipment.invalid_location'));
        }

        if ($action === self::LOCATION_ACTION_CREATE && $supplierId > 0) {
            $activeCount = SupplierGoodsLocation::query()->forSupplier($supplierId)->count();
            if ($activeCount >= SupplierGoodsLocation::MAX_ACTIVE_PER_SUPPLIER) {
                $validator->errors()->add('preshipment.location_action', __('shipments.preshipment.location_limit_reached', [
                    'max' => SupplierGoodsLocation::MAX_ACTIVE_PER_SUPPLIER,
                ]));
            }
        }
    }

    public static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }
}
