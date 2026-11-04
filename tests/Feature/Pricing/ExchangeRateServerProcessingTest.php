<?php

namespace Tests\Feature\Pricing;

use App\Models\AnnualExchangeRate;
use App\Models\ExchangeRate;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ExchangeRateServerProcessingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setDate(2026, 11, 3)->setTime(10, 0));
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->actingAs($this->admin);
    }

    public function test_full_pages_expose_the_fragment_workspace_and_keep_one_permanent_effective_modal(): void
    {
        foreach ([[], ['tab' => 'annual']] as $query) {
            $response = $this->get($this->index($query))->assertOk();
            $xpath = $this->xpath($response->getContent());

            $this->assertSame(1, $xpath->query('//*[@id="exchangeRatesWorkspace" and @data-server-tabs-container]')->length);
            $this->assertSame(2, $xpath->query('//*[@id="exchangeRatesWorkspace"]//*[@data-server-tabs-nav]//a[@data-server-tab and @href]')->length);
            $this->assertSame(1, $xpath->query('//*[@id="exchangeRatesWorkspace"]//*[@data-server-tabs-content]')->length);
            $this->assertSame(1, $xpath->query('//*[@id="rateModal"]')->length);
            $this->assertSame(0, $xpath->query('//*[@data-server-tabs-content]//*[@id="rateModal"]')->length);
        }
    }

    public function test_generic_ajax_and_json_accept_headers_do_not_request_a_fragment(): void
    {
        $response = $this->get($this->index(['tab' => 'annual']), [
            'X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json',
        ])->assertOk()->assertViewIs('admin.exchange-rates.index');

        $this->assertStringContainsString('text/html', $response->headers->get('Content-Type'));
        $this->assertSame(1, $this->xpath($response->getContent())->query('//*[@id="exchangeRatesWorkspace"]')->length);
    }

    public function test_explicit_fragment_requests_return_the_rendered_content_nav_and_private_uncached_url(): void
    {
        foreach (['annual', 'effective'] as $tab) {
            $response = $this->fragment(['tab' => $tab])->assertOk()
                ->assertJsonStructure(['html', 'tab', 'url', 'nav'])
                ->assertJsonPath('tab', $tab)
                ->assertJsonPath('url', $this->index(['tab' => $tab]));

            $html = $response->json('html');
            $this->assertIsString($html);
            $this->assertStringNotContainsString('<html', strtolower($html));
            $this->assertStringNotContainsString('<body', strtolower($html));
            $this->assertStringNotContainsString('<aside', strtolower($html));
            $this->assertSame(0, $this->xpath($html)->query('//*[@id="exchangeRatesWorkspace" or @id="rateModal"]')->length);
            $this->assertIsString($response->json('nav'));
            $this->assertSame(1, $this->xpath($response->json('nav'))->query('//a[@aria-current="page" and contains(@href, "tab='.$tab.'")]')->length);
            $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
    }

    public function test_annual_fragments_page_and_filter_years_and_display_only_the_latest_revision(): void
    {
        foreach (range(2017, 2026) as $year) {
            $this->annual($year);
        }
        $this->annual(2024, 2, '17600');

        $latest = $this->fragment(['tab' => 'annual'])->assertOk();
        $this->assertSame([2022, 2023, 2024, 2025, 2026], $this->matrixYears($latest->json('html')));
        $usd2024 = $this->xpath($latest->json('html'))->evaluate('string(//table[@data-annual-rates-matrix]/tbody/tr[th[normalize-space(.)="USD"]]/td[3])');
        $this->assertSame('17600', preg_replace('/\D/', '', $usd2024));

        $older = $this->fragment(['tab' => 'annual', 'annual_page' => 2])->assertOk();
        $this->assertSame([2017, 2018, 2019, 2020, 2021], $this->matrixYears($older->json('html')));

        $filtered = $this->fragment(['annual_year' => 2024])->assertOk()->assertJsonPath('tab', 'annual');
        $this->assertSame([2024], $this->matrixYears($filtered->json('html')));
        $this->assertSame(0, $this->xpath($latest->json('html'))->query('//input[starts-with(@name, "rates[")]')->length);
        $this->assertDatabaseCount('annual_exchange_rates', 33);
    }

    public function test_effective_fragments_apply_currency_filter_and_server_pagination(): void
    {
        foreach (range(1, 35) as $day) {
            $this->effective('USD', (string) (17000 + $day), now()->subDays($day)->toDateString());
        }
        $this->effective('JPY', '106', '2026-11-02');

        foreach ([1 => 30, 2 => 5] as $page => $expectedRows) {
            $response = $this->fragment(['tab' => 'effective', 'currency' => 'USD', 'page' => $page])->assertOk();
            $xpath = $this->xpath($response->json('html'));
            $rows = $xpath->query('//table/tbody/tr[td]');
            $this->assertSame($expectedRows, $rows->length);
            foreach ($rows as $row) {
                $this->assertSame('USD', trim($xpath->evaluate('string(td[1])', $row)));
            }
        }
        $this->assertDatabaseCount('exchange_rates', 36);
    }

    public function test_edit_add_and_cancel_fragments_keep_comparison_columns_without_financial_writes(): void
    {
        foreach (range(2022, 2026) as $year) {
            $this->annual($year);
        }
        $this->annual(2024, 2, '17600');

        $edit = $this->fragment(['annual_edit_year' => 2024])->assertOk();
        $this->assertSame([2022, 2023, 2024, 2025, 2026], $this->matrixYears($edit->json('html')));
        $xpath = $this->xpath($edit->json('html'));
        $this->assertSame(3, $xpath->query('//input[starts-with(@name, "rates[")]')->length);
        $this->assertSame('2', $xpath->evaluate('string(//input[@name="expected_revision"]/@value)'));
        $this->assertSame('17600.0000', $xpath->evaluate('string(//input[@name="rates[USD]"]/@value)'));

        $draft = $this->fragment(['annual_new' => 1, 'annual_new_year' => 2027])->assertOk();
        $this->assertSame([2022, 2023, 2024, 2025, 2026, 2027], $this->matrixYears($draft->json('html')));
        $draftXpath = $this->xpath($draft->json('html'));
        $this->assertSame('0', $draftXpath->evaluate('string(//input[@name="expected_revision"]/@value)'));
        $inputs = $draftXpath->query('//input[starts-with(@name, "rates[")]');
        $this->assertSame(3, $inputs->length);
        foreach ($inputs as $input) {
            $this->assertSame('', $input->getAttribute('value'));
        }

        $cancel = $draftXpath->query('//form[@action="'.route('admin.exchange-rates.annual.store').'"]//a[contains(@href, "tab=annual") and not(contains(@href, "annual_edit_year")) and not(contains(@href, "annual_new"))]');
        $this->assertSame(1, $cancel->length);
        $cancelled = $this->get($cancel->item(0)->getAttribute('href'), $this->fragmentHeaders())->assertOk();
        $this->assertSame(0, $this->xpath($cancelled->json('html'))->query('//input[starts-with(@name, "rates[")]')->length);
        $this->assertDatabaseCount('annual_exchange_rates', 18);
    }

    public function test_annual_json_saves_return_a_message_and_fragment_url_without_flash_and_append_complete_revisions(): void
    {
        $first = $this->postJson(route('admin.exchange-rates.annual.store'), $this->annualPayload(2027, 0))
            ->assertOk()->assertJsonStructure(['redirect', 'message'])->assertSessionMissing('success');
        $this->assertSame('annual', $this->query($first->json('redirect'))['tab'] ?? null);
        $this->assertIsString($first->json('message'));
        $this->assertNotSame('', $first->json('message'));
        $this->assertDatabaseCount('annual_exchange_rates', 3);
        foreach (['USD', 'JPY', 'CNY'] as $currency) {
            $this->assertDatabaseHas('annual_exchange_rates', ['year' => 2027, 'revision' => 1, 'currency' => $currency]);
        }
        $updated = $this->get($first->json('redirect'), $this->fragmentHeaders())->assertOk()->assertJsonPath('tab', 'annual');
        $this->assertSame([2027], $this->matrixYears($updated->json('html')));

        $payload = $this->annualPayload(2027, 1);
        $payload['rates']['USD'] = '17900';
        $this->postJson(route('admin.exchange-rates.annual.store'), $payload)->assertOk()->assertSessionMissing('success');
        $this->assertDatabaseCount('annual_exchange_rates', 6);
        $this->assertDatabaseHas('annual_exchange_rates', ['year' => 2027, 'revision' => 1, 'currency' => 'USD', 'rate_to_idr' => '17500.0000']);
        $this->assertDatabaseHas('annual_exchange_rates', ['year' => 2027, 'revision' => 2, 'currency' => 'USD', 'rate_to_idr' => '17900.0000']);
    }

    public function test_annual_json_conflicts_distinguish_stale_edits_from_duplicate_creation_without_writes(): void
    {
        $this->annual(2024);
        $this->annual(2024, 2, '17600');

        $this->postJson(route('admin.exchange-rates.annual.store'), $this->annualPayload(2024, 1))
            ->assertUnprocessable()->assertJsonValidationErrors('expected_revision');
        $this->postJson(route('admin.exchange-rates.annual.store'), $this->annualPayload(2024, 0))
            ->assertUnprocessable()->assertJsonValidationErrors('year');
        $this->assertDatabaseCount('annual_exchange_rates', 6);
        $this->assertDatabaseHas('annual_exchange_rates', ['year' => 2024, 'revision' => 2, 'currency' => 'USD', 'rate_to_idr' => '17600.0000']);
    }

    public function test_invalid_annual_json_input_returns_field_errors_without_partial_writes(): void
    {
        $payload = $this->annualPayload(2024, 0);
        $payload['rates']['USD'] = '0';

        $this->postJson(route('admin.exchange-rates.annual.store'), $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('rates.USD');
        $this->assertDatabaseCount('annual_exchange_rates', 0);
    }

    public function test_effective_json_save_returns_a_message_and_refresh_url_without_a_session_flash(): void
    {
        $response = $this->postJson(route('admin.exchange-rates.store'), [
            'currency' => 'USD', 'rate_to_idr' => '17650', 'valid_from' => '2026-11-03',
        ])->assertOk()->assertJsonStructure(['redirect', 'message'])->assertSessionMissing('success');

        $this->assertSame('effective', $this->query($response->json('redirect'))['tab'] ?? null);
        $this->assertIsString($response->json('message'));
        $this->assertNotSame('', $response->json('message'));
        $this->assertDatabaseCount('exchange_rates', 1);
        $this->assertDatabaseHas('exchange_rates', ['currency' => 'USD', 'rate_to_idr' => 17650, 'valid_from' => '2026-11-03']);

        $fragment = $this->get($response->json('redirect'), $this->fragmentHeaders())->assertOk()->assertJsonPath('tab', 'effective');
        $xpath = $this->xpath($fragment->json('html'));
        $this->assertSame(1, $xpath->query('//table/tbody/tr[td]')->length);
        $this->assertSame('USD', trim($xpath->evaluate('string(//table/tbody/tr/td[1])')));
        $this->assertSame('1765000', preg_replace('/\D/', '', $xpath->evaluate('string(//table/tbody/tr/td[2])')));
    }

    public function test_normal_post_fallbacks_keep_redirects_and_success_flashes(): void
    {
        $this->post(route('admin.exchange-rates.annual.store'), $this->annualPayload(2024, 0))
            ->assertRedirect($this->index(['tab' => 'annual', 'annual_page' => 1]))
            ->assertSessionHas('success');
        $this->from($this->index(['tab' => 'effective']))->post(route('admin.exchange-rates.store'), [
            'currency' => 'JPY', 'rate_to_idr' => '106', 'valid_from' => '2026-11-03',
        ])->assertRedirect($this->index(['tab' => 'effective']))->assertSessionHas('success');
        $this->assertDatabaseCount('annual_exchange_rates', 3);
        $this->assertDatabaseCount('exchange_rates', 1);
    }

    public function test_fragment_and_json_write_contracts_keep_guest_and_role_boundaries(): void
    {
        auth()->logout();
        $this->get($this->index(['tab' => 'annual']), $this->fragmentHeaders())->assertUnauthorized();
        $this->postJson(route('admin.exchange-rates.annual.store'), $this->annualPayload(2024, 0))->assertUnauthorized();
        $this->postJson(route('admin.exchange-rates.store'), ['currency' => 'USD', 'rate_to_idr' => '17500', 'valid_from' => '2026-11-03'])->assertUnauthorized();

        $this->actingAs(User::factory()->create(['role' => 'purchasing', 'is_active' => true]));
        $this->fragment(['tab' => 'annual'])->assertForbidden();
        $this->postJson(route('admin.exchange-rates.annual.store'), $this->annualPayload(2024, 0))->assertForbidden();
        $this->postJson(route('admin.exchange-rates.store'), ['currency' => 'USD', 'rate_to_idr' => '17500', 'valid_from' => '2026-11-03'])->assertForbidden();
        $this->assertDatabaseCount('annual_exchange_rates', 0);
        $this->assertDatabaseCount('exchange_rates', 0);
    }

    private function annual(int $year, int $revision = 1, string $usd = '17200'): void
    {
        foreach (['USD' => $usd, 'JPY' => '105.2', 'CNY' => '2250'] as $currency => $rate) {
            AnnualExchangeRate::create([
                'year' => $year, 'revision' => $revision, 'currency' => $currency,
                'rate_to_idr' => $rate, 'created_by' => $this->admin->id,
            ]);
        }
    }

    private function effective(string $currency, string $rate, string $date): void
    {
        ExchangeRate::create(['currency' => $currency, 'rate_to_idr' => $rate, 'valid_from' => $date, 'created_by' => $this->admin->id]);
    }

    private function annualPayload(int $year, int $revision): array
    {
        return ['year' => $year, 'expected_revision' => $revision, 'rates' => ['USD' => '17500', 'JPY' => '106', 'CNY' => '2300']];
    }

    private function index(array $query = []): string
    {
        return route('admin.exchange-rates.index', $query);
    }

    private function fragmentHeaders(): array
    {
        return ['X-Adasi-Server-Tabs' => '1', 'Accept' => 'application/json'];
    }

    private function fragment(array $query): TestResponse
    {
        return $this->get($this->index($query), $this->fragmentHeaders());
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new DOMXPath($document);
    }

    private function matrixYears(string $html): array
    {
        $years = [];
        foreach ($this->xpath($html)->query('//table[@data-annual-rates-matrix]//th[@scope="col" and @data-annual-year]') as $header) {
            $years[] = (int) $header->getAttribute('data-annual-year');
        }

        return $years;
    }

    private function query(string $url): array
    {
        $this->assertSame(parse_url($this->index(), PHP_URL_HOST), parse_url($url, PHP_URL_HOST));
        $this->assertSame(parse_url($this->index(), PHP_URL_PATH), parse_url($url, PHP_URL_PATH));
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);

        return $query;
    }
}
