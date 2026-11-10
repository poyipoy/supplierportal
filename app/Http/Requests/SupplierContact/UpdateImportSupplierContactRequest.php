<?php

namespace App\Http\Requests\SupplierContact;

class UpdateImportSupplierContactRequest extends StoreSupplierContactRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'company_name' => ['nullable', 'string', 'max:255'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }

    public function attributes(): array
    {
        return parent::attributes() + [
            'company_name' => __('supplier_contact.profile.company_name'),
        ];
    }
}
