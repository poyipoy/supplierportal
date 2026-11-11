<?php

namespace App\Console\Commands;

use App\Services\Procurement\SlaWatchdogService;
use Illuminate\Console\Command;

class RunSlaWatchdogCommand extends Command
{
    protected $signature = 'sla:watchdog';

    protected $description = 'Evaluate procurement and finance SLA thresholds and dispatch idempotent reminders and escalations';

    public function handle(SlaWatchdogService $watchdog): int
    {
        $summary = $watchdog->evaluateAll();

        $this->info("SLA watchdog completed. Total notifications dispatched: {$summary['dispatched']}.");

        foreach ($summary['by_rule'] as $ruleKey => $counts) {
            $this->line(sprintf(
                ' - %s: %d reminder(s), %d escalation(s)',
                $ruleKey,
                $counts['reminder'] ?? 0,
                $counts['escalation'] ?? 0,
            ));
        }

        return self::SUCCESS;
    }
}
