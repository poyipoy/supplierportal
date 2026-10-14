<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;
use RuntimeException;

class FinanceConfigurationGuard
{
    public function assertProductionConfiguration(): void
    {
        if (app()->environment('production')) {
            $this->limit('ga_claim_max_amount');
            $this->limit('bank_fee_max_amount');
        }
    }

    public function limit(string $key): string
    {
        $value = config("finance.limits.{$key}");
        if ((! is_string($value) && ! is_int($value)) || ! preg_match('/^\d{1,16}(\.\d{1,2})?$/', (string) $value) || Money::compare($value, Money::ZERO) <= 0) {
            throw new RuntimeException("Finance limit [{$key}] must be an explicit positive decimal amount.");
        }

        return Money::normalize($value);
    }

    public function amount(mixed $value, string $key, string $field, string $minimum = '0.00'): string
    {
        $limit = $this->limit($key);
        if ((! is_string($value) && ! is_int($value)) || ! preg_match('/^\d{1,16}(\.\d{1,2})?$/', (string) $value)
            || Money::compare($value, $minimum) < 0 || Money::compare($value, $limit) > 0) {
            throw ValidationException::withMessages([$field => __('finance_controls.amount_limit', ['min' => $minimum, 'max' => $limit])]);
        }

        return Money::normalize($value);
    }
}
