<?php

namespace App\Http\Requests;

use App\Models\PrItem;
use App\Models\PurchaseRequisition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SavePurchaseRequisitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $prId = $this->route('requisition') ?? $this->route('id');
        if ($prId instanceof PurchaseRequisition) {
            $prId = $prId->id;
        }

        $activeSupplier = Rule::exists('users', 'id')
            ->where(function ($query) use ($prId) {
                $query->where('role', 'supplier')
                    ->where('is_active', true)
                    ->where(function ($subQuery) use ($prId) {
                        $subQuery->whereExists(function ($q) {
                            $q->select(DB::raw(1))
                                ->from('supplier_scopes')
                                ->whereColumn('supplier_scopes.supplier_id', 'users.id')
                                ->where('supplier_scopes.scope', 'import');
                        });

                        if ($prId) {
                            $subQuery->orWhereExists(function ($q) use ($prId) {
                                $q->select(DB::raw(1))
                                    ->from('purchase_requisition_suppliers')
                                    ->whereColumn('purchase_requisition_suppliers.supplier_id', 'users.id')
                                    ->where('purchase_requisition_suppliers.pr_id', $prId);
                            });
                        }
                    });
            });
        $activeMaterial = Rule::exists('material_masters', 'id')->where('is_active', true);

        return [
            'period_id' => ['required', 'integer', 'exists:periods,id'],
            'action' => ['required', Rule::in(['draft', 'submitted'])],
            'notes' => ['nullable', 'string', 'max:5000'],
            'supplier_selection_present' => ['nullable', 'boolean'],
            'supplier_id' => ['nullable', 'integer', $activeSupplier],
            'supplier_ids' => ['nullable', 'array'],
            'supplier_ids.*' => ['nullable', 'integer', $activeSupplier],
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.material_master_id' => ['required', 'integer', $activeMaterial],
            'items.*.material_name' => ['nullable', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.shape' => ['nullable', Rule::in(PrItem::SHAPES)],
            'items.*.thickness' => ['nullable', 'numeric', 'gt:0'],
            'items.*.d_inner' => ['nullable', 'numeric', 'gt:0'],
            'items.*.d_outer' => ['nullable', 'numeric', 'gt:0'],
            'items.*.width' => ['nullable', 'numeric', 'gt:0'],
            'items.*.length' => ['nullable', 'numeric', 'gt:0'],
            'items.*.manual_hs_code' => ['nullable', 'string', 'max:20'],
            'items.*.hs_code' => ['nullable', 'string', 'max:20'],
            'items.*.hs_code_manual_override' => ['nullable', 'boolean'],
            'items.*.weight_needed' => ['nullable', 'numeric'],
            'items.*.weight_manual_override' => ['nullable', 'boolean'],
            'items.*.remark' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'At least one material must be added.',
            'items.*.material_master_id.required' => 'Select a material from the master list.',
            'items.*.material_master_id.exists' => 'The selected material is inactive or unavailable.',
            'items.*.quantity.min' => 'Quantity must be at least 1.',
            'supplier_id.exists' => 'The selected supplier must be active.',
            'supplier_ids.*.exists' => 'The selected supplier must be active.',
        ];
    }
}
