<?php

namespace App\Http\Requests\Export\Filters;

use App\Support\BusinessTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class InspectionExportFilters
{
    public static function validated(Request $request): array
    {
        $filters = validator($request->all(), [
            'start_date' => ['nullable', $request->input('options') !== null ? 'date_format:Y-m-d' : 'date'],
            'end_date' => ['nullable', $request->input('options') !== null ? 'date_format:Y-m-d' : 'date', 'after_or_equal:start_date'],
            'status' => ['nullable', Rule::in(['ok', 'ng'])],
            'date_range' => ['sometimes', 'array:mode,days'],
            'date_range.mode' => ['required_with:date_range', Rule::in(['relative', 'absolute'])],
            'date_range.days' => ['required_if:date_range.mode,relative', 'integer', 'min:1', 'max:3650'],
        ])->validate();
        if (($filters['date_range']['mode'] ?? null) === 'relative') {
            $filters['end_date'] = BusinessTime::today()->toDateString();
            $filters['start_date'] = BusinessTime::today()->subDays($filters['date_range']['days'] - 1)->toDateString();
        }
        unset($filters['date_range']);

        return $filters;
    }

    public static function apply(Builder $query, array $filters): void
    {
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['start_date'])) {
            $query->where('inspected_at', '>=', BusinessTime::toStorage(BusinessTime::parseDate($filters['start_date'])));
        }
        if (! empty($filters['end_date'])) {
            $query->where('inspected_at', '<', BusinessTime::toStorage(BusinessTime::parseDate($filters['end_date'])->addDay()));
        }
    }

    public static function schema(): array
    {
        return [['name' => 'start_date', 'type' => 'date'], ['name' => 'end_date', 'type' => 'date'], ['name' => 'status', 'type' => 'select', 'domain' => 'qc', 'options' => ['ok', 'ng']]];
    }
}
