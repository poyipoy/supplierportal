<?php

namespace App\Policies;

use App\Models\ExportPreset;
use App\Models\User;
use App\Support\Export\ExportDefinitions;
use InvalidArgumentException;

class ExportPresetPolicy
{
    public function update(User $user, ExportPreset $preset): bool
    {
        if ((int) $user->id !== (int) $preset->user_id) {
            return false;
        }
        try {
            return ExportDefinitions::get($preset->export_key)->authorize($user);
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    public function delete(User $user, ExportPreset $preset): bool
    {
        return $this->update($user, $preset);
    }
}
