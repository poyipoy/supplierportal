<?php

namespace App\Http\Requests\SupplierContact;

class UpdateImportSupplierContactRequest extends StoreSupplierContactRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
