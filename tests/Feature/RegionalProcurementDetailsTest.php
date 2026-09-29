<?php

namespace Tests\Feature;

use App\Exports\PurchaseOrderDetailExport;
use App\Exports\QuotationDetailExport;
use App\Models\ExchangeRate;
use App\Models\Period;
use App\Models\PoItemProgressUpdate;
use App\Models\PrItemAward;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\Supplier;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RegionalProcurementDetailsTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasing;

    private User $supplier;

    private Period $period;

    private ExchangeRate $rate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 9, 28)->setTime(12, 0));
        $this->purchasing = User::factory()->create(['role' => 'purchasing']);
        $this->supplier = User::factory()->create(['role' => 'supplier']);
        Supplier::create(['user_id' => $this->supplier->id, 'company_name' => 'Regional Detail Supplier']);
        $this->period = Period::create(['name' => 'September Details', 'month' => 9, 'year' => 2026, 'status' => 'open', 'created_by' => $this->purchasing->id]);
        $this->rate = ExchangeRate::create(['currency' => 'USD', 'rate_to_idr' => 1.25, 'valid_from' => '2026-09-28', 'created_by' => $this->purchasing->id]);
    }

    public static function detailSurfaces(): array
    {
        return [
            'Purchasing PO' => ['purchasing', 'purchase-orders'],
            'Supplier PO' => ['supplier', 'purchase-orders'],
            'Purchasing Quotation' => ['purchasing', 'quotations'],
            'Supplier Quotation' => ['supplier', 'quotations'],
        ];
    }

    #[DataProvider('detailSurfaces')]
    public function test_detail_regional_display_keeps_precision_models_actions_and_machine_values(string $role, string $resource): void
    {
        $quote = $this->quotation(2);
        $record = $resource === 'purchase-orders' ? $this->po($quote) : $quote;
        $user = $role === 'supplier' ? $this->supplier : $this->purchasing;
        $url = route($role.'.'.$resource.'.show', $record);
        $counter = $this->watchPreferences();
        $system = $this->actingAs($user)->get($url)->assertOk();
        $this->assertSame(1, $counter->count, 'System detail preference lookup must not scale with rows.');
        $legacy = $this->text($system->getContent());
        $this->assertTextContains('1234.5678', $legacy);
        $this->assertTextContains($resource === 'quotations' && $role === 'supplier' ? '30 September 2026' : '30 Sep 2026', $legacy);
        if ($resource === 'quotations') {
            $this->assertTextContains('28 Sep 2026, 23:35', $legacy);
        }
        $modelBefore = $record->fresh()->getAttributes();
        $itemBefore = $quote->items()->firstOrFail()->getAttributes();
        $rawMachine = $this->machineValues($system->getContent());
        $modal = $this->elementHtml($system->getContent(), 'generatePoModal');
        if ($resource === 'quotations' && $role === 'purchasing') {
            $this->assertNotSame('', $modal, 'Fixture must exercise the Generate PO modal.');
        }
        $this->preferences($user, 'dmy', 'indonesian');
        $this->app->forgetScopedInstances(); // Simulate a fresh HTTP request after changing the saved preference.
        $counter->count = 0;
        $regional = $this->get($url)->assertOk();
        $display = $this->text($regional->getContent());
        $this->assertTextContains('30/09/2026', $display);
        $this->assertTextContains('1.234,5678', $display, 'Four-digit unit-price precision remains authoritative.');
        $this->assertTextContains('1.234,57', $display, 'Max-decimal weight preserves rounding and trimmed precision.');
        $this->assertSame(1, $counter->count, 'Regional detail preference lookup must not scale with rows.');
        if ($resource === 'quotations') {
            $this->assertTextContains('29/09/2026, 06:35 WIB', $display);
            $this->assertTextContains('28/10/2026', $display);
            $this->assertSame('2026-10-28', $quote->fresh()->validity_period->toDateString());
            $this->assertSame($modal, $this->elementHtml($regional->getContent(), 'generatePoModal'));
        } elseif ($role === 'supplier') {
            $this->assertTextContains('29/09/2026 WIB', $display, 'Created instant crosses midnight; ETA is still the source calendar day.');
            $this->assertTextContains('02/10/2026', $display);
        } else {
            $this->assertSame($this->eventTimes($system->getContent()), $this->eventTimes($regional->getContent()), 'Excluded PO event timestamps remain unchanged.');
        }
        $this->assertSame($rawMachine, $this->machineValues($regional->getContent()));
        $this->assertSame($modelBefore, $record->fresh()->getAttributes());
        $this->assertSame($itemBefore, $quote->items()->firstOrFail()->getAttributes());
    }

    public function test_single_and_multirow_supplier_po_requests_resolve_preferences_once_and_preserve_progress_input(): void
    {
        $quote = $this->quotation(1);
        $po = $this->po($quote);
        $item = $quote->items()->firstOrFail();
        $award = PrItemAward::create(['pr_id' => $quote->pr_id, 'pr_item_id' => $item->pr_item_id, 'quotation_id' => $quote->id, 'quotation_item_id' => $item->id, 'supplier_id' => $this->supplier->id, 'purchase_order_id' => $po->id, 'awarded_by' => $this->purchasing->id, 'awarded_at' => '2026-09-28 12:00:00']);
        PoItemProgressUpdate::create(['pr_item_award_id' => $award->id, 'status' => 'order_confirmed', 'supplier_controlled_qty_snapshot' => 2, 'estimated_ready_date' => '2026-10-01', 'note' => 'Ready date fixture', 'updated_by' => $this->supplier->id])->forceFill(['created_at' => '2026-09-28 23:35:00'])->save();
        $counter = $this->watchPreferences();
        $system = $this->actingAs($this->supplier)->get(route('supplier.purchase-orders.show', $po))->assertOk();
        $this->assertSame(1, $counter->count);
        $this->assertTextContains('value="2026-10-01"', $system->getContent());
        $this->preferences($this->supplier, 'iso', 'international');
        $this->app->forgetScopedInstances(); // Laravel feature tests reuse one application across multiple requests.
        $counter->count = 0;
        $regional = $this->get(route('supplier.purchase-orders.show', $po))->assertOk();
        $this->assertTextContains('2026-09-30', $this->text($regional->getContent()));
        $this->assertTextContains('2026-10-01', $this->text($regional->getContent()));
        $this->assertTextContains('2026-09-29 06:35 WIB', $this->text($regional->getContent()));
        $this->assertTextContains('value="2026-10-01"', $regional->getContent());
        $this->assertSame($this->machineValues($system->getContent()), $this->machineValues($regional->getContent()));
        $this->assertSame(1, $counter->count);
    }

    public function test_detail_display_does_not_modify_exports_calculations_or_supplier_isolation(): void
    {
        $quote = $this->quotation(2);
        $po = $this->po($quote);
        $poRows = (new PurchaseOrderDetailExport($po->id))->collection()->all();
        $quoteRows = (new QuotationDetailExport($quote->id))->collection()->all();
        $item = $quote->items()->firstOrFail();
        $values = [$item->requested_amount, $item->resolved_amount, $quote->isExpired(), $po->is_overdue, $po->resolved_total_idr];
        $this->preferences($this->supplier, 'iso', 'international');
        $poResponse = $this->actingAs($this->supplier)->get(route('supplier.purchase-orders.show', $po))->assertOk();
        $this->assertTextContains('1,234.5678', $this->text($poResponse->getContent()));
        $quotationResponse = $this->get(route('supplier.quotations.show', $quote))->assertOk();
        $this->assertTextContains('1,234.5678', $this->text($quotationResponse->getContent()));
        $this->assertSame($poRows, (new PurchaseOrderDetailExport($po->id))->collection()->all());
        $this->assertSame($quoteRows, (new QuotationDetailExport($quote->id))->collection()->all());
        $item->refresh();
        $this->assertSame($values, [$item->requested_amount, $item->resolved_amount, $quote->isExpired(), $po->is_overdue, $po->resolved_total_idr]);
        $other = User::factory()->create(['role' => 'supplier']);
        $this->actingAs($other)->get(route('supplier.purchase-orders.show', $po))->assertForbidden();
        $this->get(route('supplier.quotations.show', $quote))->assertForbidden();
        $local = User::factory()->create(['role' => 'supplier']);
        $local->supplierScopes()->delete();
        $local->supplierScopes()->create(['scope' => 'local']);
        $this->actingAs($local)->get(route('supplier.purchase-orders.show', $po))->assertForbidden();
        $this->get(route('supplier.quotations.show', $quote))->assertForbidden();
    }

    private function quotation(int $count): Quotation
    {
        $pr = PurchaseRequisition::create(['period_id' => $this->period->id, 'created_by' => $this->purchasing->id, 'pr_number' => 'REQ/09/2026/811', 'status' => 'bidding']);
        $pr->forceFill(['created_at' => '2026-09-28 23:35:00'])->save();
        $quote = Quotation::create(['pr_id' => $pr->id, 'supplier_id' => $this->supplier->id, 'exchange_rate_id' => $this->rate->id, 'currency' => 'USD', 'status' => 'accepted', 'submitted_at' => '2026-09-28 23:35:00', 'estimated_delivery' => '2026-09-30', 'validity_period' => '2026-10-28']);
        for ($i = 0; $i < $count; $i++) {
            $item = $pr->items()->create(['hs_code' => '7209.16.00', 'material_name' => 'Regional Detail Plate '.$i, 'quantity' => 2, 'shape' => 'Flat', 'thickness' => 2.5, 'width' => 1000, 'length' => 2000, 'weight_needed' => 1234.5678]);
            $quote->items()->create(['pr_item_id' => $item->id, 'is_available' => true, 'price_per_kg' => '1234.5678', 'amount' => '1250000.5', 'available_qty' => 2, 'offered_weight_per_unit' => '1234.5678']);
        }

        return $quote;
    }

    private function po(Quotation $quote): PurchaseOrder
    {
        $po = PurchaseOrder::create(['supplier_id' => $this->supplier->id, 'currency' => 'USD', 'exchange_rate_id' => $this->rate->id, 'po_number' => 'PO/09/2026/811', 'status' => 'active', 'created_by' => $this->purchasing->id, 'estimated_arrival' => '2026-09-30', 'actual_arrival' => '2026-10-02']);
        $po->forceFill(['created_at' => '2026-09-28 23:35:00'])->save();
        $po->quotations()->attach($quote->id);

        return $po;
    }

    private function preferences(User $user, string $date, string $number): void
    {
        $defaults = config('user_preferences.defaults');
        unset($defaults['sidebar_revision']);
        $user->preference()->create([...$defaults, 'timezone' => 'Asia/Jakarta', 'date_format' => $date, 'time_format' => '24h', 'number_format' => $number]);
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

    private function assertTextContains(string $expected, string $actual, string $message = ''): void
    {
        $this->assertTrue(str_contains($actual, $expected), $message ?: 'Missing expected display: '.$expected);
    }

    private function text(string $html): string
    {
        return preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5));
    }

    private function dom(string $html): DOMDocument
    {
        $doc = new DOMDocument;
        $errors = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($errors);

        return $doc;
    }

    private function machineValues(string $html): array
    {
        $values = [];
        foreach ((new DOMXPath($this->dom($html)))->query('//*[@href or @action or @datetime or @value or @data-offer-amount or @data-history-url or @data-award-id or @data-current-rank]') as $node) {
            $attributes = [];
            foreach ($node->attributes as $attribute) {
                if (in_array($attribute->name, ['href', 'action', 'datetime', 'value', 'data-offer-amount', 'data-history-url', 'data-award-id', 'data-current-rank'], true)) {
                    $attributes[$attribute->name] = $attribute->value;
                }
            }
            $values[] = $attributes;
        }

        return $values;
    }

    private function elementHtml(string $html, string $id): string
    {
        $doc = $this->dom($html);
        $node = $doc->getElementById($id);

        return $node ? $doc->saveHTML($node) : '';
    }

    private function eventTimes(string $html): array
    {
        $doc = $this->dom($html);
        $times = [];
        foreach ((new DOMXPath($doc))->query('//time[@datetime]') as $node) {
            $times[] = $doc->saveHTML($node);
        }

        return $times;
    }
}
