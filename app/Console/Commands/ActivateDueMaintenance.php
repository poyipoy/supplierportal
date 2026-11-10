<?php

namespace App\Console\Commands;

use App\Services\Maintenance\MaintenanceService;
use Illuminate\Console\Command;

/**
 * Persists the start of scheduled maintenance once its start time passes.
 * Enforcement does not wait for this: due schedules already block suppliers.
 * Idempotent: a single conditional UPDATE, safe to run late or twice.
 */
class ActivateDueMaintenance extends Command
{
    protected $signature = 'maintenance:activate-due';

    protected $description = 'Mark scheduled Supplier Portal maintenance as active once its start time has passed';

    public function handle(MaintenanceService $service): int
    {
        $count = $service->activateDue();

        $this->info("Activated {$count} scheduled maintenance record(s).");

        return self::SUCCESS;
    }
}
