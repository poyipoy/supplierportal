<?php

namespace App\Http\Requests\Export\Filters;

use App\Models\User;
use App\Support\BusinessTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class PurchaseOrderExportFilters
{
    public static function validated(Request $request, bool $supplier = false, bool $index = false): array
    {
        $data = $request->all();
        if ($index && is_array($data['search'] ?? null)) {
            $data['search'] = $data['search']['value'] ?? null;
        }
        $dateRule = $index || $request->input('options') !== null ? 'date_format:Y-m-d' : 'date';
        $filters = validator($data, [
            'supplier_id' => $supplier ? ['exclude'] : ['nullable', 'string', 'max:255'],
            'start_date' => ['nullable', $dateRule],
            'end_date' => ['nullable', $dateRule],
            'po_number' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in($supplier ? ['draft', 'active', 'waiting_qc', 'claim_needed', 'overdue', 'completed', 'cancelled'] : ['active', 'waiting_qc', 'claim_needed', 'overdue', 'completed', 'cancelled'])],
            'search' => ['nullable', 'string', 'max:255'],
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
            throw ValidationException::withMessages(['end_date' => __($supplier ? 'supplier.copy.end_date_cannot_be_before_start_date' : 'purchasing.copy.end_date_cannot_be_before_start_date')]);
        }
        if (! $supplier && ! empty($filters['supplier_id'])) {
            $id = $filters['supplier_id'];
            abort_unless(! ctype_digit($id), 404);
            $owner = (new User)->resolveRouteBinding($id);
            abort_unless($owner instanceof User && $owner->role === 'supplier', 404);
            $filters['supplier_id'] = (int) $owner->id;
        }

        return $filters;
    }

    public static function apply(Builder $query, array $filters): void
    {
        foreach (['supplier_id', 'po_number'] as $field) {
            if (! empty($filters[$field])) {
                $field === 'supplier_id' ? $query->where($field, $filters[$field]) : $query->where($field, 'like', '%'.trim($filters[$field]).'%');
            }
        }
        if (($filters['status'] ?? null) === 'overdue') {
            $query->where(fn ($q) => $q->where('status', 'overdue')->orWhere(fn ($q) => $q->where('status', 'active')->whereNotNull('estimated_arrival')->where('estimated_arrival', '<', BusinessTime::today()->toDateString())->whereNull('actual_arrival')));
        } elseif (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['start_date'])) {
            $query->where('created_at', '>=', BusinessTime::toStorage(BusinessTime::parseDate($filters['start_date'])));
        }
        if (! empty($filters['end_date'])) {
            $query->where('created_at', '<', BusinessTime::toStorage(BusinessTime::parseDate($filters['end_date'])->addDay()));
        }
        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $query->where(fn ($q) => $q->where('po_number', 'like', '%'.$search.'%')
                ->orWhereHas('supplier', fn ($q) => $q->where('name', 'like', '%'.$search.'%'))
                ->orWhereHas('quotations.purchaseRequisition.period', fn ($q) => $q->where('name', 'like', '%'.$search.'%'))
                ->orWhereHas('quotations.purchaseRequisition', fn ($q) => $q->where('pr_number', 'like', '%'.$search.'%'))
                ->orWhere('notes', 'like', '%'.$search.'%')->orWhere('status', 'like', '%'.$search.'%')->orWhere('estimated_arrival', 'like', '%'.$search.'%'));
        }
    }

    public static function schema(bool $supplier): array
    {
        $fields = [
            ['name' => 'start_date', 'type' => 'date'], ['name' => 'end_date', 'type' => 'date'],
            ['name' => 'po_number', 'type' => 'text'], ['name' => 'search', 'type' => 'text'],
            ['name' => 'status', 'type' => 'select', 'options' => $supplier ? ['draft', 'active', 'waiting_qc', 'claim_needed', 'overdue', 'completed', 'cancelled'] : ['active', 'waiting_qc', 'claim_needed', 'overdue', 'completed', 'cancelled']],
        ];
        if (! $supplier) {
            $fields[] = ['name' => 'supplier_id', 'type' => 'supplier'];
        }

        return $fields;
    }
}
