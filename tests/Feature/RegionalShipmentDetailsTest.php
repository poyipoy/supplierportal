<?php

namespace Tests\Feature;

use App\Exports\ShipmentsExport;
use App\Models\ExchangeRate;
use App\Models\Period;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\QcInspection;
use App\Models\Quotation;
use App\Models\Shipment;
use App\Models\User;
use App\Services\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RegionalShipmentDetailsTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasing;

    private User $supplier;

    private Period $period;

    private ExchangeRate $rate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
        $this->purchasing = User::factory()->create(['role' => 'purchasing']);
        $this->supplier = User::factory()->create(['role' => 'supplier']);
        $this->period = Period::create(['name' => 'Regional shipment details', 'month' => 9, 'year' => 2026, 'status' => 'open', 'created_by' => $this->purchasing->id]);
        $this->rate = ExchangeRate::create(['currency' => 'USD', 'rate_to_idr' => 16000, 'valid_from' => '2026-09-01', 'created_by' => $this->purchasing->id]);
    }

    public function test_system_preserves_both_shipment_detail_display_contracts(): void
    {
        $shipment = $this->shipment();
        foreach (['purchasing' => $this->purchasing, 'supplier' => $this->supplier] as $portal => $user) {
            $response = $this->detail($user, $portal, $shipment)->assertOk();
            $text = $this->text($response->getContent());
            $this->assertHasText('28 Sep 2026', $text);
            $this->assertHasText('Dispatched: 30 Sep 2026', $text);
            $this->assertHasText('Arrived: 02 Oct 2026', $text);
            $this->assertHasText('1,250 pcs', $text);
            $this->assertHasText('1234.57 Kg', $text);
        }
    }

    public function test_regional_details_separate_calendar_dates_from_event_instants_and_preserve_precision(): void
    {
        $shipment = $this->shipment();
        $raw = $shipment->getRawOriginal();
        $items = $shipment->items()->get()->map->getRawOriginal()->all();
        foreach (['purchasing' => $this->purchasing, 'supplier' => $this->supplier] as $portal => $user) {
            $this->preference($user);
            $html = $this->detail($user, $portal, $shipment)->assertOk()->getContent();
            $text = $this->text($html);
            $this->assertHasText('29/09/2026 WIB', $text);
            $this->assertHasText('Dispatched: 30/09/2026', $text);
            $this->assertHasText('Arrived: 02/10/2026', $text);
            $this->assertHasText('1.250 pcs', $text);
            $this->assertHasText('1.234,57 Kg', $text);
            $this->assertHasText(route($portal.'.purchase-orders.show', $shipment->items->first()->purchaseOrder), $html);
            $this->assertHasText('<th scope="col" class="text-end">Actual Weight</th>', $html);
        }
        $this->assertSame($raw, $shipment->fresh()->getRawOriginal());
        $this->assertSame($items, $shipment->items()->get()->map->getRawOriginal()->all());
    }

    public function test_qc_inspection_is_an_instant_while_arrival_stays_a_calendar_date_and_export_stays_fixed(): void
    {
        $shipment = $this->shipment();
        $inspector = User::factory()->create(['role' => 'qc']);
        $inspection = QcInspection::create(['po_id' => $shipment->items->first()->purchase_order_id, 'shipment_id' => $shipment->id, 'inspected_by' => $inspector->id, 'status' => 'ok', 'inspected_at' => '2026-09-28 23:35:00']);
        $export = new ShipmentsExport(supplierId: $this->supplier->id);
        $before = $export->collection()->all();
        $this->preference($this->purchasing);
        $html = $this->detail($this->purchasing, 'purchasing', $shipment)->assertOk()->getContent();
        $text = $this->text($html);
        $this->assertHasText('29/09/2026, 6:35 AM WIB', $text);
        $this->assertHasText('Arrived: 02/10/2026', $text);
        $this->assertHasText(route('qc.inspections.show', $inspection), $html);
        $this->assertSame('2026-09-28 23:35:00', $inspection->fresh()->getRawOriginal('inspected_at'));
        $this->assertSame($before, $export->collection()->all());
    }

    public function test_human_iso_and_international_preferences_preserve_the_date_and_numeric_digits(): void
    {
        $shipment = $this->shipment();
        foreach ([
            ['purchasing', $this->purchasing, 'human', '30 Sep 2026', '29 Sep 2026 WIB'],
            ['supplier', $this->supplier, 'iso', '2026-09-30', '2026-09-29 WIB'],
        ] as [$portal, $user, $format, $date, $instant]) {
            $this->preference($user, ['date_format' => $format, 'number_format' => 'international']);
            $html = $this->detail($user, $portal, $shipment)->assertOk()->getContent();
            $text = $this->text($html);
            $this->assertHasText('Dispatched: '.$date, $text);
            $this->assertHasText($instant, $text);
            $this->assertHasText('1,250 pcs', $text);
            $this->assertHasText('1,234.57 Kg', $text);
        }
    }

    public function test_eta_and_mutation_form_values_actions_and_attributes_remain_canonical(): void
    {
        $shipment = $this->shipment();
        $shipment->forceFill(['status' => 'submitted', 'actual_arrival_date' => null])->save();
        foreach (['purchasing' => $this->purchasing, 'supplier' => $this->supplier] as $portal => $user) {
            $legacy = $this->detail($user, $portal, $shipment)->assertOk()->getContent();
            $this->preference($user);
            $regional = $this->detail($user, $portal, $shipment)->assertOk()->getContent();
            $this->assertHasText('ETA: 03/10/2026', $this->text($regional));
            $this->assertSame($this->machineMarkup($legacy), $this->machineMarkup($regional));
            if ($portal === 'purchasing') {
                $this->assertHasText('value="2026-10-10"', $regional);
                $this->assertHasText(route('purchasing.shipments.confirm-arrival', $shipment), $regional);
            } else {
                $this->assertHasText(route('supplier.shipments.cancel', $shipment), $regional);
                $this->assertHasText('id="btnConfirmCancel"', $regional);
            }
        }
    }

    public function test_preference_queries_do_not_scale_with_related_items(): void
    {
        $single = $this->shipment();
        $multiple = $this->shipment(3);
        $this->preference($this->purchasing);
        $queries = (object) ['count' => 0];
        DB::listen(function ($query) use ($queries): void {
            if (str_contains(strtolower($query->sql), 'user_preferences')) {
                $queries->count++;
            }
        });
        foreach ([$single, $multiple] as $shipment) {
            $queries->count = 0;
            $this->detail($this->purchasing, 'purchasing', $shipment)->assertOk();
            $this->assertSame(1, $queries->count);
        }
    }

    public function test_supplier_ownership_and_import_scope_remain_authoritative(): void
    {
        $shipment = $this->shipment();
        $other = User::factory()->create(['role' => 'supplier']);
        $this->preference($other);
        $this->detail($other, 'supplier', $shipment)->assertForbidden();
        $this->supplier->supplierScopes()->delete();
        $this->supplier->supplierScopes()->create(['scope' => 'local']);
        $this->preference($this->supplier);
        $this->detail($this->supplier, 'supplier', $shipment)->assertForbidden();
    }

    private function detail(User $user, string $portal, Shipment $shipment)
    {
        // Multiple feature-test requests share the app; real HTTP requests have a fresh scoped formatter.
        $this->app->forgetScopedInstances();

        return $this->actingAs($user)->get(route($portal.'.shipments.show', $shipment));
    }

    private function preference(User $user, array $regional = []): void
    {
        $defaults = config('user_preferences.defaults');
        unset($defaults['sidebar_revision']);
        $user->preference()->create([...$defaults, 'timezone' => 'Asia/Jakarta', 'date_format' => 'dmy', 'time_format' => '12h', 'number_format' => 'indonesian', ...$regional]);
    }

    private function assertHasText(string $expected, string $actual): void
    {
        $this->assertTrue(str_contains($actual, $expected), 'Missing expected shipment detail text: '.$expected);
    }

    private function text(string $html): string
    {
        return preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html)));
    }

    private function machineMarkup(string $html): array
    {
        preg_match_all('/<(?:input|select|option|form)\b[^>]*>|\b(?:href|data-[\w-]+|datetime)="[^"]*"/', $html, $matches);

        return $matches[0];
    }

    private function shipment(int $count = 1): Shipment
    {
        $requisition = PurchaseRequisition::create(['period_id' => $this->period->id, 'created_by' => $this->purchasing->id, 'pr_number' => 'REQ-REG-'.PurchaseRequisition::count(), 'status' => 'completed']);
        $quotation = Quotation::create(['pr_id' => $requisition->id, 'supplier_id' => $this->supplier->id, 'exchange_rate_id' => $this->rate->id, 'currency' => 'USD', 'status' => 'accepted']);
        $po = PurchaseOrder::create(['supplier_id' => $this->supplier->id, 'currency' => 'USD', 'exchange_rate_id' => $this->rate->id, 'po_number' => 'PO-REG-'.PurchaseOrder::count(), 'status' => 'active', 'created_by' => $this->purchasing->id, 'estimated_arrival' => '2026-10-03']);
        $po->quotations()->attach($quotation->id);
        $items = [];
        for ($index = 0; $index < $count; $index++) {
            $prItem = $requisition->items()->create(['hs_code' => '7209.16.00', 'material_name' => 'Regional plate '.$index, 'quantity' => 1250, 'shape' => 'Flat', 'thickness' => 2.5, 'width' => 1000, 'length' => 2000, 'weight_needed' => 100]);
            $quotationItem = $quotation->items()->create(['pr_item_id' => $prItem->id, 'price_per_kg' => 2.5, 'amount' => '1250000.5']);
            $items[] = ['purchase_order_id' => $po->id, 'quotation_item_id' => $quotationItem->id, 'shipped_qty' => 1250, 'actual_weight_kg' => '1234.5678'];
        }
        $shipment = app(ShipmentService::class)->createDraft($this->supplier, ['shipment_date' => '2026-09-30', 'estimated_arrival_date' => '2026-10-03', 'items' => $items]);
        $shipment->forceFill(['status' => 'arrived', 'actual_arrival_date' => '2026-10-02', 'created_at' => '2026-09-28 23:35:00'])->save();

        return $shipment->fresh(['items.purchaseOrder']);
    }
}
