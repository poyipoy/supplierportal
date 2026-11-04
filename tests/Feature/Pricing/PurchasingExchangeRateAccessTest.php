<?php

namespace Tests\Feature\Pricing;

use App\Models\AnnualExchangeRate;
use App\Models\ExchangeRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchasingExchangeRateAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setDate(2026, 11, 4)->setTime(10, 0));
        $this->purchasing = User::factory()->create([
            'role' => 'purchasing',
            'is_active' => true,
        ]);
    }

    public function test_purchasing_can_access_exchange_rates_index_and_tabs(): void
    {
        $response = $this->actingAs($this->purchasing)
            ->get(route('purchasing.master-data.exchange-rates.index'))
            ->assertOk();

        $response->assertSee(route('purchasing.master-data.exchange-rates.index', ['tab' => 'effective']));
        $response->assertSee(route('purchasing.master-data.exchange-rates.index', ['tab' => 'annual']));
        $response->assertSee(route('purchasing.master-data.exchange-rates.store'));

        $annualResponse = $this->actingAs($this->purchasing)
            ->get(route('purchasing.master-data.exchange-rates.index', ['tab' => 'annual']))
            ->assertOk();

        $annualResponse->assertSee(route('purchasing.master-data.exchange-rates.index', ['tab' => 'annual']));

        // When initiating an annual edit or add-year, the annual store form action is rendered.
        $editResponse = $this->actingAs($this->purchasing)
            ->get(route('purchasing.master-data.exchange-rates.index', [
                'tab' => 'annual',
                'annual_new' => 1,
                'annual_new_year' => 2028,
            ]))
            ->assertOk();

        $editResponse->assertSee(route('purchasing.master-data.exchange-rates.annual.store'));
    }

    public function test_purchasing_sidebar_displays_exchange_rates_under_master_data(): void
    {
        $response = $this->actingAs($this->purchasing)
            ->get(route('purchasing.master-data.exchange-rates.index'))
            ->assertOk();

        $response->assertSee(route('purchasing.master-data.material-hs-code.index'));
        $response->assertSee(route('purchasing.master-data.reference-prices.index'));
        $response->assertSee(route('purchasing.master-data.exchange-rates.index'));
    }

    public function test_purchasing_can_store_effective_rate(): void
    {
        $response = $this->actingAs($this->purchasing)
            ->post(route('purchasing.master-data.exchange-rates.store'), [
                'currency' => 'USD',
                'rate_to_idr' => 17650,
                'valid_from' => '2026-11-04',
            ]);

        $response->assertSessionHas('success');
        $this->assertDatabaseHas('exchange_rates', [
            'currency' => 'USD',
            'rate_to_idr' => 17650,
            'valid_from' => '2026-11-04',
            'created_by' => $this->purchasing->id,
        ]);
    }

    public function test_purchasing_can_store_effective_rate_via_json(): void
    {
        $response = $this->actingAs($this->purchasing)
            ->postJson(route('purchasing.master-data.exchange-rates.store'), [
                'currency' => 'JPY',
                'rate_to_idr' => 108.5,
                'valid_from' => '2026-11-04',
            ]);

        $response->assertOk()
            ->assertJsonPath('redirect', route('purchasing.master-data.exchange-rates.index', ['tab' => 'effective']));

        $this->assertDatabaseHas('exchange_rates', [
            'currency' => 'JPY',
            'rate_to_idr' => 108.5,
            'valid_from' => '2026-11-04',
            'created_by' => $this->purchasing->id,
        ]);
    }

    public function test_purchasing_can_store_annual_exchange_rates(): void
    {
        $response = $this->actingAs($this->purchasing)
            ->post(route('purchasing.master-data.exchange-rates.annual.store'), [
                'year' => 2027,
                'expected_revision' => 0,
                'rates' => [
                    'USD' => '17500',
                    'JPY' => '106.5',
                    'CNY' => '2300',
                ],
            ]);

        $response->assertRedirect(route('purchasing.master-data.exchange-rates.index', [
            'tab' => 'annual',
            'annual_page' => 1,
        ]));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('annual_exchange_rates', [
            'year' => 2027,
            'revision' => 1,
            'currency' => 'USD',
            'rate_to_idr' => 17500,
            'created_by' => $this->purchasing->id,
        ]);
    }

    public function test_purchasing_cannot_access_admin_exchange_rates_routes(): void
    {
        $this->actingAs($this->purchasing)
            ->get(route('admin.exchange-rates.index'))
            ->assertForbidden();

        $this->actingAs($this->purchasing)
            ->post(route('admin.exchange-rates.store'), [
                'currency' => 'USD',
                'rate_to_idr' => 17650,
                'valid_from' => '2026-11-04',
            ])
            ->assertForbidden();

        $this->actingAs($this->purchasing)
            ->post(route('admin.exchange-rates.annual.store'), [
                'year' => 2027,
                'expected_revision' => 0,
                'rates' => [
                    'USD' => '17500',
                    'JPY' => '106.5',
                    'CNY' => '2300',
                ],
            ])
            ->assertForbidden();
    }

    public function test_other_roles_cannot_access_purchasing_exchange_rates_routes(): void
    {
        foreach (['supplier', 'qc', 'finance', 'ga'] as $role) {
            $user = User::factory()->create([
                'role' => $role,
                'is_active' => true,
            ]);

            $this->actingAs($user)
                ->get(route('purchasing.master-data.exchange-rates.index'))
                ->assertForbidden();

            $this->actingAs($user)
                ->post(route('purchasing.master-data.exchange-rates.store'), [
                    'currency' => 'USD',
                    'rate_to_idr' => 17650,
                    'valid_from' => '2026-11-04',
                ])
                ->assertForbidden();

            $this->actingAs($user)
                ->post(route('purchasing.master-data.exchange-rates.annual.store'), [
                    'year' => 2027,
                    'expected_revision' => 0,
                    'rates' => [
                        'USD' => '17500',
                        'JPY' => '106.5',
                        'CNY' => '2300',
                    ],
                ])
                ->assertForbidden();
        }
    }

    public function test_inactive_purchasing_user_is_terminated_and_policy_denies(): void
    {
        $inactivePurchasing = User::factory()->create([
            'role' => 'purchasing',
            'is_active' => false,
        ]);

        $this->assertFalse($inactivePurchasing->can('viewAny', ExchangeRate::class));
        $this->assertFalse($inactivePurchasing->can('create', ExchangeRate::class));

        $this->actingAs($inactivePurchasing)
            ->get(route('purchasing.master-data.exchange-rates.index'))
            ->assertRedirect(route('login'));
    }
}
