<?php

namespace App\Traits;

use Vinkla\Hashids\Facades\Hashids;

trait HasHashids
{
    /**
     * Get the value of the model's route key.
     * Overrides default Route Model Binding to use Hashids.
     *
     * @return mixed
     */
    public function getRouteKey()
    {
        return Hashids::encode($this->getKey());
    }

    /**
     * Retrieve the model for a bound value.
     * Overrides default Route Model Binding to decode Hashids.
     *
     * @param  mixed  $value
     * @param  string|null  $field
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function resolveRouteBinding($value, $field = null)
    {
        // If a specific field is defined, fall back to default behavior
        if ($field) {
            return parent::resolveRouteBinding($value, $field);
        }

        // Decode the hashid
        $decoded = Hashids::decode($value);
        $id = $decoded[0] ?? null;

        if (! $id) {
            // Fallback: If the value is a plain numeric ID (e.g., from old emails or hardcoded JS URLs)
            if (is_numeric($value) && ctype_digit(strval($value))) {
                return $this->where($this->getRouteKeyName(), $value)->first();
            }
            return null; // Invalid hashid and not a plain integer
        }

        return $this->where($this->getRouteKeyName(), $id)->first();
    }

    /**
     * Accessor to easily get the hashed ID via $model->hash.
     *
     * @return string
     */
    public function getHashAttribute(): string
    {
        return Hashids::encode($this->getKey());
    }
}
