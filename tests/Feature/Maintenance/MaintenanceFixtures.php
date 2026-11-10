<?php

namespace Tests\Feature\Maintenance;

use App\Models\MaintenanceRecord;
use App\Models\SupplierScope;
use App\Models\User;
use App\Services\Maintenance\MaintenanceService;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;

trait MaintenanceFixtures
{
    protected function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** @param list<string> $scopes */
    protected function supplier(array $scopes = ['import']): User
    {
        // UserFactory always grants the import scope to suppliers; make the scopes exact.
        $user = User::factory()->create(['role' => 'supplier']);
        SupplierScope::query()->where('supplier_id', $user->id)->whereNotIn('scope', $scopes)->delete();
        foreach ($scopes as $scope) {
            SupplierScope::query()->firstOrCreate(['supplier_id' => $user->id, 'scope' => $scope]);
        }

        return $user->fresh();
    }

    /** A staff user of the given role (purchasing, qc, finance, accounting, ga). */
    protected function staff(string $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    protected function service(): MaintenanceService
    {
        return app(MaintenanceService::class);
    }

    /**
     * @param  list<string>  $modules  empty for Global Maintenance
     */
    protected function data(array $modules = [], array $overrides = []): array
    {
        return array_merge([
            'type' => $modules === [] ? MaintenanceRecord::TYPE_GLOBAL : MaintenanceRecord::TYPE_MODULE,
            'modules' => $modules,
            'title' => 'Maintenance '.implode(', ', $modules ?: ['global']),
            'description' => "Planned upgrade.\nSubmissions resume afterwards.",
            'starts_at' => null,
            'estimated_end_at' => BusinessTime::now()->addHours(2),
        ], $overrides);
    }

    /** @param list<string> $modules */
    protected function activeMaintenance(User $actor, array $modules = [], array $overrides = []): MaintenanceRecord
    {
        return $this->service()->create($actor, $this->data($modules, $overrides), MaintenanceService::ACTION_ACTIVATE);
    }

    protected function businessMoment(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, BusinessTime::tz());
    }
}
