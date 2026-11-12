<?php

namespace App\Http\Requests;

use App\Models\PrItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExportPrFormRequest extends FormRequest
{
    private const CONTENT_FIELDS = [
        'material_master_id',
        'material_name',
        'shape',
        'quantity',
        'thickness',
        'width',
        'd_outer',
        'd_inner',
        'length',
        'remark',
    ];

    public function authorize(): bool
    {
        return $this->user() && in_array($this->user()->role, ['purchasing', 'admin'], true);
    }

    protected function prepareForValidation(): void
    {
        $rawItems = $this->input('items', []);
        if (! is_array($rawItems)) {
            return;
        }

        $filtered = [];
        foreach ($rawItems as $row) {
            if (! is_array($row)) {
                continue;
            }

            $hasContent = false;
            foreach (self::CONTENT_FIELDS as $field) {
                if (isset($row[$field]) && trim((string) $row[$field]) !== '') {
                    $hasContent = true;
                    break;
                }
            }

            if ($hasContent) {
                $filtered[] = $row;
            }
        }

        $this->merge(['items' => $filtered]);
    }

    public function rules(): array
    {
        return [
            'pr_number' => ['nullable', 'string', 'max:50'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.material_master_id' => ['nullable', 'integer'],
            'items.*.material_name' => ['nullable', 'string', 'max:255'],
            'items.*.shape' => ['nullable', 'string', Rule::in(PrItem::SHAPES)],
            'items.*.quantity' => ['nullable', 'integer', 'min:1'],
            'items.*.thickness' => ['nullable', 'numeric', 'gt:0'],
            'items.*.width' => ['nullable', 'numeric', 'gt:0'],
            'items.*.d_outer' => ['nullable', 'numeric', 'gt:0'],
            'items.*.d_inner' => ['nullable', 'numeric', 'gt:0'],
            'items.*.length' => ['nullable', 'numeric', 'gt:0'],
            'items.*.remark' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => __('purchasing.validation.material_required'),
            'items.min' => __('purchasing.validation.material_required'),
            'items.*.quantity.min' => __('purchasing.validation.quantity_minimum'),
        ];
    }
}
