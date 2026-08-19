<?php

namespace App\Http\Requests;

use App\Data\Materials\HsCodeConditionSet;
use App\Models\HsCodeRule;
use App\Models\MaterialMaster;
use App\Models\PrItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Throwable;

class SaveHsCodeRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $rawHsCode = trim((string) $this->input('hs_code'));
        $digits = preg_match('/^\d{8}$/', $rawHsCode)
            ? $rawHsCode
            : (preg_match('/^\d{4}\.\d{2}\.\d{2}$/', $rawHsCode)
                ? str_replace('.', '', $rawHsCode)
                : null);
        $canonical = $digits !== null
            ? substr($digits, 0, 4).'.'.substr($digits, 4, 2).'.'.substr($digits, 6, 2)
            : $rawHsCode;

        $conditions = $this->input('conditions');
        if (is_string($this->input('conditions_json'))) {
            $decoded = json_decode((string) $this->input('conditions_json'), true);
            if (is_array($decoded)) {
                $conditions = $decoded;
            }
        }

        $this->merge(['hs_code' => $canonical, 'conditions' => $conditions]);
    }

    public function rules(): array
    {
        return [
            'hs_code' => ['required', 'regex:/^\d{4}\.\d{2}\.\d{2}$/'],
            'material_category' => ['required', Rule::in(MaterialMaster::HS_CATEGORIES)],
            'shape' => ['required', Rule::in(PrItem::SHAPES)],
            'conditions' => ['required', 'array', 'min:1'],
            'conditions_json' => ['nullable', 'string'],
            'priority' => ['required', 'integer', 'min:1', 'max:65535'],
            'status' => ['required', Rule::in(HsCodeRule::STATUSES)],
            'source_refs' => ['nullable', 'array'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            try {
                HsCodeConditionSet::fromArray((array) $this->input('conditions', []));
            } catch (Throwable $exception) {
                $validator->errors()->add('conditions', $exception->getMessage());
            }
        }];
    }
}
