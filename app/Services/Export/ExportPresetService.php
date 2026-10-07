<?php

namespace App\Services\Export;

use App\Exports\Advanced\ExportDefinition;
use App\Exports\InspectionsExport;
use App\Exports\LocalInvoicesExport;
use App\Exports\PurchaseOrdersExport;
use App\Exports\QuotationsExport;
use App\Exports\RequisitionsExport;
use App\Exports\ShipmentsExport;
use App\Http\Requests\Export\Filters\InspectionExportFilters;
use App\Http\Requests\Export\Filters\LocalInvoiceExportFilters;
use App\Http\Requests\Export\Filters\PurchaseOrderExportFilters;
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
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ExportPresetService
{
    public function definition(string $key, User $actor): ExportDefinition
    {
        try {
            $definition = ExportDefinitions::get($key);
        } catch (InvalidArgumentException) {
            abort(404);
        }
        abort_unless($definition->authorize($actor), 404);

        return $definition;
    }

    public function save(User $actor, array $input, ?ExportPreset $preset = null): ExportPreset
    {
        return DB::transaction(function () use ($actor, $input, $preset): ExportPreset {
            $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            if ($preset !== null) {
                $preset = ExportPreset::query()->where('user_id', $actor->id)->lockForUpdate()->findOrFail($preset->id);
                Gate::forUser($actor)->authorize('update', $preset);
            }
            $key = $preset?->export_key ?? $input['export_key'];
            if (isset($input['export_key']) && $input['export_key'] !== $key) {
                throw ValidationException::withMessages(['export_key' => __('exports.advanced.invalid_columns')]);
            }
            $definition = $this->definition($key, $actor);
            $options = ExportOptionsResolver::resolve($key, $actor, $input);
            $cohort = ExportPreset::query()->where('user_id', $actor->id)->where('export_key', $key);
            if ($preset === null && (clone $cohort)->count() >= (int) config('exports.max_presets_per_key')) {
                throw ValidationException::withMessages(['name' => __('exports.advanced.preset_limit', ['limit' => config('exports.max_presets_per_key')])]);
            }
            $duplicate = (clone $cohort)->where('name', trim($input['name']));
            if ($preset !== null) {
                $duplicate->whereKeyNot($preset->id);
            }
            if ($duplicate->exists()) {
                throw ValidationException::withMessages(['name' => __('exports.advanced.preset_duplicate')]);
            }
            $filters = $this->filters($definition, $input['filters'] ?? []);
            $default = $input['is_default'] ?? $preset?->is_default ?? false;
            if ($default) {
                (clone $cohort)->where('is_default', true)->update(['is_default' => false]);
            }
            $attributes = ['user_id' => $actor->id, 'export_key' => $key, 'name' => trim($input['name']), 'columns' => $options->columns, 'format' => $options->format, 'filters' => $filters, 'is_default' => $default];
            if ($preset === null) {
                return ExportPreset::create($attributes);
            }
            $preset->fill($attributes)->save();

            return $preset;
        }, 3);
    }

    private function filters(ExportDefinition $definition, array $filters): array
    {
        $allowed = array_column($definition->filterSchema(), 'name');
        if (in_array('start_date', $allowed, true)) {
            $allowed[] = 'date_range';
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
            $preset = ExportPreset::query()->where('user_id', $actor->id)->lockForUpdate()->findOrFail($preset->id);
            Gate::forUser($actor)->authorize('update', $preset);
            ExportPreset::query()->where('user_id', $actor->id)->where('export_key', $preset->export_key)->where('is_default', true)->update(['is_default' => false]);
            $preset->forceFill(['is_default' => true])->save();

            return $preset;
        }, 3);
    }

    public function delete(User $actor, ExportPreset $preset): void
    {
        DB::transaction(function () use ($actor, $preset): void {
            $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $preset = ExportPreset::query()->where('user_id', $actor->id)->lockForUpdate()->findOrFail($preset->id);
            Gate::forUser($actor)->authorize('delete', $preset);
            $preset->delete();
        }, 3);
    }

    public function present(ExportPreset $preset, User $actor): array
    {
        $definition = $this->definition($preset->export_key, $actor);
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

        return [
            'id' => $preset->getRouteKey(), 'export_key' => $preset->export_key, 'name' => $preset->name,
            'columns' => $keys, 'filters' => $preset->filters ?? [], 'format' => $format, 'is_default' => $preset->is_default,
            'warnings' => $keys !== $preset->columns || $format !== $preset->format ? [__('exports.advanced.stale_preset')] : [],
        ];
    }
}
