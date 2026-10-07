<?php

namespace App\Http\Requests\Export\Filters;

use App\Models\User;
use App\Support\BusinessTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ShipmentExportFilters
{
    public static function validated(Request $request): array
    {
        $data = $request->all();
        if (is_array($data['search'] ?? null)) {
            $data['search'] = $data['search']['value'] ?? null;
        }
        $data['start_date'] = $data['start_date'] ?? $data['date_from'] ?? null;
        $data['end_date'] = $data['end_date'] ?? $data['date_to'] ?? null;
        $filters = validator($data, ['supplier_id' => ['nullable', 'string', 'max:255'], 'status' => ['nullable', Rule::in(['draft', 'submitted', 'arrived', 'cancelled'])], 'search' => ['nullable', 'string', 'max:255'], 'shipment_number' => ['nullable', 'string', 'max:100'], 'start_date' => ['nullable', 'date'], 'end_date' => ['nullable', 'date'], 'date_range' => ['sometimes', 'array:mode,days'], 'date_range.mode' => ['required_with:date_range', Rule::in(['absolute', 'relative'])], 'date_range.days' => ['required_if:date_range.mode,relative', 'integer', 'min:1', 'max:3650']])->validate();
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
            $user = (new User)->resolveRouteBinding($id);
            abort_unless($user instanceof User && $user->role === 'supplier', 404);
            $filters['supplier_id'] = (int) $user->id;
        }

        return $filters;
    }

    public static function apply(Builder $query, array $filters): void
    {
        foreach (['status', 'supplier_id'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (! empty($filters['start_date'])) {
            $query->whereDate('shipment_date', '>=', $filters['start_date']);
        }
        if (! empty($filters['end_date'])) {
            $query->whereDate('shipment_date', '<=', $filters['end_date']);
        }
        if (! empty($filters['shipment_number'])) {
            $query->where('shipment_number', 'like', '%'.trim($filters['shipment_number']).'%');
        }
        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $query->where(fn ($q) => $q->where('shipment_number', 'like', '%'.$search.'%')->orWhere('notes', 'like', '%'.$search.'%')->orWhereHas('supplier', fn ($q) => $q->where('name', 'like', '%'.$search.'%'))->orWhereHas('items.purchaseOrder', fn ($q) => $q->where('po_number', 'like', '%'.$search.'%'))->orWhereHas('items.quotationItem.prItem', fn ($q) => $q->where('material_name', 'like', '%'.$search.'%')));
        }
    }

    public static function schema(): array
    {
        return [['name' => 'supplier_id', 'type' => 'supplier'], ['name' => 'status', 'type' => 'select', 'domain' => 'shipment', 'options' => ['draft', 'submitted', 'arrived', 'cancelled']], ['name' => 'search', 'type' => 'text'], ['name' => 'shipment_number', 'type' => 'text'], ['name' => 'start_date', 'type' => 'date'], ['name' => 'end_date', 'type' => 'date']];
    }
}
