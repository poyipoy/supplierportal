<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\QcInspection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RegionalDashboardRenderingTest extends TestCase
{
    use RefreshDatabase;

    public static function dashboards(): array
    {
        $cases = [
            'Admin' => ['admin', 'admin', 'admin.dashboard', 'notifications', 'rates', ['totalUsersActive' => 1250]],
            'Purchasing' => ['purchasing', 'purchasing', 'purchasing.dashboard', 'recent_requisitions', 'exceptions', ['prAktif' => 1250]],
            'Finance' => ['finance', 'finance', 'finance.dashboard', 'batches', 'forecast', ['kpis' => ['waiting_physical' => 1250]]],
            'Accounting' => ['accounting', 'accounting', 'accounting.dashboard', 'lifecycle', 'invoices', ['counts' => ['SUBMITTED' => 1250]]],
            'QC' => ['qc', 'qc', 'qc.dashboard', 'charts', 'queue', ['totalInspections' => 1250]],
            'GA' => ['ga', 'ga', 'ga.dashboard', 'claims', 'statuses', ['kpis' => ['submitted' => 1250]]],
            'Supplier Import' => ['supplier', 'supplier.import', 'supplier.dashboard', 'updates', 'orders', ['periodeAktif' => 1250]],
            'Supplier Local' => ['supplier', 'supplier.local', 'local-supplier.dashboard', 'company', 'invoices', ['counts' => ['SUBMITTED' => 1250]]],
        ];
        $matrix = [];
        foreach ($cases as $label => $case) {
            $matrix[$label.' System'] = [...$case, 'system', $case[0] === 'admin' ? '1,250' : '1250'];
            $matrix[$label.' Indonesian'] = [...$case, 'indonesian', '1.250'];
        }

        return $matrix;
    }

    public static function regionalModes(): array
    {
        return ['System' => ['system'], 'Regional' => ['indonesian']];
    }

    #[DataProvider('dashboards')]
    public function test_regional_counts_keep_dashboard_contracts_with_one_preference_query(string $role, string $audience, string $route, string $hidden, string $required, array $fixture, string $mode, string $expectedCount): void
    {
        $user = $this->regionalUser($role, $audience, $mode);
        $user->preference->forceFill(['dashboard_preferences' => [$audience => ['hidden' => [$audience.'.'.$hidden, $audience.'.'.$required], 'order' => []]]])->save();
        View::composer($route, function ($view) use ($fixture): void {
            foreach ($fixture as $key => $value) {
                $view->with($key, $key === 'counts' ? collect($value) : $value);
            }
        });
        $lookups = 0;
        DB::listen(function ($query) use (&$lookups): void {
            if (str_contains(strtolower($query->sql), 'user_preferences')) {
                $lookups++;
            }
        });
        $response = $this->actingAs($user)->get(route($route))->assertOk();
        $this->assertSame(1, preg_match('/>\s*'.preg_quote($expectedCount, '/').'\s*</', $response->getContent()), 'Visible metric for '.$audience.' in '.$mode.' mode');
        $response->assertDontSee('data-dashboard-widget="'.$audience.'.'.$hidden.'"', false);
        $response->assertSee('data-dashboard-widget="'.$audience.'.'.$required.'"', false);
        $this->assertSame(1, $lookups);
    }

    #[DataProvider('regionalModes')]
    public function test_admin_calendar_date_and_rate_precision_follow_display_preferences(string $mode): void
    {
        $user = $this->regionalUser('admin', 'admin', $mode);
        $rate = new ExchangeRate(['currency' => 'USD', 'valid_from' => '2026-09-28', 'rate_to_idr' => '1250000.5']);
        View::composer('admin.dashboard', fn ($view) => $view->with('latestRates', ['USD' => $rate]));
        $response = $this->actingAs($user)->get(route('admin.dashboard'))->assertOk();
        $response->assertSee($mode === 'system' ? '28 Sep 2026' : '28/09/2026')->assertSee('Rp 1.250.000,50');
        $this->assertSame('2026-09-28', $rate->valid_from->toDateString());
        $this->assertSame('1250000.5000', $rate->rate_to_idr);
    }

    #[DataProvider('regionalModes')]
    public function test_qc_instant_crosses_midnight_without_mutating_model(string $mode): void
    {
        $user = $this->regionalUser('qc', 'qc', $mode);
        $inspection = new QcInspection(['status' => 'ok', 'inspected_at' => '2026-09-28 23:35:00']);
        $inspection->id = 999;
        $inspection->setRelation('purchaseOrder', null)->setRelation('inspector', $user);
        View::composer('qc.dashboard', fn ($view) => $view->with('recentInspections', collect([$inspection])));
        $response = $this->actingAs($user)->get(route('qc.dashboard'))->assertOk();
        $response->assertSee($mode === 'system' ? '28 Sep 2026, 23:35' : '29/09/2026, 6:35 AM WIB');
        $this->assertSame('2026-09-28 23:35:00', $inspection->inspected_at->format('Y-m-d H:i:s'));
    }

    public function test_finance_visible_range_changes_but_raw_dates_amounts_and_filter_keys_do_not(): void
    {
        $user = $this->regionalUser('finance', 'finance');
        $weekly = [['start' => '2026-09-28', 'end' => '2026-09-30', 'week' => 'Week 5', 'label' => 'Week 5', 'count' => 0, 'period_amount' => 1250000.5, 'cumulative_amount' => 1250000.5, 'period_amount_formatted' => 'Rp 1.250.001', 'cumulative_amount_formatted' => 'Rp 1.250.001']];
        View::composer('finance.dashboard', fn ($view) => $view->with(['weeklyForecast' => $weekly, 'selectedMonth' => '2026-09', 'availableMonths' => [['key' => '2026-09', 'label' => 'September 2026', 'is_current' => true]]]));
        $response = $this->actingAs($user)->get(route('finance.dashboard'))->assertOk();
        $response->assertSee(__('finance.review.date_range', ['start' => '28/09/2026', 'end' => '30/09/2026'], 'en'));
        $response->assertSee("selectedMonth: '2026-09'", false);
        $response->assertSee('"start":"2026-09-28"', false);
        $response->assertSee('"period_amount":1250000.5', false);
        $response->assertSee(':key="row.start + \'_\' + mode"', false);
        $response->assertSee('?month='.'$'.'{month}', false);
    }

    #[DataProvider('regionalModes')]
    public function test_admin_missing_currency_rates_keep_the_placeholder(string $mode): void
    {
        $user = $this->regionalUser('admin', 'admin', $mode);
        View::composer('admin.dashboard', fn ($view) => $view->with('latestRates', []));
        $this->actingAs($user)->get(route('admin.dashboard'))->assertOk()
            ->assertSee('Rate Required')->assertSee('<td>-</td>', false);
    }

    private function regionalUser(string $role, string $audience, string $mode = 'indonesian'): User
    {
        $user = User::factory()->create(['role' => $role]);
        if ($role === 'supplier') {
            $user->supplierScopes()->delete();
            DB::table('supplier_scopes')->insert(['supplier_id' => $user->id, 'scope' => str_ends_with($audience, '.local') ? 'local' : 'import', 'created_at' => now(), 'updated_at' => now()]);
        }
        $user->preference()->create(array_diff_key(config('user_preferences.defaults'), ['sidebar_revision' => true]))
            ->forceFill($mode === 'system' ? ['timezone' => 'system', 'date_format' => 'system', 'time_format' => 'system', 'number_format' => 'system'] : ['timezone' => 'Asia/Jakarta', 'date_format' => 'dmy', 'time_format' => '12h', 'number_format' => 'indonesian'])->save();

        return $user;
    }
}
