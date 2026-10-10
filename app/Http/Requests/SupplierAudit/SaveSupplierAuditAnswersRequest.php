<?php

namespace App\Http\Requests\SupplierAudit;

use App\Models\SupplierAudit;
use App\Models\SupplierAuditAnswer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveSupplierAuditAnswersRequest extends FormRequest
{
    public const ACTION_DRAFT = 'draft';

    public const ACTION_SUBMIT = 'submit';

    public function authorize(): bool
    {
        $audit = $this->route('supplierAudit');

        return $audit instanceof SupplierAudit && $this->user()->can('fill', $audit);
    }

    public function rules(): array
    {
        $submit = $this->isSubmit();

        return [
            'action' => ['required', Rule::in([self::ACTION_DRAFT, self::ACTION_SUBMIT])],
            'answers' => ['required', 'array'],
            'answers.*' => ['array'],
            'answers.*.answer' => [$submit ? 'required' : 'nullable', Rule::in(SupplierAuditAnswer::ANSWERS)],
            'answers.*.score' => array_values(array_filter([
                $submit ? 'required_if:answers.*.answer,'.SupplierAuditAnswer::ANSWER_YES : null,
                'prohibited_if:answers.*.answer,'.SupplierAuditAnswer::ANSWER_NO,
                'nullable',
                'integer',
                'between:'.SupplierAuditAnswer::SCORE_MIN.','.SupplierAuditAnswer::SCORE_MAX,
            ])),
        ];
    }

    public function messages(): array
    {
        return [
            'answers.*.answer.required' => __('supplier_audit.errors.answer_required', ['criterion' => ':attribute']),
            'answers.*.score.required_if' => __('supplier_audit.errors.score_required', ['criterion' => ':attribute']),
            'answers.*.score.prohibited_if' => __('supplier_audit.errors.score_prohibited', ['criterion' => ':attribute']),
            'answers.*.score.between' => __('supplier_audit.errors.score_range'),
            'answers.*.score.integer' => __('supplier_audit.errors.score_range'),
        ];
    }

    /** Nama atribut per baris: "Bagian 2.1 no. 3". */
    public function attributes(): array
    {
        $audit = $this->route('supplierAudit');
        if (! $audit instanceof SupplierAudit) {
            return [];
        }

        $attributes = [];
        foreach ($audit->answers()->get(['supplier_audit_criterion_id', 'section_code_snapshot', 'criterion_number_snapshot']) as $row) {
            $label = __('supplier_audit.fields.criterion_ref', [
                'section' => $row->section_code_snapshot,
                'number' => $row->criterion_number_snapshot,
            ]);
            $attributes['answers.'.$row->supplier_audit_criterion_id.'.answer'] = $label;
            $attributes['answers.'.$row->supplier_audit_criterion_id.'.score'] = $label;
        }

        return $attributes;
    }

    /** Setiap key jawaban wajib milik audit ini (criterion dari template audit). */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $audit = $this->route('supplierAudit');
            $submitted = array_keys((array) $this->input('answers', []));
            $allowed = $audit->answers()->pluck('supplier_audit_criterion_id')->map(fn ($id) => (string) $id)->all();

            foreach ($submitted as $criterionId) {
                if (! in_array((string) $criterionId, $allowed, true)) {
                    $validator->errors()->add('answers', __('supplier_audit.errors.invalid_criterion'));

                    return;
                }
            }
        });
    }

    public function isSubmit(): bool
    {
        return $this->input('action') === self::ACTION_SUBMIT;
    }
}
