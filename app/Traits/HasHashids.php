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
        if ($field !== null && $field !== $this->getRouteKeyName()) {
            return null;
        }

        if (! is_string($value) || ctype_digit($value)) {
            return null;
        }

        try {
            $decoded = Hashids::decode($value);
        } catch (\Throwable) {
            return null;
        }

        $id = count($decoded) === 1 ? (int) $decoded[0] : null;

        if (! $id || Hashids::encode($id) !== $value) {
            return null;
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
