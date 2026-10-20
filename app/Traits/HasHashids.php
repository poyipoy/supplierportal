<?php

namespace App\Traits;

use App\Support\HashidCodec;
use Illuminate\Database\Eloquent\Model;

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
        return HashidCodec::encode($this->getKey());
    }

    /**
     * Retrieve the model for a bound value.
     * Overrides default Route Model Binding to decode Hashids.
     *
     * @param  mixed  $value
     * @param  string|null  $field
     * @return Model|null
     */
    public function resolveRouteBinding($value, $field = null)
    {
        if ($field !== null && $field !== $this->getRouteKeyName()) {
            return null;
        }

        if (! is_string($value) || ctype_digit($value)) {
            return null;
        }

        $id = HashidCodec::decode($value);
        if ($id === null) {
            return null;
        }

        return $this->where($this->getRouteKeyName(), $id)->first();
    }

    /**
     * Accessor to easily get the hashed ID via $model->hash.
     */
    public function getHashAttribute(): string
    {
        return HashidCodec::encode($this->getKey());
    }
}
