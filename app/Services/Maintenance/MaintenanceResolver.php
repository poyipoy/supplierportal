<?php

namespace App\Services\Maintenance;

use App\Models\MaintenanceRecord;
use App\Support\BusinessTime;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Read side: which records block users right now. Coverage is the union of
 * every effective record, so releasing one record never opens a module another
 * record still holds. Not a singleton: each instance reads fresh data once.
 */
final class MaintenanceResolver
{
    /** @var Collection<int, MaintenanceRecord>|null */
    private ?Collection $effective = null;

    public function __construct(private readonly ?DateTimeInterface $now = null) {}

    /** @return Collection<int, MaintenanceRecord> */
    public function effectiveRecords(): Collection
    {
        return $this->effective ??= MaintenanceRecord::query()
            ->effective($this->now ?? BusinessTime::now())
            ->with(['modules' => fn ($query) => $query->whereNull('deactivated_at')])
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, MaintenanceRecord> */
    public function globalRecords(): Collection
    {
        return $this->effectiveRecords()->filter(fn (MaintenanceRecord $record): bool => $record->isGlobal())->values();
    }

    /**
     * Module records holding any of the given keys.
     *
     * @param  list<string>  $moduleKeys
     * @return Collection<int, MaintenanceRecord>
     */
    public function moduleRecordsFor(array $moduleKeys): Collection
    {
        return $this->effectiveRecords()
            ->filter(fn (MaintenanceRecord $record): bool => ! $record->isGlobal()
                && array_intersect($record->activeModuleKeys(), $moduleKeys) !== [])
            ->values();
    }

    /** @return array<string, int> module key => number of module records holding it */
    public function blockedModuleCounts(): array
    {
        $counts = [];
        foreach ($this->effectiveRecords() as $record) {
            if ($record->isGlobal()) {
                continue;
            }
            foreach ($record->activeModuleKeys() as $key) {
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * Other effective records that would still block a module of $record.
     *
     * @return Collection<int, MaintenanceRecord>
     */
    public function otherBlockersFor(MaintenanceRecord $record, string $moduleKey): Collection
    {
        return $this->effectiveRecords()
            ->filter(fn (MaintenanceRecord $other): bool => $other->id !== $record->id
                && ($other->isGlobal() || in_array($moduleKey, $other->activeModuleKeys(), true)))
            ->values();
    }
}
