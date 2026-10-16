<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExchangeRateEffectiveDateTest extends TestCase
{
    use RefreshDatabase;

    public function test_latest_rate_uses_only_effective_rows_and_deterministic_id_tie_break(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 12)->setTime(10, 0));
        $owner = User::factory()->create(['role' => 'purchasing']);
        $this->rate($owner, 'USD', '16000', '2026-10-11');
        $expected = $this->rate($owner, 'USD', '16100', '2026-10-12');
        $this->rate($owner, 'USD', '99999', '2026-10-13');

        $this->assertSame($expected->id, ExchangeRate::latestRate('usd')->id);
        $replacement = $this->rate($owner, 'USD', '16200', '2026-10-12');
        $this->assertSame($replacement->id, ExchangeRate::latestRate('USD')->id);
    }

    public function test_cache_changes_at_business_midnight_even_before_sixty_minutes_expire(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 12)->setTime(16, 59));
        $owner = User::factory()->create(['role' => 'purchasing']);
        $current = $this->rate($owner, 'JPY', '100', '2026-10-12');
        $tomorrow = $this->rate($owner, 'JPY', '101', '2026-10-13');
        $this->assertSame($current->id, ExchangeRate::latestRate('JPY')->id);

        $this->travel(2)->minutes();

        $this->assertSame($tomorrow->id, ExchangeRate::latestRate('JPY')->id);
    }

    public function test_idr_new_rows_are_one_and_historical_rows_are_not_rewritten(): void
    {
        $owner = User::factory()->create(['role' => 'purchasing']);
        $idr = $this->rate($owner, 'IDR', '25000', '2020-01-01');
        $this->assertSame('1.0000', $idr->fresh()->rate_to_idr);
        $historicalId = DB::table('exchange_rates')->insertGetId([
            'currency' => 'IDR', 'rate_to_idr' => '15000', 'valid_from' => '2021-01-01',
            'created_by' => $owner->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        Cache::flush();

        $this->assertSame($idr->id, ExchangeRate::latestRate('IDR')->id);
        $this->assertSame('15000.0000', ExchangeRate::findOrFail($historicalId)->rate_to_idr);
    }

    public function test_currency_without_an_effective_rate_returns_null(): void
    {
        $owner = User::factory()->create(['role' => 'purchasing']);
        $this->rate($owner, 'CNY', '3000', '2100-01-01');
        $this->assertNull(ExchangeRate::latestRate('CNY'));
    }

    public function test_purchasing_quick_rate_update_stores_the_business_date_across_utc_midnight(): void
    {
        // 18:00 UTC on 12 Oct is already 01:00 on 13 Oct in Asia/Jakarta.
        $this->travelTo(now()->setTimezone('UTC')->setDate(2026, 10, 12)->setTime(18, 0));
        $purchasing = User::factory()->create(['role' => 'purchasing']);

        $this->actingAs($purchasing)
            ->post(route('purchasing.kurs.update'), ['currency' => 'USD', 'rate_to_idr' => '16250'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $rate = ExchangeRate::sole();
        $this->assertSame('2026-10-13', $rate->valid_from->toDateString());
        $this->assertSame('2026-10-13', DB::table('exchange_rates')->where('id', $rate->id)->value('valid_from'));
        $this->assertSame($rate->id, ExchangeRate::latestRate('USD')?->id);
    }

    public function test_admin_quick_rate_update_stores_the_business_date_across_utc_midnight(): void
    {
        $this->travelTo(now()->setTimezone('UTC')->setDate(2026, 10, 12)->setTime(18, 0));
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->post(route('admin.kurs.update'), ['currency' => 'JPY', 'rate_to_idr' => '107.5'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('2026-10-13', DB::table('exchange_rates')->where('currency', 'JPY')->value('valid_from'));
    }

    public function test_quick_rate_update_keeps_its_authorization_and_validation(): void
    {
        $supplier = User::factory()->create(['role' => 'supplier']);
        $purchasing = User::factory()->create(['role' => 'purchasing']);

        $this->actingAs($supplier)->post(route('purchasing.kurs.update'), ['currency' => 'USD', 'rate_to_idr' => '16000'])->assertForbidden();
        $this->actingAs($supplier)->post(route('admin.kurs.update'), ['currency' => 'USD', 'rate_to_idr' => '16000'])->assertForbidden();
        $this->actingAs($purchasing)->post(route('admin.kurs.update'), ['currency' => 'USD', 'rate_to_idr' => '16000'])->assertForbidden();
        $this->actingAs($purchasing)->post(route('purchasing.kurs.update'), ['currency' => 'EUR', 'rate_to_idr' => '0'])
            ->assertSessionHasErrors(['currency', 'rate_to_idr']);

        $this->assertDatabaseCount('exchange_rates', 0);
    }

    private function rate(User $owner, string $currency, string $amount, string $date): ExchangeRate
    {
        return ExchangeRate::create([
            'currency' => $currency, 'rate_to_idr' => $amount,
            'valid_from' => $date, 'created_by' => $owner->id,
        ]);
    }
}
