<?php

namespace App\Policies;

use App\Models\ExportPreset;
use App\Models\User;
use App\Support\Export\ExportDefinitions;
use InvalidArgumentException;

class ExportPresetPolicy
{
    public function view(User $user, ExportPreset $preset): bool
    {
        if (! $this->authorizesDefinition($user, $preset->export_key)) {
            return false;
        }

        $scope = $preset->scope ?: ExportPreset::SCOPE_PERSONAL;
        if ($scope === ExportPreset::SCOPE_TEAM) {
            return ! $user->isSupplier() && ! str_starts_with($preset->export_key, 'supplier.');
        }

        return (int) $user->id === (int) $preset->user_id;
    }

    public function createTeam(User $user, string $exportKey): bool
    {
        if ($user->isSupplier() || str_starts_with($exportKey, 'supplier.')) {
            return false;
        }

        if (! $this->authorizesDefinition($user, $exportKey)) {
            return false;
        }

        return in_array($user->role, config('exports.team_preset_manager_roles', ['admin', 'purchasing', 'finance', 'qc']), true);
    }

    public function update(User $user, ExportPreset $preset): bool
    {
        if (! $this->authorizesDefinition($user, $preset->export_key)) {
            return false;
        }

        $scope = $preset->scope ?: ExportPreset::SCOPE_PERSONAL;
        if ($scope === ExportPreset::SCOPE_TEAM) {
            if (! $this->createTeam($user, $preset->export_key)) {
                return false;
            }

            return (int) $user->id === (int) $preset->user_id || $user->isAdmin();
        }

        return (int) $user->id === (int) $preset->user_id;
    }

    public function delete(User $user, ExportPreset $preset): bool
    {
        return $this->update($user, $preset);
    }

    public function makeDefault(User $user, ExportPreset $preset): bool
    {
        $scope = $preset->scope ?: ExportPreset::SCOPE_PERSONAL;
        if ($scope !== ExportPreset::SCOPE_PERSONAL) {
            return false;
        }

        return $this->update($user, $preset);
    }

    private function authorizesDefinition(User $user, string $exportKey): bool
    {
        if (! $user->is_active) {
            return false;
        }

        try {
            $definition = ExportDefinitions::get($exportKey);

            return ExportDefinitions::supportsColumns($definition) && $definition->authorize($user);
        } catch (InvalidArgumentException) {
            return false;
        }
    }
}
