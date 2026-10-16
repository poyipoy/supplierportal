<?php

namespace Tests\Feature\Procurement;

use App\Models\Attachment;
use App\Models\FileInspection;
use App\Models\PurchaseOrder;
use App\Models\QuotationItem;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Services\PrItemAwardService;
use App\Services\PurchaseOrderGenerationService;
use App\Services\ShipmentService;
use App\Support\Procurement\ProcurementFeatures;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\AttachesShipmentMtc;
use Tests\TestCase;

/**
 * Gate 2 shipment concurrency hotfix (I-2, I-9): real MySQL row locks with
 * subprocess workers. The invariant for every completed run is that committed
 * submitted quantity never exceeds the frozen ordered quantity, with the
 * shipment MTC switch both on and off.
 */
class ShipmentSubmissionConcurrencyTest extends TestCase
{
    use AttachesShipmentMtc;
    use DatabaseTruncation;
    use ProcurementFixtures;

    private const ROUNDS = 4;

    // Retain the migration-owned singleton used to serialize PO ZIP admission.
    protected $exceptTables = ['local_po_document_capacity'];

    protected function beforeTruncatingDatabase(): void
    {
        $this->assertSame('adasi_portal_test', DB::connection()->getDatabaseName());
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        $this->setUpProcurementActors();
        config(['procurement.ordered_quantity_write_enabled' => true, 'procurement.allow_offer_surplus' => true]);
    }

    // Committed rows must not leak into later RefreshDatabase tests.
    protected function tearDown(): void
    {
        $this->truncateTablesForAllConnections();
        parent::tearDown();
    }

    public static function switchModes(): array
    {
        return ['MTC enabled' => [true], 'MTC disabled' => [false]];
    }

    #[DataProvider('switchModes')]
    public function test_seven_plus_seven_against_twelve_never_submits_both(bool $mtc): void
    {
        $this->useSwitch($mtc);
        $rounds = [];
        for ($round = 1; $round <= self::ROUNDS; $round++) {
            [$po, $line] = $this->orderedTwelve();
            $drafts = [$this->draft($po, $line, 7, $mtc), $this->draft($po, $line, 7, $mtc)];
            $outcomes = $this->race(array_map(fn (Shipment $draft) => ['submit', $draft->id], $drafts), $mtc);
            $shipped = $this->submittedQty($po);
            $rounds[] = sprintf('%s shipped=%d', implode('+', $outcomes), $shipped);

            $this->assertLessThanOrEqual(12, $shipped, 'I-2 violated: '.end($rounds));
            $this->assertSame(7, $shipped, 'Exactly one of the two submissions commits: '.end($rounds));
            $this->assertSame(1, collect($outcomes)->filter(fn ($o) => $o === 'submitted')->count(), end($rounds));
            $this->assertNoDuplicateRows($drafts);
        }
        fwrite(STDERR, sprintf("[7+7 mtc=%s] %s\n", $mtc ? 'on' : 'off', implode(' | ', $rounds)));
    }

    #[DataProvider('switchModes')]
    public function test_seven_plus_five_fits_twelve_and_both_submit(bool $mtc): void
    {
        $this->useSwitch($mtc);
        $rounds = [];
        for ($round = 1; $round <= self::ROUNDS; $round++) {
            [$po, $line] = $this->orderedTwelve();
            $drafts = [$this->draft($po, $line, 7, $mtc), $this->draft($po, $line, 5, $mtc)];
            $outcomes = $this->race(array_map(fn (Shipment $draft) => ['submit', $draft->id], $drafts), $mtc);
            $shipped = $this->submittedQty($po);
            $rounds[] = sprintf('%s shipped=%d', implode('+', $outcomes), $shipped);

            $this->assertSame(['submitted', 'submitted'], $outcomes, end($rounds));
            $this->assertSame(12, $shipped, end($rounds));
            $this->assertNoDuplicateRows($drafts);
        }
        fwrite(STDERR, sprintf("[7+5 mtc=%s] %s\n", $mtc ? 'on' : 'off', implode(' | ', $rounds)));
    }

    public function test_enabled_switch_refuses_the_concurrent_submission_without_validated_mtc(): void
    {
        $this->useSwitch(true);
        [$po, $line] = $this->orderedTwelve();
        $withMtc = $this->draft($po, $line, 7, true);
        $withoutMtc = $this->draft($po, $line, 5, false);

        $outcomes = $this->race([['submit', $withMtc->id], ['submit', $withoutMtc->id]], true, sort: false);

        $this->assertSame(['submitted', 'refused:MtcRequiredException'], $outcomes);
        $this->assertSame(Shipment::STATUS_DRAFT, $withoutMtc->fresh()->status);
        $this->assertSame(7, $this->submittedQty($po));
    }

    public function test_concurrent_mtc_removal_and_submission_never_submit_without_validated_mtc(): void
    {
        $this->useSwitch(true);
        $seen = [];
        for ($round = 1; $round <= self::ROUNDS; $round++) {
            [$po, $line] = $this->orderedTwelve();
            $draft = $this->draft($po, $line, 7, false);
            $mtc = $this->attachValidatedMtc($draft);

            $outcomes = $this->race([
                ['submit', $draft->id],
                ['remove-mtc', $draft->id, $mtc->id, $this->supplier->id],
            ], true, sort: false);
            $seen[] = implode('+', $outcomes);
            $draft->refresh();

            if ($draft->status === Shipment::STATUS_SUBMITTED) {
                $this->assertSame(['submitted', 'refused:InvalidArgumentException'], $outcomes);
                $this->assertSame(1, $this->validatedMtc($draft), 'I-9: a submitted shipment keeps its validated MTC');
            } else {
                $this->assertSame(['refused:MtcRequiredException', 'removed'], $outcomes);
                $this->assertSame(0, $this->validatedMtc($draft));
                $this->assertSame(0, $this->submittedQty($po));
            }
        }
        fwrite(STDERR, '[submit vs remove-mtc] '.implode(' | ', $seen)."\n");
    }

    public function test_a_deadlock_is_retried_and_the_submission_commits_exactly_once(): void
    {
        $this->useSwitch(true);
        [$po, $line] = $this->orderedTwelve();
        $draft = $this->draft($po, $line, 7, true);
        // Fail after the notification is registered (the final reload), so a
        // discarded after-commit callback of the failed attempt is observable.
        $attempts = $this->injectDeadlocks(times: 1);

        $submitted = app(ShipmentService::class)->submitShipment($draft->id);
        $attempts->active = false;

        $this->assertSame(2, $attempts->count, 'one deadlock, one successful retry');
        $this->assertSame(Shipment::STATUS_SUBMITTED, $submitted->status);
        $this->assertSame(1, ShipmentItem::where('shipment_id', $draft->id)->count());
        $this->assertSame(7, $this->submittedQty($po));
        $this->assertSame(1, Shipment::where('status', Shipment::STATUS_SUBMITTED)->count());
        $this->assertSame(1, $this->submittedNotifications(), 'after-commit notification delivered once');
    }

    public function test_retry_exhaustion_fails_closed_without_partial_state(): void
    {
        $this->useSwitch(true);
        [$po, $line] = $this->orderedTwelve();
        $draft = $this->draft($po, $line, 7, true);
        $attempts = $this->injectDeadlocks(times: PHP_INT_MAX);

        try {
            app(ShipmentService::class)->submitShipment($draft->id);
            $this->fail('An exhausted retry must surface the concurrency error.');
        } catch (QueryException $exception) {
            $this->assertSame(40001, $exception->getCode());
        } finally {
            $attempts->active = false;
        }

        $this->assertSame(3, $attempts->count, 'bounded to three attempts');
        $this->assertSame(Shipment::STATUS_DRAFT, $draft->fresh()->status);
        $this->assertNull($draft->fresh()->submitted_at);
        $this->assertSame(1, ShipmentItem::where('shipment_id', $draft->id)->count());
        $this->assertSame(0, $this->submittedQty($po));
        $this->assertSame(0, $this->submittedNotifications());
    }

    private function useSwitch(bool $enabled): void
    {
        config(['procurement.shipment_mtc_enabled' => $enabled]);
        $this->assertSame($enabled, ProcurementFeatures::shipmentMtcEnabled(), 'runtime-effective MTC switch');
    }

    /**
     * @return array{0: PurchaseOrder, 1: QuotationItem}
     */
    private function orderedTwelve(): array
    {
        $pr = $this->createRequisition([['quantity' => 10, 'weight_needed' => 3]]);
        $quotation = $this->createQuotation($pr, [['available_qty' => 15, 'offered_weight_per_unit' => 3]]);
        app(PrItemAwardService::class)->awardBatch($pr, [$pr->items[0]->id => $quotation->items[0]->id], $this->purchasing, [$pr->items[0]->id => ['order_qty' => 12]]);
        $po = app(PurchaseOrderGenerationService::class)->generateFromAwards($pr->fresh()->awards->pluck('id')->all(), $this->purchasing)->sole();
        $this->assertSame(12, (int) $po->total_ordered_quantity);

        return [$po, $quotation->items[0]];
    }

    private function draft(PurchaseOrder $po, QuotationItem $line, int $qty, bool $withMtc): Shipment
    {
        $draft = app(ShipmentService::class)->createDraft($this->supplier, ['items' => [[
            'purchase_order_id' => $po->id, 'quotation_item_id' => $line->id, 'shipped_qty' => $qty, 'actual_weight_kg' => $qty * 3,
        ]]]);
        if ($withMtc) {
            $this->attachValidatedMtc($draft);
        }

        return $draft;
    }

    /**
     * @param  list<list<int|string>>  $jobs
     * @return list<string>
     */
    private function race(array $jobs, bool $mtc, bool $sort = true): array
    {
        $start = (string) (microtime(true) + 1.5);
        $processes = array_map(fn (array $job) => new Process(
            [PHP_BINARY, base_path('tests/Support/shipment-submit-concurrency-worker.php'), (string) $job[0], (string) $job[1], $start, $mtc ? 'true' : 'false', ...array_map('strval', array_slice($job, 2))],
            base_path(),
            ['APP_ENV' => 'testing'],
        ), $jobs);
        foreach ($processes as $process) {
            $process->start();
        }
        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        }
        $outcomes = array_map(fn (Process $process) => trim($process->getOutput()), $processes);
        if ($sort) {
            sort($outcomes);
        }

        return $outcomes;
    }

    private function submittedQty(PurchaseOrder $po): int
    {
        return (int) ShipmentItem::query()
            ->whereHas('shipment', fn ($shipment) => $shipment->where('status', Shipment::STATUS_SUBMITTED))
            ->where('purchase_order_id', $po->id)
            ->sum('shipped_qty');
    }

    /**
     * @param  list<Shipment>  $drafts
     */
    private function assertNoDuplicateRows(array $drafts): void
    {
        foreach ($drafts as $draft) {
            $this->assertSame(1, ShipmentItem::where('shipment_id', $draft->id)->count(), 'no duplicated shipment lines');
        }
        $this->assertSame(count($drafts), Shipment::whereIn('id', array_map(fn ($d) => $d->id, $drafts))->count(), 'no extra shipments');
    }

    private function validatedMtc(Shipment $shipment): int
    {
        return Attachment::query()
            ->where('attachable_type', $shipment->getMorphClass())
            ->where('attachable_id', $shipment->id)
            ->whereHas('fileInspection', fn ($q) => $q->where('status', FileInspection::STATUS_VALIDATED))
            ->count();
    }

    private function submittedNotifications(): int
    {
        return (int) DB::table('notifications')->where('data', 'like', '%"event":"shipment.submitted"%')->count();
    }

    /**
     * Throws a MySQL-shaped deadlock (SQLSTATE 40001) on the final reload of
     * the submitted shipment, after every write and the notification call.
     */
    private function injectDeadlocks(int $times): object
    {
        $state = new class
        {
            public int $count = 0;

            public int $failed = 0;

            public bool $active = true;
        };
        DB::connection()->beforeExecuting(function (string $sql) use ($state, $times): void {
            // Model::fresh() of the locked shipment; the lock read ends in "for update".
            if ($state->active && $sql === 'select * from `shipments` where `id` = ? limit 1') {
                $state->count++;
                if ($state->failed < $times) {
                    $state->failed++;
                    throw new QueryException('mysql', $sql, [], new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction', 40001));
                }
            }
        });

        return $state;
    }
}
