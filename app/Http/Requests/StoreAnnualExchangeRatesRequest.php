<?php

namespace App\Http\Requests;

use App\Models\AnnualExchangeRate;
use Illuminate\Foundation\Http\FormRequest;

class StoreAnnualExchangeRatesRequest extends FormRequest
{
    protected $errorBag = 'annualRates';

    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    protected function prepareForValidation(): void
    {
        if (is_array($this->rates)) {
            $normalized = [];
            foreach ($this->rates as $currency => $val) {
                $normalized[$currency] = is_string($val) ? str_replace(',', '.', trim($val)) : $val;
            }
            $this->merge(['rates' => $normalized]);
        }
    }

    public function rules(): array
    {
        $rules = [
            'year' => ['required', 'integer', 'between:1000,9999'],
            'expected_revision' => ['required', 'integer', 'min:0'],
            'rates' => ['required', 'array:USD,JPY,CNY'],
        ];
        foreach (AnnualExchangeRate::CURRENCIES as $currency) {
            $rules['rates.'.$currency] = ['required', 'numeric', 'decimal:0,4', 'gt:0', 'max:99999999999.9999'];
        }

        return $rules;
    }

    public function attributes(): array
    {
        return ['year' => __('annual_rates.year'), 'rates' => __('annual_rates.title'),
            'rates.USD' => 'USD', 'rates.JPY' => 'JPY', 'rates.CNY' => 'CNY'];
    }

    protected function getRedirectUrl(): string
    {
        return route('admin.exchange-rates.index', ['tab' => 'annual']);
    }
}
