<?php

namespace App\Http\Requests\SupplierContact;

use Illuminate\Foundation\Http\FormRequest;

class StoreSupplierContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pic_name' => ['required', 'string', 'max:100'],
            'pic_email' => ['required', 'email', 'max:100'],
            // Digits plus common separators, so the value also works as a tel: link.
            'pic_phone' => ['required', 'string', 'max:30', 'regex:/^\+?[0-9][0-9 ().-]{4,}$/'],
        ];
    }

    public function attributes(): array
    {
        return [
            'pic_name' => __('supplier_contact.fields.name'),
            'pic_email' => __('supplier_contact.fields.email'),
            'pic_phone' => __('supplier_contact.fields.phone'),
            'reason' => __('supplier_contact.fields.reason'),
        ];
    }

    public function messages(): array
    {
        return [
            'pic_phone.regex' => __('supplier_contact.validation.phone_format'),
        ];
    }
}
