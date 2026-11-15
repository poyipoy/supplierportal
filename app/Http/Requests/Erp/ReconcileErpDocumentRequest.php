<?php

namespace App\Http\Requests\Erp;

use App\Models\ErpDeltaRow;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class ReconcileErpDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user instanceof User) {
            return false;
        }

        return $user->can('apply', [ErpDeltaRow::class, null]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'integer', 'exists:users,id'],
            'doc_no' => ['required', 'string', 'max:100'],
            'expected_digest' => ['required', 'string', 'regex:/^[0-9a-f]{16}$/i'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
