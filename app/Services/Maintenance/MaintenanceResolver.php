<?php

namespace App\Services\Maintenance;

use App\Models\MaintenanceRecord;
use App\Support\BusinessTime;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Read side: which records block users right now. Coverage is the union of
 * every effective record, so releasing one record never opens a module another
 * record still holds. Production reads share a short cache invalidated on writes.
 */
final class MaintenanceResolver
{
    public const CACHE_KEY = 'maintenance:effective_records';

    public const CACHE_TTL_SECONDS = 30;

    /** @var Collection<int, MaintenanceRecord>|null */
    private ?Collection $effective = null;

    public function __construct(private readonly ?DateTimeInterface $now = null) {}

    public static function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
        app()->forgetInstance(self::class);
        DB::afterCommit(static function (): void {
            Cache::forget(self::CACHE_KEY);
            app()->forgetInstance(self::class);
        });
    }

    /** @return Collection<int, MaintenanceRecord> */
    public function effectiveRecords(): Collection
    {
        if ($this->effective !== null) {
            return $this->effective;
        }

        if ($this->now !== null) {
            return $this->effective = $this->queryEffectiveRecords($this->now);
        }

        return $this->effective = Cache::remember(
            self::CACHE_KEY,
            self::CACHE_TTL_SECONDS,
            fn (): Collection => $this->queryEffectiveRecords(BusinessTime::now()),
        );
    }

    /** @return Collection<int, MaintenanceRecord> */
    private function queryEffectiveRecords(DateTimeInterface $now): Collection
    {
        return MaintenanceRecord::query()
            ->effective($now)
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
