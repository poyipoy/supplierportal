<?php

namespace App\Http\Requests\Export\Filters;

use App\Models\ExchangeRate;
use App\Models\PrItem;
use App\Models\ReferencePriceRule;
use App\Models\User;
use App\Services\Pricing\HistoricalPriceQueryService;
use App\Support\BusinessTime;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class PurchasingPriceHistoryExportFilters
{
    public const RANGE_OPTIONS = ['all', '3m', '6m', '12m', '24m', '1y', '2y', '5y'];

    public const CHANGE_DIRECTIONS = ['increased', 'decreased', 'unchanged'];

    public const SORT_DIRECTIONS = ['desc', 'asc'];

    public static function validated(Request $request): array
    {
        $data = $request->all();

        foreach (['materials', 'categories', 'source_currencies'] as $arrayKey) {
            if (isset($data[$arrayKey]) && is_string($data[$arrayKey])) {
                $trimmed = trim($data[$arrayKey]);
                $data[$arrayKey] = $trimmed !== '' ? [$trimmed] : [];
            }
        }

        if (isset($data['source_currencies']) && is_array($data['source_currencies'])) {
            $data['source_currencies'] = array_values(array_filter(array_map(
                static fn ($c) => is_scalar($c) ? strtoupper(trim((string) $c)) : '',
                $data['source_currencies']
            )));
        }

        if (isset($data['currency']) && is_string($data['currency']) && trim($data['currency']) !== '') {
            $data['currency'] = strtoupper(trim($data['currency']));
        }

        if (isset($data['change_direction']) && is_string($data['change_direction'])) {
            $trimmedDir = strtolower(trim($data['change_direction']));
            $data['change_direction'] = $trimmedDir !== '' ? $trimmedDir : null;
        }

        if (isset($data['sort_dir']) && is_string($data['sort_dir'])) {
            $trimmedSort = strtolower(trim($data['sort_dir']));
            $data['sort_dir'] = $trimmedSort !== '' ? $trimmedSort : null;
        }

        $dateRule = $request->input('options') !== null ? 'date_format:Y-m-d' : 'date';
        $activeCategoryKeys = ReferencePriceRule::query()->active()->pluck('category_key')->all();

        $filters = validator($data, [
            'supplier_id' => ['nullable', 'string', 'max:255'],
            'materials' => ['nullable', 'array', 'max:50'],
            'materials.*' => ['string', 'max:255'],
            'material_name' => ['nullable', 'string', 'max:255'],
            'shape' => ['nullable', Rule::in(PrItem::SHAPES)],
            'categories' => ['nullable', 'array', 'max:50'],
            'categories.*' => ['string', 'max:100', Rule::in($activeCategoryKeys)],
            'source_currencies' => ['nullable', 'array', 'max:20'],
            'source_currencies.*' => ['string', Rule::in(ExchangeRate::CURRENCIES)],
            'doc_search' => ['nullable', 'string', 'max:100'],
            'currency' => ['nullable', Rule::in(ExchangeRate::CURRENCIES)],
            'period_view' => ['nullable', Rule::in(['monthly', 'yearly'])],
            'range' => ['nullable', Rule::in(self::RANGE_OPTIONS)],
            'key_min' => ['nullable', 'numeric', 'min:0'],
            'key_max' => ['nullable', 'numeric', 'min:0'],
            'price_min' => ['nullable', 'numeric', 'min:0'],
            'price_max' => ['nullable', 'numeric', 'min:0'],
            'change_direction' => ['nullable', Rule::in(self::CHANGE_DIRECTIONS)],
            'sort_dir' => ['nullable', Rule::in(self::SORT_DIRECTIONS)],
            'start_date' => ['nullable', $dateRule],
            'end_date' => ['nullable', $dateRule],
            'date_range' => ['sometimes', 'array:mode,days'],
            'date_range.mode' => ['required_with:date_range', Rule::in(['relative', 'absolute'])],
            'date_range.days' => ['required_if:date_range.mode,relative', 'integer', 'min:1', 'max:3650'],
        ])->validate();

        if (($filters['date_range']['mode'] ?? null) === 'relative') {
            $filters['end_date'] = BusinessTime::today()->toDateString();
            $filters['start_date'] = BusinessTime::today()->subDays($filters['date_range']['days'] - 1)->toDateString();
        }
        unset($filters['date_range']);

        if (! empty($filters['start_date']) && ! empty($filters['end_date']) && $filters['end_date'] < $filters['start_date']) {
            throw ValidationException::withMessages(['end_date' => __('purchasing.copy.end_date_cannot_be_before_start_date')]);
        }

        if (! empty($filters['supplier_id'])) {
            $id = $filters['supplier_id'];
            abort_unless(! ctype_digit($id), 404);
            $owner = (new User)->resolveRouteBinding($id);
            abort_unless($owner instanceof User && $owner->role === 'supplier', 404);
            $filters['supplier_id'] = (int) $owner->id;
        } else {
            unset($filters['supplier_id']);
        }

        $materials = [];
        foreach ($filters['materials'] ?? [] as $entry) {
            $clean = trim((string) $entry);
            if ($clean !== '' && ! in_array($clean, $materials, true)) {
                $materials[] = $clean;
            }
        }
        if ($materials === [] && ! empty($filters['material_name'])) {
            $materials[] = HistoricalPriceQueryService::encodeMaterialKey(
                trim((string) $filters['material_name']),
                ! empty($filters['shape']) ? (string) $filters['shape'] : null
            );
        }
        unset($filters['material_name'], $filters['shape']);
        if ($materials !== []) {
            $filters['materials'] = $materials;
        } else {
            unset($filters['materials']);
        }

        $categories = array_values(array_unique(array_filter(array_map(
            static fn ($c) => trim((string) $c),
            $filters['categories'] ?? []
        ))));
        if ($categories !== []) {
            $filters['categories'] = $categories;
        } else {
            unset($filters['categories']);
        }

        $sourceCurrencies = array_values(array_unique(array_filter(array_map(
            static fn ($c) => strtoupper(trim((string) $c)),
            $filters['source_currencies'] ?? []
        ))));
        if ($sourceCurrencies !== []) {
            $filters['source_currencies'] = $sourceCurrencies;
        } else {
            unset($filters['source_currencies']);
        }

        $docSearch = trim((string) ($filters['doc_search'] ?? ''));
        if ($docSearch !== '') {
            $filters['doc_search'] = $docSearch;
        } else {
            unset($filters['doc_search']);
        }

        $min = isset($filters['key_min']) && $filters['key_min'] !== '' ? Money::normalize((string) $filters['key_min'], 4) : null;
        $max = isset($filters['key_max']) && $filters['key_max'] !== '' ? Money::normalize((string) $filters['key_max'], 4) : null;
        $min ??= $max;
        $max ??= $min;
        if ($min !== null && bccomp($min, $max, 4) > 0) {
            [$min, $max] = [$max, $min];
        }
        if ($min !== null) {
            $filters['key_min'] = $min;
            $filters['key_max'] = $max;
        } else {
            unset($filters['key_min'], $filters['key_max']);
        }

        $priceMin = isset($filters['price_min']) && $filters['price_min'] !== '' ? Money::normalize((string) $filters['price_min'], 4) : null;
        $priceMax = isset($filters['price_max']) && $filters['price_max'] !== '' ? Money::normalize((string) $filters['price_max'], 4) : null;
        if ($priceMin !== null && $priceMax !== null && bccomp($priceMin, $priceMax, 4) > 0) {
            [$priceMin, $priceMax] = [$priceMax, $priceMin];
        }
        if ($priceMin !== null) {
            $filters['price_min'] = $priceMin;
        } else {
            unset($filters['price_min']);
        }
        if ($priceMax !== null) {
            $filters['price_max'] = $priceMax;
        } else {
            unset($filters['price_max']);
        }

        if (empty($filters['change_direction'])) {
            unset($filters['change_direction']);
        }

        $filters['currency'] = $filters['currency'] ?? 'IDR';
        $filters['period_view'] = ($filters['period_view'] ?? 'monthly') === 'yearly' ? 'yearly' : 'monthly';
        $filters['range'] = $filters['range'] ?? 'all';
        $filters['sort_dir'] = HistoricalPriceQueryService::normalizeSortDirection($filters['sort_dir'] ?? null);

        return $filters;
    }

    public static function schema(): array
    {
        return [
            ['name' => 'start_date', 'type' => 'date'],
            ['name' => 'end_date', 'type' => 'date'],
            ['name' => 'supplier_id', 'type' => 'supplier'],
            ['name' => 'materials', 'type' => 'multi-select', 'unit_label' => 'purchasing.copy.material'],
            ['name' => 'categories', 'type' => 'multi-select', 'unit_label' => 'materials.reference_prices.rules.category_name'],
            ['name' => 'source_currencies', 'type' => 'multi-select', 'unit_label' => 'exports.advanced.filters.source_currencies'],
            ['name' => 'doc_search', 'type' => 'text', 'placeholder' => 'exports.advanced.filters.placeholders.price_history_doc_search'],
            [
                'name' => 'currency',
                'type' => 'select',
                'domain' => 'currency',
                'options' => ExchangeRate::CURRENCIES,
                'required' => true,
                'default' => 'IDR',
            ],
            [
                'name' => 'period_view',
                'type' => 'select',
                'options' => ['monthly', 'yearly'],
                'labels' => [
                    'monthly' => 'purchasing.copy.monthly',
                    'yearly' => 'purchasing.copy.yearly',
                ],
                'required' => true,
                'default' => 'monthly',
            ],
            [
                'name' => 'range',
                'type' => 'select',
                'options' => self::RANGE_OPTIONS,
                'labels' => [
                    'all' => 'exports.advanced.all',
                    '3m' => 'exports.advanced.filters.ranges.3m',
                    '6m' => 'exports.advanced.filters.ranges.6m',
                    '12m' => 'exports.advanced.filters.ranges.12m',
                    '24m' => 'exports.advanced.filters.ranges.24m',
                    '1y' => 'exports.advanced.filters.ranges.1y',
                    '2y' => 'exports.advanced.filters.ranges.2y',
                    '5y' => 'exports.advanced.filters.ranges.5y',
                ],
                'default' => 'all',
            ],
            ['name' => 'key_min', 'type' => 'number'],
            ['name' => 'key_max', 'type' => 'number'],
            ['name' => 'price_min', 'type' => 'number'],
            ['name' => 'price_max', 'type' => 'number'],
            [
                'name' => 'change_direction',
                'type' => 'select',
                'options' => self::CHANGE_DIRECTIONS,
                'labels' => [
                    'increased' => 'purchasing.comparison_analytics.change_increased',
                    'decreased' => 'purchasing.comparison_analytics.change_decreased',
                    'unchanged' => 'purchasing.comparison_analytics.change_unchanged',
                ],
            ],
            [
                'name' => 'sort_dir',
                'type' => 'select',
                'options' => self::SORT_DIRECTIONS,
                'labels' => [
                    'desc' => 'purchasing.comparison_analytics.sort_po_date_desc',
                    'asc' => 'purchasing.comparison_analytics.sort_po_date_asc',
                ],
                'default' => 'desc',
            ],
        ];
    }
}
