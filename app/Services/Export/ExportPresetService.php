<?php

namespace App\Services\Export;

use App\Exports\Advanced\ExportDefinition;
use App\Exports\InspectionsExport;
use App\Exports\LocalInvoicesExport;
use App\Exports\PurchaseOrdersExport;
use App\Exports\PurchasingHistoricalPriceExport;
use App\Exports\QuotationsExport;
use App\Exports\RequisitionsExport;
use App\Exports\ShipmentsExport;
use App\Http\Requests\Export\Filters\InspectionExportFilters;
use App\Http\Requests\Export\Filters\LocalInvoiceExportFilters;
use App\Http\Requests\Export\Filters\PurchaseOrderExportFilters;
use App\Http\Requests\Export\Filters\PurchasingPriceHistoryExportFilters;
use App\Http\Requests\Export\Filters\QuotationExportFilters;
use App\Http\Requests\Export\Filters\RequisitionExportFilters;
use App\Http\Requests\Export\Filters\ShipmentExportFilters;
use App\Models\ExportPreset;
use App\Models\User;
use App\Support\Export\ExportDefinitions;
use App\Support\Export\ExportOptionsResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ExportPresetService
{
    public function definition(string $key, User $actor, bool $presets = false): ExportDefinition
    {
        try {
            $definition = ExportDefinitions::get($key);
        } catch (InvalidArgumentException) {
            abort(404);
        }
        abort_unless($definition->authorize($actor), 404);
        abort_if($presets && ! ExportDefinitions::supportsColumns($definition), 404);

        return $definition;
    }

    public function save(User $actor, array $input, ?ExportPreset $preset = null): ExportPreset
    {
        return DB::transaction(function () use ($actor, $input, $preset): ExportPreset {
            $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            if ($preset !== null) {
                $preset = ExportPreset::query()->lockForUpdate()->findOrFail($preset->id);
                Gate::forUser($actor)->authorize('update', $preset);
            }

            $key = $preset?->export_key ?? $input['export_key'];
            if (isset($input['export_key']) && $input['export_key'] !== $key) {
                throw ValidationException::withMessages(['export_key' => __('exports.advanced.invalid_columns')]);
            }

            $definition = $this->definition($key, $actor, presets: true);

            $requestedScope = $input['scope'] ?? $preset?->scope ?? ExportPreset::SCOPE_PERSONAL;
            if (! in_array($requestedScope, [ExportPreset::SCOPE_PERSONAL, ExportPreset::SCOPE_TEAM], true)) {
                throw ValidationException::withMessages(['scope' => __('exports.advanced.invalid_scope')]);
            }

            if ($requestedScope === ExportPreset::SCOPE_TEAM) {
                // Suppliers and supplier.* keys are strictly forbidden from team presets.
                abort_if($actor->isSupplier() || str_starts_with($key, 'supplier.'), 403);
                Gate::forUser($actor)->authorize('createTeam', [ExportPreset::class, $key]);
            }

            if ($preset !== null && ($preset->scope ?: ExportPreset::SCOPE_PERSONAL) !== $requestedScope) {
                throw ValidationException::withMessages(['scope' => __('exports.advanced.invalid_scope')]);
            }

            $options = ExportOptionsResolver::resolve($key, $actor, $input);
            $name = trim($input['name']);
            $ownerId = $preset !== null ? (int) $preset->user_id : (int) $actor->id;

            if ($requestedScope === ExportPreset::SCOPE_TEAM) {
                $cohort = ExportPreset::query()
                    ->where('export_key', $key)
                    ->where('scope', ExportPreset::SCOPE_TEAM);
            } else {
                $cohort = ExportPreset::query()
                    ->where('user_id', $actor->id)
                    ->where('export_key', $key)
                    ->where('scope', ExportPreset::SCOPE_PERSONAL);
            }

            if ($preset === null && (clone $cohort)->count() >= (int) config('exports.max_presets_per_key')) {
                throw ValidationException::withMessages(['name' => __('exports.advanced.preset_limit', ['limit' => config('exports.max_presets_per_key')])]);
            }

            $duplicate = (clone $cohort)->where('name', $name);
            if ($preset !== null) {
                $duplicate->whereKeyNot($preset->id);
            }
            if ($duplicate->exists()) {
                throw ValidationException::withMessages(['name' => __('exports.advanced.preset_duplicate')]);
            }

            // Guard the table's (user_id, export_key, name) unique constraint across scopes for the same owner.
            $ownerCollision = ExportPreset::query()
                ->where('user_id', $ownerId)
                ->where('export_key', $key)
                ->where('name', $name);
            if ($preset !== null) {
                $ownerCollision->whereKeyNot($preset->id);
            }
            if ($ownerCollision->exists()) {
                throw ValidationException::withMessages(['name' => __('exports.advanced.preset_duplicate')]);
            }

            $filters = $this->filters($definition, $input['filters'] ?? []);
            $default = $requestedScope === ExportPreset::SCOPE_TEAM
                ? false
                : (bool) ($input['is_default'] ?? $preset?->is_default ?? false);

            if ($default) {
                (clone $cohort)->where('is_default', true)->update(['is_default' => false]);
            }

            $attributes = [
                'user_id' => $ownerId,
                'export_key' => $key,
                'scope' => $requestedScope,
                'name' => $name,
                'columns' => $options->columns,
                'format' => $options->format,
                'csv_delimiter' => $options->csvDelimiter,
                'filters' => $filters,
                'is_default' => $default,
            ];

            if ($preset === null) {
                return ExportPreset::create($attributes);
            }
            $preset->fill($attributes)->save();

            return $preset;
        }, 3);
    }

    public function duplicateToPersonal(User $actor, ExportPreset $source, ?string $preferredName = null): ExportPreset
    {
        return DB::transaction(function () use ($actor, $source, $preferredName): ExportPreset {
            $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $source = ExportPreset::query()->lockForUpdate()->findOrFail($source->id);
            Gate::forUser($actor)->authorize('view', $source);

            $key = $source->export_key;
            $this->definition($key, $actor, presets: true);

            $personalCohort = ExportPreset::query()
                ->where('user_id', $actor->id)
                ->where('export_key', $key)
                ->where('scope', ExportPreset::SCOPE_PERSONAL);

            if ((clone $personalCohort)->count() >= (int) config('exports.max_presets_per_key')) {
                throw ValidationException::withMessages(['name' => __('exports.advanced.preset_limit', ['limit' => config('exports.max_presets_per_key')])]);
            }

            $name = $this->resolveDuplicateName($actor, $key, $preferredName !== null ? trim($preferredName) : $source->name);
            $csvDelimiter = in_array($source->csv_delimiter, [',', ';'], true) ? $source->csv_delimiter : ',';
            $format = in_array($source->format, ['xlsx', 'csv'], true) ? $source->format : 'xlsx';

            return ExportPreset::create([
                'user_id' => $actor->id,
                'export_key' => $key,
                'scope' => ExportPreset::SCOPE_PERSONAL,
                'name' => $name,
                'columns' => $source->columns,
                'filters' => $source->filters,
                'format' => $format,
                'csv_delimiter' => $csvDelimiter,
                'is_default' => false,
            ]);
        }, 3);
    }

    private function resolveDuplicateName(User $actor, string $exportKey, string $baseName): string
    {
        $existing = ExportPreset::query()
            ->where('user_id', $actor->id)
            ->where('export_key', $exportKey)
            ->pluck('name')
            ->all();

        $suffix = ' ('.__('exports.advanced.copy_suffix').')';
        $base = trim($baseName) !== '' ? trim($baseName) : 'Preset';
        $candidate = Str::limit($base, 80 - mb_strlen($suffix), '').$suffix;

        if (! in_array($candidate, $existing, true)) {
            return $candidate;
        }

        for ($counter = 2; $counter <= 99; $counter++) {
            $numberedSuffix = ' ('.__('exports.advanced.copy_suffix').' '.$counter.')';
            $next = Str::limit($base, 80 - mb_strlen($numberedSuffix), '').$numberedSuffix;
            if (! in_array($next, $existing, true)) {
                return $next;
            }
        }

        throw ValidationException::withMessages(['name' => __('exports.advanced.preset_duplicate')]);
    }

    private function filters(ExportDefinition $definition, array $filters): array
    {
        $allowed = array_column($definition->filterSchema(), 'name');
        if (in_array('start_date', $allowed, true)) {
            $allowed[] = 'date_range';
        }
        if (($legacyKey = $this->legacySearchFilterKey($definition->exportClass())) !== null) {
            $allowed[] = $legacyKey;
        }
        if (array_diff(array_keys($filters), $allowed) !== []) {
            throw ValidationException::withMessages(['filters' => __('exports.advanced.invalid_filters')]);
        }
        $request = Request::create('/', 'POST', [...$filters, 'options' => []]);
        $normalized = match ($definition->exportClass()) {
            InspectionsExport::class => InspectionExportFilters::validated($request),
            LocalInvoicesExport::class => LocalInvoiceExportFilters::validated($request, accounting: str_starts_with($definition->key(), 'accounting.')),
            QuotationsExport::class => QuotationExportFilters::validated($request, supplier: str_starts_with($definition->key(), 'supplier.')),
            RequisitionsExport::class => RequisitionExportFilters::validated($request),
            ShipmentsExport::class => ShipmentExportFilters::validated($request),
            PurchaseOrdersExport::class => PurchaseOrderExportFilters::validated($request, supplier: str_starts_with($definition->key(), 'supplier.')),
            PurchasingHistoricalPriceExport::class => PurchasingPriceHistoryExportFilters::validated($request),
            default => throw new InvalidArgumentException('Preset filters are not supported.'),
        };
        // Never persist the resolved integer supplier FK; resolve the hash again on dispatch.
        if (isset($normalized['supplier_id'])) {
            $normalized['supplier_id'] = $filters['supplier_id'];
        }
        if (($filters['date_range']['mode'] ?? null) === 'relative') {
            unset($normalized['start_date'], $normalized['end_date']);
            $normalized['date_range'] = ['mode' => 'relative', 'days' => (int) $filters['date_range']['days']];
        }

        return $normalized;
    }

    public function makeDefault(User $actor, ExportPreset $preset): ExportPreset
    {
        return DB::transaction(function () use ($actor, $preset): ExportPreset {
            $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $preset = ExportPreset::query()->lockForUpdate()->findOrFail($preset->id);
            Gate::forUser($actor)->authorize('makeDefault', $preset);
            ExportPreset::query()
                ->where('user_id', $actor->id)
                ->where('export_key', $preset->export_key)
                ->where('scope', ExportPreset::SCOPE_PERSONAL)
                ->where('is_default', true)
                ->update(['is_default' => false]);
            $preset->forceFill(['is_default' => true])->save();

            return $preset;
        }, 3);
    }

    public function delete(User $actor, ExportPreset $preset): void
    {
        DB::transaction(function () use ($actor, $preset): void {
            $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $preset = ExportPreset::query()->lockForUpdate()->findOrFail($preset->id);
            Gate::forUser($actor)->authorize('delete', $preset);
            $preset->delete();
        }, 3);
    }

    public function present(ExportPreset $preset, User $actor): array
    {
        $definition = $this->definition($preset->export_key, $actor, presets: true);
        $allowed = ExportDefinitions::allowedColumns($definition);
        $keys = array_values(array_unique(array_intersect($preset->columns, array_map(fn ($c) => $c->key, $allowed))));
        if ($keys === []) {
            $keys = ExportDefinitions::defaultKeys($definition);
        } else {
            foreach ($allowed as $column) {
                if ($column->required && ! in_array($column->key, $keys, true)) {
                    array_unshift($keys, $column->key);
                }
            }
        }
        $format = in_array($preset->format, ['xlsx', 'csv'], true) ? $preset->format : 'xlsx';
        $csvDelimiter = in_array($preset->csv_delimiter, [',', ';'], true) ? $preset->csv_delimiter : ',';
        $scope = in_array($preset->scope, [ExportPreset::SCOPE_PERSONAL, ExportPreset::SCOPE_TEAM], true)
            ? $preset->scope
            : ExportPreset::SCOPE_PERSONAL;
        $filters = $preset->filters ?? [];
        $legacyKey = $this->legacySearchFilterKey($definition->exportClass());
        if ($legacyKey !== null && ! empty($filters[$legacyKey])) {
            if (empty($filters['search'])) {
                $filters['search'] = $filters[$legacyKey];
            }
            unset($filters[$legacyKey]);
        }

        return [
            'id' => $preset->getRouteKey(),
            'export_key' => $preset->export_key,
            'scope' => $scope,
            'name' => $preset->name,
            'columns' => $keys,
            'filters' => $filters,
            'format' => $format,
            'csv_delimiter' => $csvDelimiter,
            'is_default' => $scope === ExportPreset::SCOPE_PERSONAL && (bool) $preset->is_default,
            'can_edit' => Gate::forUser($actor)->allows('update', $preset),
            'can_delete' => Gate::forUser($actor)->allows('delete', $preset),
            'warnings' => $keys !== $preset->columns || $format !== $preset->format ? [__('exports.advanced.stale_preset')] : [],
        ];
    }

    private function legacySearchFilterKey(string $exportClass): ?string
    {
        return match ($exportClass) {
            ShipmentsExport::class => 'shipment_number',
            PurchaseOrdersExport::class => 'po_number',
            QuotationsExport::class => 'pr_number',
            default => null,
        };
    }
}
