<?php

namespace App\Console\Commands;

use App\Models\QuotationItem;
use Illuminate\Console\Command;

class RepairQuotationAmounts extends Command
{
    protected $signature = 'quotations:repair-zero-amounts
                            {--execute : Persist the calculated amounts. Without this flag the command is a dry run.}
                            {--limit= : Limit the number of candidate rows inspected.}';

    protected $description = 'Audit quotation items with a non-positive stored amount and optionally repair eligible rows.';

    public function handle(): int
    {
        $query = QuotationItem::query()
            ->with('prItem')
            ->where('price_per_kg', '>', 0)
            ->where('amount', '<=', 0)
            ->orderBy('id');

        if ($this->option('limit') !== null) {
            $query->limit(max(1, (int) $this->option('limit')));
        }

        $candidates = 0;
        $eligible = 0;
        $updated = 0;

        $query->chunkById(100, function ($items) use (&$candidates, &$eligible, &$updated): void {
            foreach ($items as $item) {
                $candidates++;
                $prItem = $item->prItem;

                // Rows carrying the revision-era offer fields already have a
                // distinct Offer Amount contract.  This command is only for
                // legacy requested-weight zero rows and must never replace a
                // new offer amount with a requested amount.
                if (! $item->is_available
                    || $item->offered_weight_per_unit !== null
                    || $item->offered_weight_source !== null
                    || $item->available_length_min !== null
                    || $item->available_length_max !== null) {
                    $this->line("SKIP quotation_item={$item->id}: row uses the Offer Amount contract.");

                    continue;
                }

                if (! $prItem || $prItem->total_weight <= 0) {
                    $this->line("SKIP quotation_item={$item->id}: PR total KG is not positive.");

                    continue;
                }

                // This legacy repair command repairs requested-weight zero
                // rows only; it must not rewrite new Offer Amount records.
                $amount = QuotationItem::calculateRequestedAmount($prItem, $item->price_per_kg);
                if ($amount === null || $amount <= 0) {
                    $this->line("SKIP quotation_item={$item->id}: calculated amount is not positive.");

                    continue;
                }

                $eligible++;
                $this->line("ELIGIBLE quotation_item={$item->id}: 0 -> {$amount}");

                if ($this->option('execute')) {
                    $item->update(['amount' => $amount]);
                    $updated++;
                }
            }
        });

        if ($this->option('execute')) {
            $this->info("Inspected {$candidates} row(s); repaired {$updated} eligible quotation item(s).");
        } else {
            $this->info("Dry run: inspected {$candidates} row(s); {$eligible} eligible quotation item(s). Re-run with --execute to persist changes.");
        }

        return self::SUCCESS;
    }
}
