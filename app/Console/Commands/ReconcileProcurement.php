<?php

namespace App\Console\Commands;

use App\Models\PrItemAward;
use App\Support\BusinessTime;
use App\Support\Money;
use App\Support\Procurement\ProcurementFeatures;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only Import-procurement integrity report used by the release and
 * rollback gates. Later missions add their own metrics; this command never
 * writes to the database.
 */
class ReconcileProcurement extends Command
{
    protected $signature = 'procurement:reconcile {--json : Emit machine-readable JSON}';

    protected $description = 'Report Import procurement release-gate metrics (read-only)';

    public function handle(): int
    {
        $metrics = [
            'po_generation_enabled' => ProcurementFeatures::poGenerationEnabled(),
            ...$this->logisticsMetrics(),
            ...$this->orderedQuantityMetrics(),
            ...$this->referenceSnapshotMetrics(),
            'inconsistent_feature_flags' => ProcurementFeatures::hasInconsistentFlags(),
        ];

        if ($this->option('json')) {
            $this->line(json_encode([
                'generated_at' => BusinessTime::now()->toIso8601String(),
                'metrics' => $metrics,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->table(['Metric', 'Value'], collect($metrics)->map(
            fn ($value, string $metric) => [$metric, is_bool($value) ? ($value ? 'true' : 'false') : (is_array($value) ? implode(', ', $value) : (string) $value)]
        )->values()->all());

        return self::SUCCESS;
    }

    /**
     * Mission 04-R rollback gates (I-17, I-21): an M04-R code revert is only
     * safe while frozen_po_count = 0; selected-but-unassigned awards are
     * blocked by guard (b) while the write path is off.
     *
     * @return array<string, int>
     */
    private function orderedQuantityMetrics(): array
    {
        $frozenPoIds = [];
        $divergentPoIds = [];

        PrItemAward::query()
            ->whereNotNull('purchase_order_id')
            ->whereNotNull('ordered_amount')
            ->with('quotationItem.prItem')
            ->chunkById(500, function ($awards) use (&$frozenPoIds, &$divergentPoIds): void {
                foreach ($awards as $award) {
                    $frozenPoIds[$award->purchase_order_id] = true;
                    $item = $award->quotationItem;
                    $diverges = $item === null
                        || (int) $award->ordered_qty !== $item->fulfillment_quantity
                        || Money::compare($award->ordered_amount, $item->resolved_amount, 4) !== 0;
                    if ($diverges) {
                        $divergentPoIds[$award->purchase_order_id] = true;
                    }
                }
            });

        return [
            'frozen_po_count' => count($frozenPoIds),
            'divergent_frozen_po_count' => count($divergentPoIds),
            'unassigned_selected_award_count' => PrItemAward::query()
                ->whereNull('purchase_order_id')
                ->where(fn ($query) => $query->whereNotNull('ordered_qty')->orWhereNotNull('spec_accepted_at'))
                ->count(),
        ];
    }

    /**
     * Mission 06B gate (I-19): from the earliest snapshot (the activation
     * point) every PO award must have a snapshot row. Earlier POs are "not
     * captured" by definition and never counted or backfilled.
     *
     * @return array<string, int|list<string>>
     */
    private function referenceSnapshotMetrics(): array
    {
        $activation = DB::table('award_reference_price_snapshots')->min('created_at');
        if ($activation === null) {
            return ['po_missing_reference_snapshot_after_activation' => 0, 'po_missing_reference_snapshot_numbers' => []];
        }

        $numbers = DB::table('purchase_orders as po')
            ->whereNull('po.deleted_at')
            ->where('po.created_at', '>=', $activation)
            ->whereExists(fn ($query) => $query->from('pr_item_awards as a')
                ->whereColumn('a.purchase_order_id', 'po.id')
                ->whereNotExists(fn ($snapshot) => $snapshot->from('award_reference_price_snapshots as s')
                    ->whereColumn('s.pr_item_award_id', 'a.id')))
            ->orderBy('po.id')
            ->pluck('po.po_number')
            ->all();

        return [
            'po_missing_reference_snapshot_after_activation' => count($numbers),
            'po_missing_reference_snapshot_numbers' => $numbers,
        ];
    }

    /**
     * Mission 02 / R1 gate (I-18). PRs are immutable after submit, so these
     * counts are meaningful over every PO, legacy included.
     *
     * @return array<string, int>
     */
    private function logisticsMetrics(): array
    {
        $linkedPrs = fn () => DB::table('po_quotations as pq')
            ->join('quotations as q', 'q.id', '=', 'pq.quotation_id')
            ->join('purchase_requisitions as pr', 'pr.id', '=', 'q.pr_id')
            ->whereColumn('pq.po_id', 'po.id');

        $submittedWithLogistics = DB::table('purchase_requisitions')
            ->whereNull('deleted_at')
            ->where('status', '<>', 'draft')
            ->whereNotNull('port_of_discharge')
            ->whereNotNull('incoterm')
            ->count();

        // Every linked PR carries logistics, yet the PO has no snapshot.
        $missingSnapshot = DB::table('purchase_orders as po')
            ->whereNull('po.deleted_at')
            ->whereNull('po.port_of_discharge')
            ->whereNull('po.incoterm')
            ->whereExists($linkedPrs())
            ->whereNotExists($linkedPrs()
                ->where(fn ($terms) => $terms->whereNull('pr.port_of_discharge')->orWhereNull('pr.incoterm')))
            ->count();

        // Linked PRs disagree (NULL counts as a distinct value).
        $mixed = DB::table('purchase_orders as po')
            ->whereNull('po.deleted_at')
            ->whereRaw("(SELECT COUNT(DISTINCT CONCAT(COALESCE(pr.port_of_discharge, '~'), '|', COALESCE(pr.incoterm, '~')))
                FROM po_quotations pq
                JOIN quotations q ON q.id = pq.quotation_id
                JOIN purchase_requisitions pr ON pr.id = q.pr_id
                WHERE pq.po_id = po.id) > 1")
            ->count();

        return [
            'submitted_pr_with_logistics_count' => $submittedWithLogistics,
            'po_missing_logistics_snapshot_count' => $missingSnapshot,
            'po_mixed_logistics_count' => $mixed,
        ];
    }
}
