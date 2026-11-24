<?php

namespace App\Http\Requests\Export\Filters;

use App\Models\ExchangeRate;
use App\Models\User;
use App\Support\BusinessTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class QuotationExportFilters
{
    public static function validated(Request $request, bool $supplier = false): array
    {
        $data = $request->all();
        if (is_array($data['search'] ?? null)) {
            $data['search'] = $data['search']['value'] ?? null;
        }
        $statuses = ['submitted', 'revision_requested', 'accepted', 'rejected', 'all_unavailable'];
        if ($supplier) {
            $statuses = ['draft', 'unresponded', 'cancelled', ...$statuses];
        }
        $filters = validator($data, ['supplier_id' => $supplier ? ['exclude'] : ['nullable', 'string', 'max:255'], 'period_id' => ['nullable', 'integer', 'exists:periods,id'], 'pr_number' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in($statuses)], 'currency' => ['nullable', Rule::in(ExchangeRate::CURRENCIES)], 'search' => ['nullable', 'string', 'max:255'], 'date_from' => ['nullable', 'date_format:Y-m'], 'date_to' => ['nullable', 'date_format:Y-m']])->validate();
        if (! empty($filters['date_from']) && ! empty($filters['date_to']) && $filters['date_to'] < $filters['date_from']) {
            throw ValidationException::withMessages(['date_to' => __('purchasing.copy.end_date_cannot_be_before_start_date')]);
        }
        if (isset($filters['period_id'])) {
            $filters['period_id'] = (int) $filters['period_id'];
        }
        if (! $supplier && ! empty($filters['supplier_id'])) {
            $id = $filters['supplier_id'];
            abort_unless(! ctype_digit($id), 404);
            $user = (new User)->resolveRouteBinding($id);
            abort_unless($user instanceof User && $user->role === 'supplier', 404);
            $filters['supplier_id'] = (int) $user->id;
        }

        return $filters;
    }

    public static function apply(Builder $query, array $filters, ?int $owner = null): void
    {
        if ($owner !== null) {
            $query->where('supplier_id', $owner);
        } elseif (! empty($filters['supplier_id'])) {
            $query->where('supplier_id', $filters['supplier_id']);
        }
        if (in_array($filters['status'] ?? null, ['unresponded', 'cancelled'], true)) {
            $query->whereRaw('1 = 0');
        } elseif (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['currency'])) {
            $query->where('currency', $filters['currency']);
        }
        if (! empty($filters['period_id'])) {
            $query->whereHas('purchaseRequisition', fn ($q) => $q->where('period_id', $filters['period_id']));
        }
        if ($pr = trim((string) ($filters['pr_number'] ?? ''))) {
            $query->whereHas('purchaseRequisition', fn ($q) => $q->where('pr_number', 'like', '%'.$pr.'%'));
        }
        if (! empty($filters['date_from'])) {
            $query->where('submitted_at', '>=', BusinessTime::toStorage(BusinessTime::parseDate($filters['date_from'].'-01')));
        }
        if (! empty($filters['date_to'])) {
            $query->where('submitted_at', '<', BusinessTime::toStorage(BusinessTime::parseDate($filters['date_to'].'-01')->addMonth()));
        }
        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $query->where(function (Builder $q) use ($search, $owner): void {
                $q->whereHas('purchaseRequisition', fn (Builder $pr) => $pr->where(function (Builder $sub) use ($search): void {
                    $sub->where('pr_number', 'like', '%'.$search.'%')
                        ->orWhere('updated_at', 'like', '%'.$search.'%')
                        ->orWhereHas('period', fn (Builder $period) => $period->where('name', 'like', '%'.$search.'%'));
                }))
                    ->orWhereHas('items.prItem', fn (Builder $item) => $item->where('material_name', 'like', '%'.$search.'%'));

                if ($owner === null) {
                    $q->orWhereHas('supplier', fn (Builder $supplier) => $supplier->where('name', 'like', '%'.$search.'%')
                        ->orWhereHas('supplier', fn (Builder $profile) => $profile->where('company_name', 'like', '%'.$search.'%')));
                }
            });
        }
    }

    public static function schema(bool $supplier): array
    {
        $searchKey = $supplier ? 'quotation_search_supplier' : 'quotation_search_purchasing';
        $fields = [
            ['name' => 'period_id', 'type' => 'period'],
            ['name' => 'status', 'type' => 'select', 'domain' => 'quotation', 'options' => $supplier ? ['unresponded', 'draft', 'submitted', 'revision_requested', 'accepted', 'rejected', 'all_unavailable', 'cancelled'] : ['submitted', 'revision_requested', 'accepted', 'rejected', 'all_unavailable']],
            [
                'name' => 'search',
                'type' => 'text',
                'placeholder' => 'exports.advanced.filters.placeholders.'.$searchKey,
                'hint' => 'exports.advanced.filters.hints.'.$searchKey,
            ],
        ];
        if (! $supplier) {
            $fields = [...$fields, ['name' => 'supplier_id', 'type' => 'supplier'], ['name' => 'currency', 'type' => 'select', 'domain' => 'currency', 'options' => ExchangeRate::CURRENCIES], ['name' => 'date_from', 'type' => 'month'], ['name' => 'date_to', 'type' => 'month']];
        }

        return $fields;
    }
}
