<?php

namespace Tests\Feature\Pricing;

use App\Models\AnnualExchangeRate;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\User;
use App\Services\Pricing\AnnualExchangeRateResolver;
use App\Services\Pricing\PurchaseHistoryPriceService;
use App\Services\Pricing\QuotationHistoryService;
use App\Support\HashidCodec;
use App\Support\Pricing\EvaluationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Procurement\QuotationHistoryFixtures;
use Tests\TestCase;

class AnnualExchangeRateTest extends TestCase
{
    use QuotationHistoryFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpQuotationHistory();
    }

    public function test_admin_saves_complete_revisions_and_stale_forms_cannot_overwrite_them(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $payload = ['year' => 2024, 'expected_revision' => 0, 'rates' => ['USD' => '17200', 'JPY' => '105.2', 'CNY' => '2250']];
        $this->actingAs($admin)->post(route('admin.exchange-rates.annual.store'), $payload)->assertRedirect();
        $this->assertDatabaseCount('annual_exchange_rates', 3);
        $this->assertDatabaseHas('annual_exchange_rates', ['year' => 2024, 'revision' => 1, 'currency' => 'JPY', 'rate_to_idr' => '105.2000']);
        $payload['rates']['USD'] = '17300';
        $this->post(route('admin.exchange-rates.annual.store'), $payload)->assertSessionHasErrors('year', null, 'annualRates');
        $this->assertDatabaseCount('annual_exchange_rates', 3);
        $payload['expected_revision'] = 1;
        $this->post(route('admin.exchange-rates.annual.store'), $payload)->assertRedirect();
        $this->assertDatabaseCount('annual_exchange_rates', 6);
        $this->assertDatabaseHas('annual_exchange_rates', ['revision' => 1, 'currency' => 'USD', 'rate_to_idr' => 17200]);
        $this->assertSame('17300.0000', (new AnnualExchangeRateResolver)->rate('USD', 2024));
        $this->get(route('admin.exchange-rates.index', ['annual_year' => 2024]))->assertOk()
            ->assertViewHas('annualRates', fn ($rates) => $rates->get(2024)->firstWhere('currency', 'USD')->rate_to_idr === '17300.0000');
        $this->get(route('admin.dashboard'))->assertOk()->assertSee(__('annual_rates.title'));
    }

    public function test_validation_rejects_partial_invalid_or_tampered_sets_without_partial_writes(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach ([['USD' => '0', 'JPY' => '-1', 'CNY' => '1.23456'], ['USD' => '17200'], ['USD' => '17200', 'JPY' => '105.2', 'CNY' => '2250', 'IDR' => '2']] as $rates) {
            $this->post(route('admin.exchange-rates.annual.store'), ['year' => 2024, 'expected_revision' => 0, 'rates' => $rates])
                ->assertSessionHasErrors(null, null, 'annualRates');
            $this->assertDatabaseCount('annual_exchange_rates', 0);
        }
    }

    public function test_only_admin_can_write_annual_rates(): void
    {
        $payload = ['year' => 2024, 'expected_revision' => 0, 'rates' => ['USD' => 17200, 'JPY' => 105.2, 'CNY' => 2250]];
        $this->post(route('admin.exchange-rates.annual.store'), $payload)->assertRedirect(route('login'));
        foreach (['purchasing', 'supplier', 'finance', 'ga', 'qc'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->post(route('admin.exchange-rates.annual.store'), $payload)->assertForbidden();
        }
        $this->assertDatabaseCount('annual_exchange_rates', 0);
    }

    public function test_year_pagination_counts_years_instead_of_currency_rows_or_revisions(): void
    {
        foreach (range(2020, 2025) as $year) {
            $this->annual($year, '17200');
        }
        $this->annual(2024, '17600', revision: 2);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('admin.exchange-rates.index', ['tab' => 'annual']))
            ->assertOk()->assertViewHas('annualYears', fn ($years) => $years->total() === 6 && $years->lastPage() === 2)
            ->assertViewHas('annualMatrixYears', [2021, 2022, 2023, 2024, 2025]);
        $this->get(route('admin.exchange-rates.index', ['annual_page' => 2]))->assertOk()
            ->assertViewHas('annualMatrixYears', [2020]);
        $this->get(route('admin.exchange-rates.index', ['annual_year' => 2024]))->assertOk()
            ->assertViewHas('annualYears', fn ($years) => $years->total() === 1);
    }

    public function test_a_failure_on_the_last_currency_rolls_back_the_whole_revision(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))->withoutExceptionHandling();
        $event = 'eloquent.creating: '.AnnualExchangeRate::class;
        Event::listen($event, function (AnnualExchangeRate $rate): void {
            if ($rate->currency === 'CNY') {
                throw new \RuntimeException('Simulated annual write failure');
            }
        });
        try {
            $this->post(route('admin.exchange-rates.annual.store'), ['year' => 2024, 'expected_revision' => 0,
                'rates' => ['USD' => 17200, 'JPY' => 105.2, 'CNY' => 2250]]);
            $this->fail('The final currency write should fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated annual write failure', $exception->getMessage());
        } finally {
            Event::forget($event);
        }
        $this->assertDatabaseCount('annual_exchange_rates', 0);
    }

    public function test_previous_rate_rows_cannot_be_updated_or_deleted(): void
    {
        $this->annual(2024, '17200');
        $rate = AnnualExchangeRate::where('currency', 'USD')->sole();
        foreach (['update', 'delete'] as $operation) {
            try {
                $operation === 'update' ? $rate->update(['rate_to_idr' => 1]) : $rate->delete();
                $this->fail('Annual rate history must be immutable.');
            } catch (\LogicException $exception) {
                $this->assertSame('Annual exchange rates are append-only.', $exception->getMessage());
            }
        }
        $this->assertDatabaseCount('annual_exchange_rates', 3);
        $this->assertSame('17200.0000', $rate->fresh()->rate_to_idr);
    }

    public function test_resolver_handles_decimal_cross_currency_missing_rates_and_business_year(): void
    {
        $this->annual(2024, '17200', '105.2');
        $rates = new AnnualExchangeRateResolver;
        $this->assertSame('172000.0000', $rates->convert('10', 'USD', 'IDR', 2024));
        $this->assertSame('1052.0000', $rates->convert('10', 'JPY', 'IDR', 2024));
        $this->assertSame('1634.9810', $rates->convert('10', 'USD', 'JPY', 2024));
        $this->assertNull($rates->convert('10', 'USD', 'IDR', 2025));
        $this->assertSame('10.0000', $rates->convert('10', 'IDR', 'IDR', 1999));
        $this->assertSame(2025, $rates->year('2024-12-31 17:00:00'));
        $this->assertSame(2024, $rates->year('2024-12-31 16:59:59'));
    }

    public function test_historical_pr_table_drawer_and_charts_use_each_transaction_year_even_for_usd(): void
    {
        [$pr, $supplier, $previous, $current, $po] = $this->scenario();
        $snapshot = $po->exchange_rate_id;
        $service = app(QuotationHistoryService::class);
        $history = $service->forPr($pr, null, 'IDR');
        $row = $history['rows'][0];
        $this->assertSame('172000.0000', $row['comparison']['previous']);
        $this->assertSame('180000.0000', $row['comparison']['current']);
        $this->assertSame('8000.0000', $row['diff']);
        $this->assertSame('4.6512', $row['diff_pct']);
        $charts = $service->charts($history, $pr->items[0]);
        $this->assertSame(['180000.0000'], $charts['current_previous']['series']['current']);
        $this->assertSame(['172000.0000'], $charts['current_previous']['series']['previous']);
        $this->assertSame('172000.0000', $service->drawer($history, $pr->items[0], $supplier->id)['summary']['lowest']['price_per_kg']);
        $url = route('purchasing.comparison.historical', ['pr_id' => $pr->getRouteKey(), 'currency' => 'IDR']);
        $this->actingAs($this->purchasingUser)->getJson($url.'&view=json')->assertOk()
            ->assertJsonPath('quotationHistory.rows.0.diff', '8000.0000')->assertJsonPath('evaluation.rate_basis', 'annual');
        $this->get($url)->assertOk()->assertSee(__('annual_rates.basis'))->assertSee('USD 10');
        $this->getJson($url.'&view=json&history=1&pr_item='.HashidCodec::encode($pr->items[0]->id).'&supplier_id='.$supplier->hash)
            ->assertOk()->assertJsonPath('records.0.diff_pct', '4.6512')->assertJsonPath('summary.count', 1);
        $this->assertSame($snapshot, $po->fresh()->exchange_rate_id);
        $this->assertSame('17700.0000', $previous->exchange_rate->rate_to_idr);
        $this->assertSame($snapshot, $current->fresh()->exchange_rate_id);
    }

    public function test_weighted_totals_group_by_year_before_conversion_and_default_service_stays_effective(): void
    {
        [$pr, $supplier, $previous, $current, $oldPo] = $this->scenario();
        $newPo = $this->purchase($current);
        DB::table('quotation_items')->where('quotation_id', $current->id)->update(['available_qty' => 30]);
        $service = app(PurchaseHistoryPriceService::class);
        $filter = ['material_name' => 'SKD11'];
        $annual = $service->summarize($filter, 'IDR', EvaluationContext::current(), 'none', true);
        $this->assertSame('178000.0000', $annual['total']['weighted_average']);
        $this->assertSame('177000.0000', $service->summarize($filter, 'IDR', EvaluationContext::current())['total']['weighted_average']);
        $lines = $service->lines($filter, 'IDR', EvaluationContext::current(), 1, 50, true)['rows'];
        $this->assertSame([2024, 2026], array_column($lines, 'rate_year'));
        $this->assertSame(['172000.0000', '180000.0000'], array_column($lines, 'converted_price_per_kg'));
        $this->actingAs($this->purchasingUser)->getJson(route('purchasing.comparison.historical', ['view' => 'json', 'material_name' => 'SKD11', 'shape' => 'Flat']))
            ->assertOk()->assertJsonPath('summary.weighted_average', '178000.0000');
        $this->get(route('purchasing.comparison.inter-supplier', ['pr_id' => $pr->hash]))->assertOk()
            ->assertViewHas('evaluation', fn (array $evaluation) => ! isset($evaluation['rate_basis']) && $evaluation['rates']['USD']['rate'] === '17700.0000');
        $this->getJson(route('purchasing.comparison.vs-best.data'))->assertOk();
    }

    public function test_missing_annual_rate_never_falls_back_to_a_document_snapshot(): void
    {
        [$pr] = $this->scenario(false);
        $service = app(QuotationHistoryService::class);
        $history = $service->forPr($pr, null, 'IDR');
        $this->assertSame('fx_unavailable', $history['rows'][0]['status']);
        $this->assertNull($history['rows'][0]['comparison']['previous']);
        $this->assertNull($history['rows'][0]['diff_pct']);
        $chart = $service->charts($history, $pr->items[0]);
        $this->assertSame([null], $chart['current_previous']['series']['previous']);
        $this->actingAs($this->purchasingUser)->get(route('purchasing.comparison.historical', ['pr_id' => $pr->hash]))
            ->assertOk()->assertSee(__('annual_rates.unavailable', ['currency' => 'USD', 'year' => 2024]));
    }

    public function test_correction_changes_the_next_request_without_rewriting_documents(): void
    {
        [$pr, , , $current, $po] = $this->scenario();
        $snapshot = $po->exchange_rate_id;
        $resolver = app(AnnualExchangeRateResolver::class);
        $this->assertSame('180000.0000', $resolver->convert('10', 'USD', 'IDR', 2026));
        $this->annual(2026, '18500', revision: 2);
        $this->assertSame('180000.0000', $resolver->convert('10', 'USD', 'IDR', 2026), 'One request keeps one rate catalog.');
        app()->forgetScopedInstances();
        $row = app(QuotationHistoryService::class)->forPr($pr, null, 'IDR')['rows'][0];
        $this->assertSame('185000.0000', $row['comparison']['current']);
        $this->assertSame('13000.0000', $row['diff']);
        $this->assertSame($snapshot, $current->fresh()->exchange_rate_id);
        $this->assertSame($snapshot, $po->fresh()->exchange_rate_id);
    }

    public function test_native_analysis_does_not_label_an_identity_conversion_as_an_fx_effect(): void
    {
        [$pr, $supplier] = $this->scenario();
        $service = app(QuotationHistoryService::class);
        $history = $service->forPr($pr, null, 'USD');
        $this->assertSame('0.0000', $history['rows'][0]['diff_pct']);
        $this->assertFalse($service->drawer($history, $pr->items[0], $supplier->id)['records'][0]['diff_converted']);
    }

    public function test_small_positive_conversions_keep_precision_until_percentages_are_calculated(): void
    {
        [$pr, $supplier, $previous, $current] = $this->scenario();
        $this->annual(2024, '0.0001', '99999999999', 2);
        $this->annual(2026, '0.0002', '99999999999', 2);
        DB::table('quotation_items')->whereIn('quotation_id', [$previous->id, $current->id])->update(['price_per_kg' => '0.0001']);
        $service = app(QuotationHistoryService::class);
        $history = $service->forPr($pr, null, 'JPY');
        $this->assertSame('0.0000', $history['rows'][0]['comparison']['previous']);
        $this->assertSame('100.0000', $history['rows'][0]['diff_pct']);
        $this->assertSame('100.0000', $service->drawer($history, $pr->items[0], $supplier->id)['records'][0]['diff_pct']);
        $this->actingAs($this->purchasingUser)->getJson(route('purchasing.comparison.historical', ['pr_id' => $pr->hash, 'currency' => 'JPY', 'view' => 'json']))
            ->assertOk()->assertJsonPath('quotationHistory.rows.0.direction', 1);
    }

    public function test_po_year_is_used_instead_of_quote_year_and_idr_does_not_need_annual_rows(): void
    {
        [$pr, , , , $po] = $this->scenario(false);
        DB::table('purchase_orders')->where('id', $po->id)->update(['created_at' => '2024-12-31 17:00:00']);
        $this->annual(2025, '17600');
        $history = app(QuotationHistoryService::class)->forPr($pr, null, 'IDR');
        $this->assertSame(2025, $history['rows'][0]['previous']['rate_year']);
        $this->assertSame('176000.0000', $history['rows'][0]['comparison']['previous']);
        DB::table('quotations')->update(['currency' => 'IDR', 'exchange_rate_id' => null]);
        $row = app(QuotationHistoryService::class)->forPr($pr, null, 'IDR')['rows'][0];
        $this->assertSame('10.0000', $row['comparison']['previous']);
        $this->assertSame('0.0000', $row['diff']);
    }

    private function annual(int $year, string $usd, string $jpy = '103', int $revision = 1): void
    {
        foreach (['USD' => $usd, 'JPY' => $jpy, 'CNY' => '2700'] as $currency => $rate) {
            AnnualExchangeRate::create(['year' => $year, 'revision' => $revision, 'currency' => $currency, 'rate_to_idr' => $rate, 'created_by' => $this->purchasingUser->id]);
        }
    }

    private function scenario(bool $oldRates = true): array
    {
        if ($oldRates) {
            $this->annual(2024, '17200', '105.2');
        }
        $this->annual(2026, '18000');
        $supplier = $this->supplierNamed('Annual rates supplier');
        $previousPr = $this->prAt('2024-06-01', [self::skd()]);
        $previous = $this->quote($previousPr, $supplier, '2024-06-10', 'USD', 'USD', [10], Quotation::STATUS_ACCEPTED);
        $po = PurchaseOrder::whereHas('quotations', fn ($query) => $query->where('quotations.id', $previous->id))->sole();
        DB::table('purchase_orders')->where('id', $po->id)->update(['created_at' => '2024-06-20 02:00:00']);
        $pr = $this->prAt('2026-10-01', [self::skd()]);
        $current = $this->quote($pr, $supplier, '2026-10-15', 'USD', 'USD', [10]);

        return [$pr, $supplier, $previous, $current, $po];
    }
}
