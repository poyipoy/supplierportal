<?php

namespace App\Services\Procurement;

use App\Exceptions\BusinessRuleArgumentException;
use App\Models\Shipment;
use App\Models\ShipmentPreshipmentInspection;
use App\Models\SupplierGoodsLocation;
use App\Models\SupplierPreshipmentProfile;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\BusinessTime;
use App\Support\CountryCatalog;
use App\Support\NotificationCategory;
use App\Support\Procurement\PreshipmentValidation;
use App\Support\Procurement\ProcurementFeatures;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Owns all Pre-Shipment Inspection (PSI) persistence, master Exporter profile
 * and multi-Location of Goods lifecycle, snapshot immutability, post-submit
 * revision tracking, and cross-supplier isolation invariants.
 */
class PreshipmentInspectionService
{
    public function __construct(
        protected NotificationService $notifications
    ) {}

    /**
     * Persist a shipment's PSI snapshot (inside an open transaction where the
     * shipment row is already locked) and optionally update supplier master data
     * when the shipment is still a Draft and the supplier explicitly opted in.
     *
     * Lock order invariant: Shipment -> (PurchaseOrders) -> User.
     *
     * @param  array<string, mixed>  $rawInput
     */
    public function persistForShipment(
        Shipment $lockedShipment,
        User $supplier,
        array $rawInput,
        bool $allowMasterUpdates
    ): ?ShipmentPreshipmentInspection {
        $normalized = PreshipmentValidation::normalizeInput($rawInput);
        $sameAsExporter = (bool) ($normalized['same_as_exporter'] ?? false);

        $values = [];
        foreach (SupplierPreshipmentProfile::FIELDS as $field) {
            $values[$field] = $this->nullableTrim($normalized[$field] ?? null);
        }

        $hasAnyValue = array_filter($values, static fn ($value): bool => $value !== null) !== [];
        $existing = ShipmentPreshipmentInspection::query()
            ->where('shipment_id', $lockedShipment->id)
            ->lockForUpdate()
            ->first();

        if (! $hasAnyValue) {
            if ($existing !== null && $lockedShipment->status === Shipment::STATUS_DRAFT) {
                $existing->delete();
            }

            if ($existing === null || $lockedShipment->status === Shipment::STATUS_DRAFT) {
                return null;
            }
        }

        $locationAction = $sameAsExporter
            ? PreshipmentValidation::LOCATION_ACTION_NONE
            : (string) ($normalized['location_action'] ?? PreshipmentValidation::LOCATION_ACTION_NONE);

        $selectedLocation = null;
        if (! $sameAsExporter && ! empty($normalized['goods_location'])) {
            $selectedLocationId = PreshipmentValidation::decodeOwnedLocationId(
                (string) $normalized['goods_location'],
                (int) $supplier->id
            );
            if ($selectedLocationId !== null) {
                $selectedLocation = SupplierGoodsLocation::query()
                    ->forSupplier((int) $supplier->id)
                    ->whereKey($selectedLocationId)
                    ->first();
            }
        }

        $snapshotLocationId = $selectedLocation?->id;
        if (
            $snapshotLocationId !== null
            && $selectedLocation !== null
            && $locationAction !== PreshipmentValidation::LOCATION_ACTION_UPDATE
            && $this->locationFieldsDifferFromMaster($selectedLocation, $values)
        ) {
            // Edited Block 2 fields without updating the master record: detach provenance so Edit/Detail stays accurate.
            $snapshotLocationId = null;
        }

        $revisionAttributes = [];
        $newRevisionForNotification = null;

        if ($lockedShipment->status === Shipment::STATUS_SUBMITTED && $existing !== null) {
            if ($this->hasSnapshotChanged($existing, $values, $sameAsExporter)) {
                $nextRevision = ((int) $existing->revision_count) + 1;
                $revisionAttributes = [
                    'revision_count' => $nextRevision,
                    'last_revised_at' => BusinessTime::now(),
                ];
                $newRevisionForNotification = $nextRevision;
            }
        }

        $snapshotPayload = [
            ...$values,
            'goods_location_id' => $sameAsExporter ? null : $snapshotLocationId,
            'supplier_same_as_exporter' => $sameAsExporter,
            'updated_by' => $supplier->id,
            ...$revisionAttributes,
        ];

        $snapshot = ShipmentPreshipmentInspection::query()->updateOrCreate(
            ['shipment_id' => $lockedShipment->id],
            $snapshotPayload
        );

        // Master updates are strictly permitted only while Draft and only when explicitly requested.
        if ($allowMasterUpdates && $lockedShipment->status === Shipment::STATUS_DRAFT) {
            $saveExporterDefault = PreshipmentValidation::truthy($normalized['save_exporter_default'] ?? false);
            $setLocationDefault = PreshipmentValidation::truthy($normalized['set_location_default'] ?? false);

            $needsExporterWrite = $saveExporterDefault && $this->hasNonEmptyExporterFields($values);
            $needsLocationWrite = in_array($locationAction, [
                PreshipmentValidation::LOCATION_ACTION_UPDATE,
                PreshipmentValidation::LOCATION_ACTION_CREATE,
            ], true) && $this->hasRequiredLocationFields($values);

            if ($needsExporterWrite || $needsLocationWrite) {
                User::query()->whereKey($supplier->id)->lockForUpdate()->firstOrFail();

                if ($needsExporterWrite) {
                    $exporterPayload = [];
                    foreach (SupplierPreshipmentProfile::EXPORTER_FIELDS as $field) {
                        $exporterPayload[$field] = $values[$field];
                    }
                    $exporterPayload['updated_by'] = $supplier->id;

                    SupplierPreshipmentProfile::query()->updateOrCreate(
                        ['supplier_id' => $supplier->id],
                        $exporterPayload
                    );
                }

                if ($needsLocationWrite) {
                    $locationData = [
                        'name' => (string) $values['supplier_name'],
                        'contact' => $values['supplier_contact'],
                        'address' => (string) $values['supplier_address'],
                        'country' => (string) $values['supplier_country'],
                        'city' => (string) $values['supplier_city'],
                        'telephone' => $values['supplier_telephone'],
                        'mobile' => $values['supplier_mobile'],
                        'fax' => $values['supplier_fax'],
                        'email' => $values['supplier_email'],
                    ];

                    if ($locationAction === PreshipmentValidation::LOCATION_ACTION_UPDATE && $selectedLocation !== null) {
                        $ownedLocation = SupplierGoodsLocation::query()
                            ->whereKey($selectedLocation->id)
                            ->where('supplier_id', $supplier->id)
                            ->first();

                        if ($ownedLocation !== null) {
                            $this->applyLocationUpdateWithinUserLock($ownedLocation, $supplier, $locationData, $setLocationDefault);
                        }
                    } elseif ($locationAction === PreshipmentValidation::LOCATION_ACTION_CREATE) {
                        $created = $this->applyLocationCreateWithinUserLock($supplier, $locationData, $setLocationDefault);
                        $snapshot->update(['goods_location_id' => $created->id]);
                    }
                }
            }
        }

        if ($newRevisionForNotification !== null) {
            $shipmentId = (int) $lockedShipment->id;
            $revisionNumber = $newRevisionForNotification;
            DB::afterCommit(function () use ($shipmentId, $revisionNumber): void {
                $freshShipment = Shipment::with('supplier')->find($shipmentId);
                if ($freshShipment !== null) {
                    $this->notifyPreshipmentRevised($freshShipment, $revisionNumber);
                }
            });
        }

        return $snapshot->fresh();
    }

    /**
     * Assert the shipment has a complete PSI snapshot before leaving Draft when
     * the feature flag is active.
     *
     * @throws BusinessRuleArgumentException
     */
    public function assertCompleteForSubmission(Shipment $lockedShipment): void
    {
        if (! ProcurementFeatures::requirePreshipmentForShipment()) {
            return;
        }

        $inspection = ShipmentPreshipmentInspection::query()
            ->where('shipment_id', $lockedShipment->id)
            ->first();

        if (! $inspection || ! $inspection->isCompleteForSubmission()) {
            throw new BusinessRuleArgumentException(__('shipments.preshipment.required_to_submit'));
        }
    }

    /**
     * Build prefill state, saved locations, country catalog, and port suggestions
     * scoped strictly to the authenticated supplier.
     *
     * @return array{
     *     values: array<string, string|null>,
     *     same_as_exporter: bool,
     *     selected_location_hash: string|null,
     *     reused: bool,
     *     default_save_exporter: bool,
     *     has_any_location: bool,
     *     active_locations_count: int,
     *     locations: list<array<string, mixed>>,
     *     port_suggestions: list<string>,
     *     countries: array<string, string>,
     *     legacy_unmapped_countries: array<string, string>,
     *     max_locations: int
     * }
     */
    public function prefillFor(User $supplier, ?Shipment $shipment = null): array
    {
        $profile = SupplierPreshipmentProfile::query()
            ->where('supplier_id', $supplier->id)
            ->first();

        /** @var Collection<int, SupplierGoodsLocation> $locations */
        $locations = SupplierGoodsLocation::query()
            ->forSupplier((int) $supplier->id)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        $defaultLocation = $locations->firstWhere('is_default', true);
        $snapshot = $shipment?->preshipmentInspection;

        $values = array_fill_keys(SupplierPreshipmentProfile::FIELDS, null);
        $sameAsExporter = false;
        $selectedLocationHash = null;
        $reused = false;

        if ($snapshot !== null) {
            foreach (SupplierPreshipmentProfile::FIELDS as $field) {
                $values[$field] = $snapshot->{$field};
            }
            $sameAsExporter = (bool) $snapshot->supplier_same_as_exporter;
            if (! $sameAsExporter && $snapshot->goods_location_id !== null) {
                $matched = $locations->firstWhere('id', (int) $snapshot->goods_location_id);
                $selectedLocationHash = $matched?->getRouteKey();
            }
        } else {
            if ($profile !== null && $profile->hasExporterData()) {
                foreach (SupplierPreshipmentProfile::EXPORTER_FIELDS as $field) {
                    $values[$field] = $profile->{$field};
                }
                $reused = true;
            }

            if ($defaultLocation !== null) {
                foreach ($defaultLocation->toSnapshotAttributes() as $field => $val) {
                    $values[$field] = $val;
                }
                $selectedLocationHash = $defaultLocation->getRouteKey();
                $reused = true;
            } elseif ($profile !== null) {
                foreach (SupplierPreshipmentProfile::LOCATION_FIELDS as $field) {
                    if (trim((string) ($profile->{$field} ?? '')) !== '') {
                        $values[$field] = $profile->{$field};
                        $reused = true;
                    }
                }
            }
        }

        $legacyUnmappedCountries = [];
        foreach (['exporter_country', 'supplier_country'] as $countryField) {
            $rawCountry = $this->nullableTrim($values[$countryField] ?? null);
            if ($rawCountry === null) {
                continue;
            }
            $normalizedCode = CountryCatalog::normalizeCode($rawCountry);
            if ($normalizedCode !== null) {
                $values[$countryField] = $normalizedCode;
            } else {
                $legacyUnmappedCountries[$countryField] = $rawCountry;
                $values[$countryField] = null;
            }
        }

        $locationPayload = $locations
            ->map(fn (SupplierGoodsLocation $loc): array => $this->formatLocationPayload($loc))
            ->values()
            ->all();

        return [
            'values' => $values,
            'same_as_exporter' => $sameAsExporter,
            'selected_location_hash' => $selectedLocationHash,
            'reused' => $reused,
            'default_save_exporter' => ! ($profile?->hasExporterData() ?? false),
            'has_any_location' => $locations->isNotEmpty(),
            'active_locations_count' => $locations->count(),
            'locations' => $locationPayload,
            'port_suggestions' => $this->portSuggestionsFor((int) $supplier->id, $profile),
            'countries' => CountryCatalog::options(),
            'legacy_unmapped_countries' => $legacyUnmappedCountries,
            'max_locations' => SupplierGoodsLocation::MAX_ACTIVE_PER_SUPPLIER,
        ];
    }

    /**
     * Scoped saved-location options payload used for dynamic cross-tab refresh inside PSI modals.
     *
     * @return array{locations: list<array<string, mixed>>, active_count: int, max_locations: int}
     */
    public function locationOptionsFor(User $supplier): array
    {
        $locations = SupplierGoodsLocation::query()
            ->forSupplier((int) $supplier->id)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return [
            'locations' => $locations
                ->map(fn (SupplierGoodsLocation $loc): array => $this->formatLocationPayload($loc))
                ->values()
                ->all(),
            'active_count' => $locations->count(),
            'max_locations' => SupplierGoodsLocation::MAX_ACTIVE_PER_SUPPLIER,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatLocationPayload(SupplierGoodsLocation $loc): array
    {
        $rawCountry = $this->nullableTrim($loc->country);
        $normalizedCountry = CountryCatalog::normalizeCode($rawCountry);

        return [
            'hash' => $loc->getRouteKey(),
            'name' => $loc->name,
            'contact' => $loc->contact ?? '',
            'address' => $loc->address,
            'city' => $loc->city,
            'country' => $normalizedCountry ?? '',
            'country_unmapped' => $rawCountry !== null && $normalizedCountry === null ? $rawCountry : '',
            'country_label' => CountryCatalog::label($normalizedCountry ?? $rawCountry),
            'telephone' => $loc->telephone ?? '',
            'mobile' => $loc->mobile ?? '',
            'fax' => $loc->fax ?? '',
            'email' => $loc->email ?? '',
            'is_default' => (bool) $loc->is_default,
            'update_url' => route('supplier.goods-locations.update', $loc, false),
            'default_url' => route('supplier.goods-locations.default', $loc, false),
            'destroy_url' => route('supplier.goods-locations.destroy', $loc, false),
        ];
    }

    /**
     * Distinct ports of loading previously used by this supplier (never cross-supplier).
     *
     * @return list<string>
     */
    public function portSuggestionsFor(int $supplierId, ?SupplierPreshipmentProfile $profile = null): array
    {
        $ports = [];
        $profilePort = $this->nullableTrim($profile?->exporter_port_of_loading);
        if ($profilePort !== null) {
            $ports[] = $profilePort;
        }

        $historical = DB::table('shipment_preshipment_inspections as spi')
            ->join('shipments as s', 's.id', '=', 'spi.shipment_id')
            ->where('s.supplier_id', $supplierId)
            ->whereNull('s.deleted_at')
            ->whereNotNull('spi.exporter_port_of_loading')
            ->where('spi.exporter_port_of_loading', '<>', '')
            ->orderByDesc('spi.updated_at')
            ->limit(40)
            ->pluck('spi.exporter_port_of_loading');

        foreach ($historical as $port) {
            $trimmed = $this->nullableTrim($port);
            if ($trimmed !== null) {
                $ports[] = $trimmed;
            }
        }

        return array_slice(array_values(array_unique($ports)), 0, 20);
    }

    /**
     * Create a new Goods Location for a supplier under an exclusive User row lock.
     *
     * @param  array<string, mixed>  $data
     */
    public function createLocation(User $supplier, array $data): SupplierGoodsLocation
    {
        return DB::transaction(function () use ($supplier, $data): SupplierGoodsLocation {
            User::query()->whereKey($supplier->id)->lockForUpdate()->firstOrFail();

            return $this->applyLocationCreateWithinUserLock(
                $supplier,
                $this->cleanLocationInput($data),
                PreshipmentValidation::truthy($data['is_default'] ?? false)
            );
        });
    }

    /**
     * Update an existing supplier-owned Goods Location under an exclusive User row lock.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateLocation(SupplierGoodsLocation $location, User $supplier, array $data): SupplierGoodsLocation
    {
        return DB::transaction(function () use ($location, $supplier, $data): SupplierGoodsLocation {
            User::query()->whereKey($supplier->id)->lockForUpdate()->firstOrFail();

            $locked = SupplierGoodsLocation::query()
                ->whereKey($location->id)
                ->where('supplier_id', $supplier->id)
                ->firstOrFail();

            return $this->applyLocationUpdateWithinUserLock(
                $locked,
                $supplier,
                $this->cleanLocationInput($data),
                array_key_exists('is_default', $data) ? PreshipmentValidation::truthy($data['is_default']) : null
            );
        });
    }

    /**
     * Mark one active location as the sole default for its supplier.
     */
    public function setDefaultLocation(SupplierGoodsLocation $location, User $supplier): SupplierGoodsLocation
    {
        return DB::transaction(function () use ($location, $supplier): SupplierGoodsLocation {
            User::query()->whereKey($supplier->id)->lockForUpdate()->firstOrFail();

            $locked = SupplierGoodsLocation::query()
                ->whereKey($location->id)
                ->where('supplier_id', $supplier->id)
                ->firstOrFail();

            SupplierGoodsLocation::query()
                ->forSupplier((int) $supplier->id)
                ->where('is_default', true)
                ->whereKeyNot($locked->id)
                ->update(['is_default' => false]);

            $locked->update([
                'is_default' => true,
                'updated_by' => $supplier->id,
            ]);

            return $locked->fresh();
        });
    }

    /**
     * Soft-delete a supplier-owned Goods Location. Does not auto-promote another
     * location when the default is deleted, and never alters existing shipment snapshots.
     */
    public function deleteLocation(SupplierGoodsLocation $location, User $supplier): void
    {
        DB::transaction(function () use ($location, $supplier): void {
            User::query()->whereKey($supplier->id)->lockForUpdate()->firstOrFail();

            $locked = SupplierGoodsLocation::query()
                ->whereKey($location->id)
                ->where('supplier_id', $supplier->id)
                ->firstOrFail();

            if ($locked->is_default) {
                $locked->update([
                    'is_default' => false,
                    'updated_by' => $supplier->id,
                ]);
            }

            $locked->delete();
        });
    }

    /**
     * @param  array<string, string|null>  $locationData
     */
    private function applyLocationCreateWithinUserLock(
        User $supplier,
        array $locationData,
        bool $requestedDefault
    ): SupplierGoodsLocation {
        $activeCount = SupplierGoodsLocation::query()
            ->forSupplier((int) $supplier->id)
            ->count();

        if ($activeCount >= SupplierGoodsLocation::MAX_ACTIVE_PER_SUPPLIER) {
            throw new BusinessRuleArgumentException(__('shipments.preshipment.location_limit_reached', [
                'max' => SupplierGoodsLocation::MAX_ACTIVE_PER_SUPPLIER,
            ]));
        }

        // First active location for a supplier automatically becomes default.
        $isDefault = $activeCount === 0 || $requestedDefault;

        if ($isDefault) {
            SupplierGoodsLocation::query()
                ->forSupplier((int) $supplier->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }

        return SupplierGoodsLocation::create([
            ...$locationData,
            'supplier_id' => $supplier->id,
            'is_default' => $isDefault,
            'created_by' => $supplier->id,
            'updated_by' => $supplier->id,
        ]);
    }

    /**
     * @param  array<string, string|null>  $locationData
     */
    private function applyLocationUpdateWithinUserLock(
        SupplierGoodsLocation $location,
        User $supplier,
        array $locationData,
        ?bool $requestedDefault
    ): SupplierGoodsLocation {
        $attributes = [
            ...$locationData,
            'updated_by' => $supplier->id,
        ];

        if ($requestedDefault === true) {
            SupplierGoodsLocation::query()
                ->forSupplier((int) $supplier->id)
                ->where('is_default', true)
                ->whereKeyNot($location->id)
                ->update(['is_default' => false]);
            $attributes['is_default'] = true;
        } elseif ($requestedDefault === false) {
            $attributes['is_default'] = false;
        }

        $location->update($attributes);

        return $location->fresh();
    }

    /**
     * @param  array<string, string|null>  $values
     */
    private function hasSnapshotChanged(
        ShipmentPreshipmentInspection $existing,
        array $values,
        bool $sameAsExporter
    ): bool {
        if ((bool) $existing->supplier_same_as_exporter !== $sameAsExporter) {
            return true;
        }

        foreach (SupplierPreshipmentProfile::FIELDS as $field) {
            if ($this->nullableTrim($existing->{$field}) !== $values[$field]) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, string|null>  $values
     */
    private function locationFieldsDifferFromMaster(
        SupplierGoodsLocation $location,
        array $values
    ): bool {
        foreach (SupplierGoodsLocation::FIELDS as $field) {
            $masterVal = $this->nullableTrim($location->{$field});
            if ($field === 'country' && $masterVal !== null) {
                $masterVal = CountryCatalog::normalizeCode($masterVal) ?? $masterVal;
            }
            if ($masterVal !== ($values['supplier_'.$field] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, string|null>  $values
     */
    private function hasNonEmptyExporterFields(array $values): bool
    {
        foreach (SupplierPreshipmentProfile::EXPORTER_FIELDS as $field) {
            if ($values[$field] !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, string|null>  $values
     */
    private function hasRequiredLocationFields(array $values): bool
    {
        foreach (ShipmentPreshipmentInspection::REQUIRED_LOCATION_FIELDS as $field) {
            if ($values[$field] === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string|null>
     */
    private function cleanLocationInput(array $data): array
    {
        $clean = [];
        foreach (SupplierGoodsLocation::FIELDS as $field) {
            $value = $this->nullableTrim($data[$field] ?? null);
            if ($field === 'country' && $value !== null) {
                $value = strtoupper($value);
            }
            $clean[$field] = $value;
        }

        return $clean;
    }

    private function notifyPreshipmentRevised(Shipment $shipment, int $revision): void
    {
        $purchasingUsers = User::where('role', 'purchasing')->where('is_active', true)->get();
        if ($purchasingUsers->isEmpty()) {
            return;
        }

        $this->notifications->send(
            $purchasingUsers,
            'shipment.preshipment_revised',
            "shipment.preshipment_revised:{$shipment->id}:{$revision}",
            'shipments.notify.preshipment_revised_title',
            'shipments.notify.preshipment_revised_body',
            route('purchasing.shipments.show', $shipment, absolute: false),
            'clipboard-check text-warning',
            [
                'category' => NotificationCategory::OTHER,
                'shipment_id' => $shipment->id,
                'shipment_number' => $shipment->shipment_number,
                'revision' => $revision,
            ],
            [
                'supplier' => $shipment->supplier?->name ?? '-',
                'shipment' => $shipment->shipment_number,
                'revision' => $revision,
            ],
        );
    }

    private function nullableTrim(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
