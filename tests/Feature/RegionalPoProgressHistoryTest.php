<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\Period;
use App\Models\PoItemProgressUpdate;
use App\Models\PrItem;
use App\Models\PrItemAward;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\User;
use App\Services\MaterialProgressService;
use App\Services\PrItemAwardService;
use App\Services\PurchaseOrderGenerationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RegionalPoProgressHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasing;

    private User $supplier;

    private PurchaseOrder $po;

    private PrItemAward $award;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-28 23:35:00', 'UTC'));

        $this->purchasing = User::factory()->create(['role' => 'purchasing']);
        $this->supplier = User::factory()->create(['role' => 'supplier', 'name' => '<b>Supplier</b>']);

        $period = Period::create([
            'name' => 'Regional progress test period',
            'month' => 9,
            'year' => 2026,
            'status' => 'open',
            'created_by' => $this->purchasing->id,
        ]);
        $rate = ExchangeRate::create([
            'currency' => 'USD',
            'rate_to_idr' => 16000,
            'valid_from' => '2026-09-27',
            'created_by' => $this->purchasing->id,
        ]);
        $requisition = PurchaseRequisition::create([
            'period_id' => $period->id,
            'created_by' => $this->purchasing->id,
            'pr_number' => 'REQ/09/2026/931',
            'status' => 'bidding',
            'notes' => 'Regional progress test',
        ]);
        $quotation = Quotation::create([
            'pr_id' => $requisition->id,
            'supplier_id' => $this->supplier->id,
            'currency' => 'USD',
            'exchange_rate_id' => $rate->id,
            'status' => Quotation::STATUS_SUBMITTED,
            'estimated_delivery' => '2026-10-15',
            'payment_terms' => 'TT 30 Days',
            'validity_period' => '2026-10-28',
            'submitted_at' => '2026-09-28 20:00:00',
        ]);
        $item = PrItem::create([
            'pr_id' => $requisition->id,
            'hs_code' => '7209.16.00',
            'material_name' => 'Regional Steel Plate',
            'quantity' => 20,
            'shape' => PrItem::SHAPE_FLAT,
            'thickness' => 2.0,
            'width' => 100,
            'length' => 200,
            'weight_needed' => 20,
        ]);
        $quotationItem = $quotation->items()->create([
            'pr_item_id' => $item->id,
            'is_available' => true,
            'price_per_kg' => 2.5,
            'amount' => 50,
            'available_qty' => 20,
        ]);
        $award = app(PrItemAwardService::class)->awardItem($item, $quotationItem, $this->purchasing);
        $this->po = app(PurchaseOrderGenerationService::class)
            ->generateFromAwards(collect([$award]), $this->purchasing)
            ->firstOrFail();
        $this->award = $award->fresh();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_both_history_endpoints_add_aliases_and_keep_the_legacy_response_unchanged(): void
    {
        $first = $this->recordProgress('order_confirmed', '<img src=x onerror=alert(1)>');
        $first->forceFill(['created_at' => '2026-09-28 23:35:00', 'updated_at' => '2026-09-28 23:35:00'])->save();
        $second = $this->recordProgress('ready_to_ship', '<script>alert(1)</script>');
        $second->forceFill([
            'created_at' => '2026-09-29 00:05:00',
            'updated_at' => '2026-09-29 00:05:00',
            'estimated_ready_date' => '2026-09-29',
        ])->save();

        foreach (['purchasing' => $this->purchasing, 'supplier' => $this->supplier] as $portal => $user) {
            $system = $this->requestHistory($portal, $user)->assertOk()->json();
            $rows = $system['history'];

            $this->assertSame([$second->id, $first->id], array_column($rows, 'id'));
            $this->assertSame(['29 Sep 2026', '28 Sep 2026'], array_column($rows, 'estimated_ready_date_display'));
            $this->assertSame([
                CarbonImmutable::parse('2026-09-29 00:05:00', 'UTC')->format('d M Y H:i'),
                CarbonImmutable::parse('2026-09-28 23:35:00', 'UTC')->format('d M Y H:i'),
            ], array_column($rows, 'created_at_display'));

            foreach ($rows as $row) {
                $this->assertArrayHasKey('created_at', $row);
                $this->assertArrayHasKey('estimated_ready_date', $row);
                $this->assertSame($this->supplier->name, $row['updated_by']);
                $this->assertContains($row['note'], ['<img src=x onerror=alert(1)>', '<script>alert(1)</script>']);
                $this->assertSame($row['status'] === PoItemProgressUpdate::STATUS_READY_TO_SHIP ? 'success' : 'info', $row['status_tone']);
            }

            $this->savePreference($user, 'Asia/Jakarta', 'iso', '24h');
            $regional = $this->requestHistory($portal, $user)->assertOk()->json();
            $this->assertSame(
                array_map($this->withoutRegionalAliases(...), $rows),
                array_map($this->withoutRegionalAliases(...), $regional['history']),
            );
            $this->assertSame($system['success'], $regional['success']);
            $this->assertSame($system['award_id'], $regional['award_id']);
            $this->assertSame($system['material_name'], $regional['material_name']);
            $this->assertSame($system['projection'], $regional['projection']);
            $this->assertSame(['2026-09-29', '2026-09-28'], array_column($regional['history'], 'estimated_ready_date_display'));
            $this->assertSame(['2026-09-29 07:05 WIB', '2026-09-29 06:35 WIB'], array_column($regional['history'], 'created_at_display'));
        }
    }

    public function test_regional_timestamp_alias_converts_midnight_without_mutating_legacy_fields_or_models(): void
    {
        $update = $this->recordProgress('order_confirmed', 'Boundary note');
        $update->forceFill([
            'created_at' => '2026-09-28 23:35:00',
            'updated_at' => '2026-09-28 23:35:00',
            'estimated_ready_date' => '2026-09-28',
        ])->save();
        $before = $update->fresh()->getRawOriginal();

        foreach (['purchasing' => $this->purchasing, 'supplier' => $this->supplier] as $portal => $user) {
            $system = $this->requestHistory($portal, $user)->assertOk()->json('history.0');
            $this->savePreference($user, 'Asia/Jakarta', 'dmy', '12h');
            $regional = $this->requestHistory($portal, $user)->assertOk()->json('history.0');

            $this->assertSame(CarbonImmutable::parse('2026-09-28 23:35:00', 'UTC')->format('d M Y H:i'), $system['created_at_display']);
            $this->assertSame('29/09/2026 6:35 AM WIB', $regional['created_at_display']);
            $this->assertSame('28/09/2026', $regional['estimated_ready_date_display']);
            $this->assertSame($system['created_at'], $regional['created_at']);
            $this->assertSame($system['estimated_ready_date'], $regional['estimated_ready_date']);
            $this->assertSame($before, $update->fresh()->getRawOriginal());
        }
    }

    public function test_calendar_alias_supports_human_dmy_iso_and_null_without_timezone_shift(): void
    {
        $update = $this->recordProgress('order_confirmed', 'Date note');
        $update->forceFill(['estimated_ready_date' => '2026-09-28'])->save();

        foreach ([
            ['human', '24h', '28 Sep 2026'],
            ['dmy', '24h', '28/09/2026'],
            ['iso', '12h', '2026-09-28'],
        ] as [$dateFormat, $timeFormat, $expectedDate]) {
            $this->savePreference($this->supplier, 'Asia/Jakarta', $dateFormat, $timeFormat);
            $row = $this->requestHistory('supplier', $this->supplier)->assertOk()->json('history.0');
            $this->assertSame($expectedDate, $row['estimated_ready_date_display']);
            $this->assertSame('28 Sep 2026', $row['estimated_ready_date']);
        }

        $update->forceFill(['estimated_ready_date' => null])->save();
        $this->savePreference($this->supplier, 'Asia/Jakarta', 'dmy', '12h');
        $row = $this->requestHistory('supplier', $this->supplier)->assertOk()->json('history.0');
        $this->assertNull($row['estimated_ready_date']);
        $this->assertNull($row['estimated_ready_date_display']);
    }

    public function test_missing_event_instant_remains_null_on_both_endpoints(): void
    {
        $update = $this->recordProgress('order_confirmed', 'No event time');
        $update->forceFill(['created_at' => null])->save();

        foreach (['purchasing' => $this->purchasing, 'supplier' => $this->supplier] as $portal => $user) {
            $row = $this->requestHistory($portal, $user)->assertOk()->json('history.0');
            $this->assertNull($row['created_at']);
            $this->assertNull($row['created_at_display']);
        }
    }

    public function test_history_preference_lookup_is_once_for_one_and_multiple_rows_on_both_endpoints(): void
    {
        $this->recordProgress('order_confirmed', 'First');
        $this->savePreference($this->supplier, 'Asia/Jakarta', 'human', '24h');
        $this->savePreference($this->purchasing, 'Asia/Jakarta', 'human', '24h');

        $queries = (object) ['count' => 0];
        DB::listen(function ($query) use ($queries): void {
            if (str_starts_with(strtolower($query->sql), 'select') && str_contains(strtolower($query->sql), 'user_preferences')) {
                $queries->count++;
            }
        });

        foreach (['purchasing' => $this->purchasing, 'supplier' => $this->supplier] as $portal => $user) {
            $queries->count = 0;
            $this->requestHistory($portal, $user)->assertOk()->assertJsonCount(1, 'history');
            $this->assertSame(1, $queries->count, $portal.' with one row must resolve preferences once.');
        }

        for ($index = 0; $index < 5; $index++) {
            $this->recordProgress('ready_to_ship', 'Update '.$index);
        }

        foreach (['purchasing' => $this->purchasing, 'supplier' => $this->supplier] as $portal => $user) {
            $queries->count = 0;
            $this->requestHistory($portal, $user)->assertOk()->assertJsonCount(6, 'history');
            $this->assertSame(1, $queries->count, $portal.' with multiple rows must resolve preferences once.');
        }
    }

    public function test_supplier_ownership_and_import_context_guards_remain_enforced(): void
    {
        $foreign = User::factory()->create(['role' => 'supplier']);
        $this->requestHistory('supplier', $foreign)->assertForbidden();

        $localOnly = User::factory()->create(['role' => 'supplier']);
        $localOnly->supplierScopes()->delete();
        $localOnly->supplierScopes()->create(['scope' => 'local']);
        $this->requestHistory('supplier', $localOnly)->assertForbidden();

        $this->requestHistory('purchasing', $this->purchasing)->assertOk();
    }

    private function recordProgress(string $status, string $note): PoItemProgressUpdate
    {
        return app(MaterialProgressService::class)->updateProgress($this->po, $this->award, $this->supplier, [
            'status' => $status,
            'estimated_ready_date' => '2026-09-28',
            'note' => $note,
        ]);
    }

    private function requestHistory(string $portal, User $user)
    {
        $this->app->forgetScopedInstances();

        $route = $portal === 'purchasing'
            ? 'purchasing.purchase-orders.item-progress.history'
            : 'supplier.purchase-orders.item-progress.history';

        return $this->actingAs($user)
            ->withSession(['supplier_context' => 'import'])
            ->getJson(route($route, ['po_id' => $this->po, 'award_id' => $this->award]));
    }

    private function savePreference(User $user, string $timezone, string $dateFormat, string $timeFormat): void
    {
        $user->preference()->updateOrCreate([], [
            ...config('user_preferences.defaults'),
            'timezone' => $timezone,
            'date_format' => $dateFormat,
            'time_format' => $timeFormat,
        ]);
        $this->app->forgetScopedInstances();
    }

    private function withoutRegionalAliases(array $row): array
    {
        unset($row['created_at_display'], $row['estimated_ready_date_display']);

        return $row;
    }
}
