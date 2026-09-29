<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\Period;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RegionalOperationalListsTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasing;

    private User $supplier;

    private Period $period;

    private ExchangeRate $rate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->purchasing = User::factory()->create(['role' => 'purchasing']);
        $this->supplier = User::factory()->create(['role' => 'supplier']);
        $this->period = Period::create(['name' => 'Regional September', 'month' => 9, 'year' => 2026, 'status' => 'open', 'created_by' => $this->purchasing->id]);
        $this->rate = ExchangeRate::create(['currency' => 'USD', 'rate_to_idr' => 1, 'valid_from' => '2026-09-28', 'created_by' => $this->purchasing->id]);
    }

    public function test_purchasing_po_system_preserves_legacy_display_and_response_keys(): void
    {
        $po = $this->po($this->supplier, 'PO/09/2026/601', '2026-09-30');
        $lookups = $this->watchPreferences();
        $response = $this->poRequest($this->purchasing)->assertOk()->assertJsonPath('recordsFiltered', 1);
        $row = $response->json('data.0');
        $this->assertSame('Rp 1.250.001', $row['total_idr']);
        $this->assertStringContainsString('30 Sep 2026', $row['estimated_date']);
        $this->assertSame($po->po_number, $row['po_number_display']);
        $this->assertStringContainsString(route('purchasing.purchase-orders.show', $po), $row['action']);
        $this->assertArrayNotHasKey('resolved_total_idr', $row);
        $this->assertSame(1, $lookups->count);
    }

    public function test_regional_po_display_keeps_sql_date_order_filters_actions_and_amount(): void
    {
        $this->preferences($this->purchasing, 'international');
        $later = $this->po($this->supplier, 'PO/09/2026/602', '2026-10-02');
        $earlier = $this->po($this->supplier, 'PO/09/2026/603', '2026-09-30');
        $before = $earlier->quotations()->firstOrFail()->items()->firstOrFail()->getRawOriginal('amount');
        $lookups = $this->watchPreferences();
        $response = $this->poRequest($this->purchasing, ['order' => [['column' => 5, 'dir' => 'asc']]])->assertOk()->assertJsonPath('recordsFiltered', 2);
        $this->assertSame([$earlier->po_number, $later->po_number], array_column($response->json('data'), 'po_number_display'));
        $this->assertSame('Rp 1,250,001', $response->json('data.0.total_idr'));
        $this->assertStringContainsString('30/09/2026', $response->json('data.0.estimated_date'));
        $this->assertStringContainsString('02/10/2026', $response->json('data.1.estimated_date'));
        $this->assertStringContainsString(route('purchasing.purchase-orders.show', $earlier), $response->json('data.0.action'));
        $this->assertSame(1, $lookups->count);
        $this->poRequest($this->purchasing, ['po_number' => $later->po_number, 'status' => 'active'])->assertOk()->assertJsonPath('recordsFiltered', 1)->assertJsonPath('data.0.po_number_display', $later->po_number);
        $this->poRequest($this->purchasing, ['search' => ['value' => 'regional-note-603', 'regex' => false]])->assertOk()->assertJsonPath('recordsFiltered', 1)->assertJsonPath('data.0.po_number_display', $earlier->po_number);
        $this->assertSame($before, $earlier->quotations()->firstOrFail()->items()->firstOrFail()->getRawOriginal('amount'));
        $this->assertSame('2026-09-30', $earlier->fresh()->estimated_arrival->toDateString());
    }

    public function test_supplier_po_regional_display_retains_owner_isolation_and_actions(): void
    {
        $this->preferences($this->supplier, 'international');
        $own = $this->po($this->supplier, 'PO/09/2026/604', '2026-09-30');
        $other = User::factory()->create(['role' => 'supplier']);
        $foreign = $this->po($other, 'PO/09/2026/605', '2026-10-02');
        $lookups = $this->watchPreferences();
        $response = $this->poRequest($this->supplier)->assertOk()->assertJsonPath('recordsFiltered', 1);
        $response->assertJsonPath('data.0.po_number_display', $own->po_number)->assertJsonPath('data.0.total_idr', 'Rp 1,250,001')->assertJsonPath('data.0.estimated_date', '30/09/2026');
        $this->assertStringContainsString(route('supplier.purchase-orders.show', $own), $response->json('data.0.action'));
        $this->assertStringNotContainsString($foreign->po_number, $response->getContent());
        $this->assertSame(1, $lookups->count);
        $this->poRequest($this->supplier, ['search' => ['value' => $foreign->po_number, 'regex' => false]])->assertOk()->assertJsonPath('recordsFiltered', 0);
    }

    public static function quotationDisplays(): array
    {
        return [
            'System' => ['system', 'system', '28 Sep 2026, 23:35', '28 Sep 2026'],
            'DMY Jakarta' => ['dmy', 'Asia/Jakarta', '29/09/2026, 6:35 AM WIB', '28/09/2026'],
            'Human Jakarta' => ['human', 'Asia/Jakarta', '29 Sep 2026, 6:35 AM WIB', '28 Sep 2026'],
            'ISO Jakarta' => ['iso', 'Asia/Jakarta', '2026-09-29, 6:35 AM WIB', '2026-09-28'],
        ];
    }

    #[DataProvider('quotationDisplays')]
    public function test_quotation_list_dates_keep_calendar_semantics_filters_order_actions_and_model(string $dateFormat, string $timezone, string $timestamp, string $calendar): void
    {
        if ($timezone !== 'system') {
            $this->preferences($this->purchasing, 'international', $dateFormat, $timezone);
        }
        $quote = $this->quotation($this->supplier, 'REQ/09/2026/606');
        $quote->forceFill(['status' => 'submitted', 'submitted_at' => '2026-09-28 23:35:00', 'validity_period' => '2026-09-28'])->save();
        $outside = $this->quotation($this->supplier, 'REQ/10/2026/607');
        $outside->forceFill(['status' => 'submitted', 'submitted_at' => '2026-10-01 00:05:00'])->save();
        $before = $quote->fresh()->getAttributes();
        $lookups = $this->watchPreferences();
        $response = $this->actingAs($this->purchasing)->get(route('purchasing.quotations.index', ['date_from' => '2026-09', 'date_to' => '2026-09', 'currency' => 'USD', 'supplier_id' => $this->supplier->hash]))->assertOk();
        $response->assertSeeText($timestamp)->assertSeeText($calendar)->assertDontSeeText('REQ/10/2026/607');
        $response->assertSee(route('purchasing.quotations.show', $quote), false);
        $this->assertSame([$quote->id], $response->viewData('quotations')->pluck('id')->all());
        $this->assertSame(1, $lookups->count);
        $this->assertSame($before, $quote->fresh()->getAttributes());
        $this->assertSame('2026-09-28', $quote->fresh()->validity_period->toDateString());
        $all = $this->get(route('purchasing.quotations.index'))->assertOk();
        $this->assertSame([$outside->id, $quote->id], $all->viewData('quotations')->pluck('id')->all());
    }

    private function preferences(User $user, string $number, string $date = 'dmy', string $timezone = 'Asia/Jakarta'): void
    {
        $defaults = config('user_preferences.defaults');
        unset($defaults['sidebar_revision']);
        $user->preference()->create([...$defaults, 'timezone' => $timezone, 'date_format' => $date, 'time_format' => '12h', 'number_format' => $number]);
    }

    private function watchPreferences(): object
    {
        $counter = (object) ['count' => 0];
        DB::listen(function ($query) use ($counter): void {
            if (str_contains(strtolower($query->sql), 'user_preferences')) {
                $counter->count++;
            }
        });

        return $counter;
    }

    private function quotation(User $supplier, string $number): Quotation
    {
        $pr = PurchaseRequisition::create(['period_id' => $this->period->id, 'created_by' => $this->purchasing->id, 'pr_number' => $number, 'status' => 'completed']);
        $item = $pr->items()->create(['hs_code' => '7209.16.00', 'material_name' => 'Regional Plate', 'quantity' => 2, 'shape' => 'Flat', 'thickness' => 2.5, 'width' => 1000, 'length' => 2000, 'weight_needed' => 100]);
        $quote = Quotation::create(['pr_id' => $pr->id, 'supplier_id' => $supplier->id, 'exchange_rate_id' => $this->rate->id, 'currency' => 'USD', 'status' => 'accepted', 'submitted_at' => '2026-09-28 23:35:00']);
        $quote->items()->create(['pr_item_id' => $item->id, 'price_per_kg' => 2.5, 'amount' => '1250000.5']);

        return $quote;
    }

    private function po(User $supplier, string $number, string $eta): PurchaseOrder
    {
        $quote = $this->quotation($supplier, str_replace('PO/', 'REQ/', $number));
        $po = PurchaseOrder::create(['supplier_id' => $supplier->id, 'currency' => 'USD', 'exchange_rate_id' => $this->rate->id, 'po_number' => $number, 'status' => 'active', 'created_by' => $this->purchasing->id, 'estimated_arrival' => $eta, 'notes' => 'regional-note-'.substr($number, -3)]);
        $po->quotations()->attach($quote->id);

        return $po;
    }

    private function poRequest(User $user, array $extra = [])
    {
        $supplier = $user->role === 'supplier';
        $definitions = [['po_number_display', 'po_number', true], ['period_name', 'period_name', false], ['pr_reference', 'pr_reference', true], ['total_idr', 'total_idr', false], ['status_badge', 'status', true], ['estimated_date', 'estimated_arrival', true], ['action', 'action', false], ['remark_display', 'remark_display', true]];
        $columns = array_map(fn ($field) => ['data' => $field[0], 'name' => $field[1], 'orderable' => $field[1] === 'estimated_arrival' ? 'true' : 'false', 'searchable' => $field[2] ? 'true' : 'false', 'search' => ['value' => '', 'regex' => false]], $definitions);
        $params = $extra + ['draw' => 1, 'start' => 0, 'length' => 25, 'columns' => $columns, 'order' => [], 'search' => ['value' => '', 'regex' => false]];

        return $this->actingAs($user)->withHeader('X-Requested-With', 'XMLHttpRequest')->getJson(route($supplier ? 'supplier.purchase-orders.index' : 'purchasing.purchase-orders.index').'?'.http_build_query($params));
    }
}
