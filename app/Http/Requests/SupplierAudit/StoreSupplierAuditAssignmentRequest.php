<?php

namespace App\Http\Requests\SupplierAudit;

use App\Models\SupplierAudit;
use App\Models\User;
use App\Support\BusinessTime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreSupplierAuditAssignmentRequest extends FormRequest
{
    /** @var list<int> */
    private array $resolvedSupplierIds = [];

    public function authorize(): bool
    {
        return $this->user()->can('create', SupplierAudit::class);
    }

    public function rules(): array
    {
        return [
            'supplier_ids' => ['required', 'array', 'min:1', 'max:100'],
            'supplier_ids.*' => ['required', 'string', 'distinct', 'max:64'],
            'period_label' => ['required', 'string', 'max:100'],
            'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.BusinessTime::today()->toDateString()],
        ];
    }

    public function attributes(): array
    {
        return [
            'supplier_ids' => __('supplier_audit.fields.suppliers'),
            'supplier_ids.*' => __('supplier_audit.fields.supplier'),
            'period_label' => __('supplier_audit.fields.period'),
            'due_date' => __('supplier_audit.fields.due_date'),
        ];
    }

    /** Nilai supplier berupa hashid; digit mentah dan hash non-supplier ditolak (pola resolveSupplierFilter). */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $ids = [];
            foreach ((array) $this->input('supplier_ids', []) as $hash) {
                $user = is_string($hash) && ! ctype_digit($hash) ? (new User)->resolveRouteBinding($hash) : null;
                if (! $user instanceof User || $user->role !== 'supplier') {
                    $validator->errors()->add('supplier_ids', __('supplier_audit.errors.invalid_supplier'));

                    return;
                }
                $ids[] = (int) $user->id;
            }

            $this->resolvedSupplierIds = $ids;
        });
    }

    /** @return list<int> */
    public function supplierIds(): array
    {
        return $this->resolvedSupplierIds;
    }
}
