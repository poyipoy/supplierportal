<?php

namespace App\Http\Requests\Export\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class RequisitionExportFilters
{
    public static function validated(Request $request): array
    {
        $data = $request->all();
        if (is_array($data['search'] ?? null)) {
            $data['search'] = $data['search']['value'] ?? null;
        }
        $filters = validator($data, ['period_id' => ['nullable', 'integer', 'exists:periods,id'], 'status' => ['nullable', Rule::in(['draft', 'submitted', 'rejected', 'bidding', 'completed'])], 'search' => ['nullable', 'string', 'max:255']])->validate();
        if (isset($filters['period_id'])) {
            $filters['period_id'] = (int) $filters['period_id'];
        }

        return $filters;
    }

    public static function apply(Builder $query, array $filters): void
    {
        if (! empty($filters['period_id'])) {
            $query->where('period_id', $filters['period_id']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $query->where(fn ($q) => $q->where('pr_number', 'like', '%'.$search.'%')->orWhereHas('period', fn ($q) => $q->where('name', 'like', '%'.$search.'%'))->orWhereHas('creator', fn ($q) => $q->where('name', 'like', '%'.$search.'%'))->orWhere('created_at', 'like', '%'.$search.'%'));
        }
    }

    public static function schema(): array
    {
        return [['name' => 'period_id', 'type' => 'period'], ['name' => 'status', 'type' => 'select', 'domain' => 'pr', 'options' => ['draft', 'submitted', 'rejected', 'bidding', 'completed']], ['name' => 'search', 'type' => 'text']];
    }
}
