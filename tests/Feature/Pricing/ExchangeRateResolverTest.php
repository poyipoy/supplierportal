<?php

namespace Tests\Feature\Pricing;

use App\Models\ExchangeRate;
use App\Models\User;
use App\Services\Pricing\ExchangeRateResolver;
use App\Support\BusinessTime;
use App\Support\Pricing\EvaluationContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Analytical exchange-rate layer (Q4, Q9; Gate 1 section 12.1). The benchmark
 * rate is the latest row effective on or before the evaluation date; document
 * snapshots and ExchangeRate::latestRate() are unaffected.
 */
class ExchangeRateResolverTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'purchasing']);
    }

    public function test_future_dated_rates_are_never_selected(): void
    {
        $expected = $this->rate('USD', '16000', '2026-10-11');
        $this->rate('USD', '99999', '2026-10-13');

        $result = $this->resolver()->rateAsOf('USD', '2026-10-12');

        $this->assertSame($expected->id, $result?->exchangeRateId);
        $this->assertSame('16000.0000', $result->rate);
        $this->assertSame('2026-10-11', $result->validFrom);
    }

    public function test_equal_effective_dates_use_the_latest_id(): void
    {
        $this->rate('USD', '16000', '2026-10-12');
        $later = $this->rate('USD', '16100', '2026-10-12');

        $this->assertSame($later->id, $this->resolver()->rateAsOf('usd', '2026-10-12')?->exchangeRateId);
    }

    public function test_idr_is_the_identity_rate_without_a_query(): void
    {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $result = $this->resolver()->rateAsOf('IDR', '2026-10-12');

        $this->assertSame('1.0000', $result?->rate);
        $this->assertNull($result->exchangeRateId);
        $this->assertNull($result->validFrom);
        $this->assertSame(0, $queries);
    }

    public function test_missing_rate_is_unavailable_never_one_or_zero(): void
    {
        $this->rate('CNY', '2200', '2100-01-01');

        $this->assertNull($this->resolver()->rateAsOf('CNY', '2026-10-12'));
        $this->assertNull($this->resolver()->rateAsOf('JPY', '2026-10-12'));
        $this->assertNull($this->resolver()->convert('100', 'JPY', 'IDR', '2026-10-12'));
        $this->assertNull($this->resolver()->convert('100', 'IDR', 'JPY', '2026-10-12'));
    }

    public function test_non_positive_rates_are_ignored(): void
    {
        $valid = $this->rate('USD', '16000', '2026-10-10');
        DB::table('exchange_rates')->insert([
            'currency' => 'USD', 'rate_to_idr' => '0', 'valid_from' => '2026-10-11',
            'created_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame($valid->id, $this->resolver()->rateAsOf('USD', '2026-10-12')?->exchangeRateId);
    }

    public function test_conversion_uses_bcmath_and_rounds_half_up_once_at_four_decimals(): void
    {
        $this->rate('USD', '16000', '2026-10-01');
        $this->rate('JPY', '105', '2026-10-01');
        $resolver = $this->resolver();

        // 100 x 16000 / 105 = 15238.095238...
        $this->assertSame('15238.0952', $resolver->convert('100', 'USD', 'JPY', '2026-10-12'));
        $this->assertSame('1600000.0000', $resolver->convert('100', 'USD', 'IDR', '2026-10-12'));
        $this->assertSame('1.0000', $resolver->convert('16000', 'IDR', 'USD', '2026-10-12'));
        $this->assertSame('123.4567', $resolver->convert('123.45665', 'IDR', 'IDR', '2026-10-12'));
        $this->assertSame('0.0001', $resolver->convert('0.00005', 'IDR', 'IDR', '2026-10-12'));
    }

    public function test_rates_are_memoized_within_the_resolver_instance(): void
    {
        $this->rate('USD', '16000', '2026-10-01');
        $resolver = $this->resolver();
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $resolver->rateAsOf('USD', '2026-10-12');
        $resolver->rateAsOf('USD', '2026-10-12');
        $resolver->convert('1', 'USD', 'IDR', '2026-10-12');

        $this->assertSame(1, $queries);
    }

    public function test_unsupported_currency_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->resolver()->rateAsOf('EUR', '2026-10-12');
    }

    public function test_carbon_dates_are_read_on_the_business_calendar(): void
    {
        $this->rate('USD', '16000', '2026-10-12');
        $tomorrow = $this->rate('USD', '16100', '2026-10-13');

        // 18:00 UTC on 12 Oct is 01:00 on 13 Oct in Asia/Jakarta.
        $instant = CarbonImmutable::parse('2026-10-12 18:00:00', 'UTC');

        $this->assertSame($tomorrow->id, $this->resolver()->rateAsOf('USD', $instant)?->exchangeRateId);
    }

    public function test_evaluation_context_resolves_one_business_date_per_request(): void
    {
        // 23:59:30 WIB on 12 Oct.
        $this->travelTo(CarbonImmutable::parse('2026-10-12 16:59:30', 'UTC'));
        $first = app(EvaluationContext::class);
        $this->assertSame('2026-10-12', $first->dateString());
        $this->assertSame(BusinessTime::today()->toDateString(), $first->dateString());

        // The request crosses midnight WIB: the same context keeps its date.
        $this->travel(1)->minutes();
        $this->assertSame('2026-10-12', app(EvaluationContext::class)->dateString());
        $this->assertSame($first, app(EvaluationContext::class));

        // The next request (scoped instances reset) evaluates the new business day.
        $this->app->forgetScopedInstances();
        $this->assertSame('2026-10-13', app(EvaluationContext::class)->dateString());
        $this->assertSame(BusinessTime::today()->toDateString(), app(EvaluationContext::class)->dateString());
    }

    public function test_resolver_rates_for_the_evaluation_date(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-12 16:59:30', 'UTC'));
        $today = $this->rate('USD', '16000', '2026-10-12');
        $this->rate('USD', '16100', '2026-10-13');
        $context = app(EvaluationContext::class);
        $this->travel(1)->minutes();

        $this->assertSame($today->id, app(ExchangeRateResolver::class)->rateAsOf('USD', $context->date)?->exchangeRateId);
        $this->assertSame(app(ExchangeRateResolver::class), app(ExchangeRateResolver::class));
    }

    private function resolver(): ExchangeRateResolver
    {
        return new ExchangeRateResolver;
    }

    private function rate(string $currency, string $amount, string $date): ExchangeRate
    {
        return ExchangeRate::create([
            'currency' => $currency, 'rate_to_idr' => $amount,
            'valid_from' => $date, 'created_by' => $this->owner->id,
        ]);
    }
}
