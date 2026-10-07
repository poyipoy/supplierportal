<?php

namespace App\Http\Requests\Export;

use App\Support\Export\ExportOptions;
use App\Support\Export\ExportOptionsResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdvancedExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'options' => ['nullable', 'array:columns,format'],
            'options.columns' => ['sometimes', 'array', 'min:1', 'max:64'],
            'options.columns.*' => ['string', 'distinct', 'regex:/^[a-z][a-z0-9_]{0,63}$/D'],
            'options.format' => ['sometimes', Rule::in(['xlsx', 'csv'])],
        ];
    }

    public function exportOptions(string $serverKey): ?ExportOptions
    {
        $input = $this->validated('options');

        return $input === null ? null : ExportOptionsResolver::resolve($serverKey, $this->user(), $input);
    }
}
