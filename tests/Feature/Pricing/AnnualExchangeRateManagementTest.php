<?php

namespace Tests\Feature\Pricing;

use App\Models\AnnualExchangeRate;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AnnualExchangeRateManagementTest extends TestCase
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

    public function test_effective_rates_remain_the_default_and_annual_links_select_the_annual_tab(): void
    {
        $this->annual(2024);

        $effective = $this->get($this->index())->assertOk();
        $this->assertActiveTab($effective, 'effective');
        $this->assertSame(0, $this->xpath($effective)->query('//table[@data-annual-rates-matrix]')->length);

        $annual = $this->get($this->index(['tab' => 'annual']))->assertOk();
        $this->assertActiveTab($annual, 'annual');
        $this->assertSame(1, $this->xpath($annual)->query('//table[@data-annual-rates-matrix]')->length);
        $this->assertNoAnnualEditor($annual);

        $this->assertActiveTab($this->get($this->index(['tab' => 'effective']))->assertOk(), 'effective');
        $filtered = $this->get($this->index(['annual_year' => 2024]))->assertOk()
            ->assertViewHas('annualMatrixYears', [2024])
            ->assertViewHas('annualYears', fn ($years) => $years->total() === 1);
        $this->assertActiveTab($filtered, 'annual');
        $this->assertNoAnnualEditor($filtered);
    }

    public function test_editing_one_year_keeps_the_five_comparison_years_and_uses_the_latest_revision(): void
    {
        foreach (range(2020, 2026) as $year) {
            $this->annual($year);
        }
        $this->annual(2024, revision: 2, usd: '17400');

        $response = $this->get($this->index(['annual_edit_year' => 2024]))->assertOk()
            ->assertViewHas('annualMatrixYears', [2022, 2023, 2024, 2025, 2026])
            ->assertViewHas('annualYears', fn ($years) => $years->total() === 7 && $years->currentPage() === 1);

        $this->assertActiveTab($response, 'annual');
        $this->assertEditor($response, 2024, '2', ['USD' => '17400.0000', 'JPY' => '105.2000', 'CNY' => '2250.0000']);
        $this->assertSame(24, AnnualExchangeRate::count());
    }

    public function test_editing_an_older_year_opens_its_page_without_collapsing_to_a_single_year(): void
    {
        foreach (range(2017, 2026) as $year) {
            $this->annual($year);
        }

        $response = $this->get($this->index(['annual_edit_year' => 2018]))->assertOk()
            ->assertViewHas('annualMatrixYears', [2017, 2018, 2019, 2020, 2021])
            ->assertViewHas('annualYears', fn ($years) => $years->currentPage() === 2 && $years->total() === 10);

        $this->assertEditor($response, 2018, '1');
        $filtered = $this->get($this->index(['annual_year' => 2018, 'annual_edit_year' => 2018]))->assertOk()
            ->assertViewHas('annualMatrixYears', [2018])
            ->assertViewHas('annualYears', fn ($years) => $years->total() === 1);
        $this->assertEditor($filtered, 2018, '1');
    }

    public function test_an_edit_request_for_a_year_without_stored_rates_returns_not_found(): void
    {
        $this->annual(2024);

        $this->get($this->index(['annual_edit_year' => 2025]))->assertNotFound();
        $this->assertDatabaseCount('annual_exchange_rates', 3);
    }

    public function test_adding_a_year_inserts_one_blank_draft_column_in_chronological_order_without_writes(): void
    {
        foreach ([2020, 2021, 2022, 2024, 2025, 2026] as $year) {
            $this->annual($year);
        }

        $response = $this->get($this->index(['annual_new' => 1, 'annual_new_year' => 2023]))->assertOk()
            ->assertViewHas('annualMatrixYears', [2021, 2022, 2023, 2024, 2025, 2026])
            ->assertViewHas('annualYears', fn ($years) => $years->total() === 6 && $years->perPage() === 5);

        $this->assertActiveTab($response, 'annual');
        $this->assertEditor($response, 2023, '0', ['USD' => '', 'JPY' => '', 'CNY' => '']);
        $this->assertDatabaseCount('annual_exchange_rates', 18);
    }

    public function test_add_without_a_candidate_asks_for_a_year_before_opening_a_draft(): void
    {
        $this->annual(2024);

        $response = $this->get($this->index(['annual_new' => 1]))->assertOk();
        $this->assertActiveTab($response, 'annual');
        $this->assertNoAnnualEditor($response);
        $year = $this->xpath($response)->query('//input[@name="annual_new_year"]');
        $this->assertSame(1, $year->length);
        $this->assertSame('', $year->item(0)->getAttribute('value'));
        $this->assertDatabaseCount('annual_exchange_rates', 3);
    }

    public function test_an_existing_year_cannot_be_opened_as_a_new_draft_and_offers_an_edit_link(): void
    {
        $this->annual(2024);

        $response = $this->get($this->index(['annual_new' => 1, 'annual_new_year' => 2024]))->assertOk();

        $this->assertNoAnnualEditor($response);
        $this->assertGreaterThan(0, $this->xpath($response)->query('//a[contains(@href, "annual_edit_year=2024")]')->length);
        $this->assertDatabaseCount('annual_exchange_rates', 3);
    }

    public function test_edit_mode_disables_other_annual_actions_and_keeps_get_forms_outside_the_post_form(): void
    {
        foreach (range(2017, 2026) as $year) {
            $this->annual($year);
        }
        $response = $this->get($this->index(['annual_edit_year' => 2024]))->assertOk();
        $xpath = $this->xpath($response);
        $form = $this->annualForm($xpath);

        $this->assertSame(0, $xpath->query('//a[contains(@href, "annual_new=1")]')->length);
        $this->assertSame(0, $xpath->query('//a[contains(@href, "annual_edit_year=")]')->length);
        $this->assertSame(0, $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " pagination ")]//a[@href]')->length);

        $filters = $xpath->query('//form[translate(@method, "get", "GET")="GET"][.//*[@name="annual_year" or @name="annual_new_year"]]');
        $this->assertGreaterThan(0, $filters->length);
        foreach ($filters as $filter) {
            $this->assertSame(0, $xpath->query('ancestor::form', $filter)->length);
            foreach ($xpath->query('.//input[not(@type="hidden")] | .//select | .//button', $filter) as $control) {
                $this->assertTrue($control->hasAttribute('disabled'), 'Annual GET controls must be disabled while editing.');
            }
        }

        $cancel = $xpath->query('.//a[contains(@href, "tab=annual") and not(contains(@href, "annual_edit_year")) and not(contains(@href, "annual_new"))]', $form);
        $this->assertSame(1, $cancel->length);
        $cancelled = $this->get($cancel->item(0)->getAttribute('href'))->assertOk();
        $this->assertNoAnnualEditor($cancelled);
        $this->assertDatabaseCount('annual_exchange_rates', 30);
        $this->assertNoNestedForms($response->getContent());
    }

    public function test_validation_restores_values_in_the_matrix_and_a_corrected_submission_saves_one_revision(): void
    {
        $this->annual(2024);
        $payload = $this->payload(2024, 1, ['USD' => '0', 'JPY' => '106.1234', 'CNY' => '2300']);

        $invalid = $this->from($this->index(['annual_edit_year' => 2024]))
            ->post(route('admin.exchange-rates.annual.store'), $payload)
            ->assertSessionHasErrors('rates.USD', null, 'annualRates')
            ->assertSessionHasInput('rates.USD', '0');
        $this->assertSame('annual', $this->redirectQuery($invalid)['tab'] ?? null);
        $response = $this->get($invalid->headers->get('Location'))->assertOk();
        $this->assertActiveTab($response, 'annual');
        $this->assertEditor($response, 2024, '1', $payload['rates']);
        $xpath = $this->xpath($response);
        $usd = $xpath->query('//input[@name="rates[USD]"]')->item(0);
        $this->assertSame('true', $usd->getAttribute('aria-invalid'));
        $errorIds = preg_split('/\s+/', trim($usd->getAttribute('aria-describedby')));
        $this->assertNotEmpty($errorIds);
        $this->assertTrue(collect($errorIds)->contains(fn ($id) => trim($xpath->evaluate('string(//*[@id="'.$id.'"])')) !== ''));
        $this->assertDatabaseCount('annual_exchange_rates', 3);

        $payload['rates']['USD'] = '17500';
        $this->post(route('admin.exchange-rates.annual.store'), $payload)
            ->assertRedirect($this->index(['tab' => 'annual', 'annual_page' => 1]))
            ->assertSessionHas('success');
        $this->assertDatabaseCount('annual_exchange_rates', 6);
        $this->assertDatabaseHas('annual_exchange_rates', ['year' => 2024, 'revision' => 2, 'currency' => 'USD', 'rate_to_idr' => '17500.0000']);
        $this->assertNoAnnualEditor($this->get($this->index(['tab' => 'annual', 'annual_page' => 1]))->assertOk());
    }

    public function test_stale_edits_retain_their_values_and_token_until_the_user_reloads_latest(): void
    {
        $this->annual(2024);
        $this->annual(2024, revision: 2, usd: '17600');
        $payload = $this->payload(2024, 1, ['USD' => '17400', 'JPY' => '106', 'CNY' => '2300']);

        $conflict = $this->from($this->index(['annual_edit_year' => 2024]))
            ->post(route('admin.exchange-rates.annual.store'), $payload)
            ->assertSessionHasErrors('expected_revision', null, 'annualRates');
        $this->assertSame('annual', $this->redirectQuery($conflict)['tab'] ?? null);
        $response = $this->get($conflict->headers->get('Location'))->assertOk();
        $this->assertEditor($response, 2024, '1', $payload['rates']);
        $reload = $this->xpath($response)->query('//a[contains(@href, "annual_edit_year=2024")]');
        $this->assertSame(1, $reload->length);

        $latest = $this->get($reload->item(0)->getAttribute('href'))->assertOk();
        $this->assertEditor($latest, 2024, '2', ['USD' => '17600.0000', 'JPY' => '105.2000', 'CNY' => '2250.0000']);
        $this->assertDatabaseCount('annual_exchange_rates', 6);
    }

    public function test_duplicate_create_is_a_year_error_and_preserves_the_draft_without_writes(): void
    {
        $this->annual(2024);
        $payload = $this->payload(2024, 0, ['USD' => '17500', 'JPY' => '106', 'CNY' => '2300']);

        $duplicate = $this->post(route('admin.exchange-rates.annual.store'), $payload)
            ->assertSessionHasErrors('year', null, 'annualRates');
        $this->assertSame('annual', $this->redirectQuery($duplicate)['tab'] ?? null);
        $response = $this->get($duplicate->headers->get('Location'))->assertOk();
        $this->assertEditor($response, 2024, '0', $payload['rates']);
        $this->assertGreaterThan(0, $this->xpath($response)->query('//a[contains(@href, "annual_edit_year=2024")]')->length);
        $this->assertDatabaseCount('annual_exchange_rates', 3);
    }

    public function test_annual_errors_force_the_annual_tab_and_restore_the_submitted_year_revision_pair(): void
    {
        $this->annual(2024);
        $this->annual(2025, revision: 2);
        $payload = $this->payload(2024, 1, ['USD' => '17123', 'JPY' => '106', 'CNY' => '2300']);

        $response = $this->withSession([
            'errors' => (new ViewErrorBag)->put('annualRates', new MessageBag(['rates.USD' => 'Review this rate.'])),
            '_old_input' => $payload,
        ])->get($this->index(['tab' => 'effective', 'annual_edit_year' => 2025]))->assertOk();

        $this->assertActiveTab($response, 'annual');
        $this->assertEditor($response, 2024, '1', $payload['rates']);
    }

    public function test_effective_errors_and_old_input_cannot_populate_an_annual_editor(): void
    {
        $this->annual(2024);
        $session = [
            'errors' => (new ViewErrorBag)->put('default', new MessageBag(['rate_to_idr' => 'Enter an effective rate.'])),
            '_old_input' => $this->payload(2025, 99, ['USD' => '99999', 'JPY' => '999', 'CNY' => '9999']),
        ];

        $effective = $this->withSession($session)->get($this->index())->assertOk();
        $this->assertActiveTab($effective, 'effective');
        $this->assertNoAnnualEditor($effective);

        $annual = $this->withSession($session)->get($this->index(['annual_edit_year' => 2024]))->assertOk();
        $this->assertEditor($annual, 2024, '1', ['USD' => '17200.0000', 'JPY' => '105.2000', 'CNY' => '2250.0000']);
    }

    public function test_malformed_old_input_does_not_crash_the_annual_page_or_render_array_values(): void
    {
        $this->annual(2024);
        foreach ([
            ['year' => [2024], 'expected_revision' => [1], 'rates' => ['USD' => ['bad'], 'JPY' => [], 'CNY' => ['bad']]],
            ['year' => 2024, 'expected_revision' => [1], 'rates' => 'invalid'],
            ['year' => 2024, 'expected_revision' => 1, 'rates' => ['USD' => ['bad'], 'JPY' => '106', 'CNY' => '2300']],
        ] as $oldInput) {
            $response = $this->withSession([
                'errors' => (new ViewErrorBag)->put('annualRates', new MessageBag(['rates' => 'Check the submitted rates.'])),
                '_old_input' => $oldInput,
            ])->get($this->index(['tab' => 'annual']))->assertOk();

            $this->assertActiveTab($response, 'annual');
            $this->assertSame(0, $this->xpath($response)->query('//input[@value="Array"]')->length);
            $this->assertLessThanOrEqual(3, $this->xpath($response)->query('//input[starts-with(@name, "rates[")]')->length);
        }
        $this->assertDatabaseCount('annual_exchange_rates', 3);
    }

    public function test_success_returns_to_the_saved_years_page_without_leaving_an_editor_or_exact_year_filter(): void
    {
        foreach (range(2017, 2026) as $year) {
            $this->annual($year);
        }

        $saved = $this->post(route('admin.exchange-rates.annual.store'), $this->payload(2018, 1))
            ->assertRedirect($this->index(['tab' => 'annual', 'annual_page' => 2]));
        $this->assertArrayNotHasKey('annual_year', $this->redirectQuery($saved));
        $response = $this->get($saved->headers->get('Location'))->assertOk()
            ->assertViewHas('annualMatrixYears', [2017, 2018, 2019, 2020, 2021]);
        $this->assertNoAnnualEditor($response);
        $this->assertDatabaseCount('annual_exchange_rates', 33);
    }

    public function test_dashboard_stays_read_only_and_distinguishes_edit_links_from_missing_year_add_links(): void
    {
        $this->annual(2026);

        $response = $this->get(route('admin.dashboard'))->assertOk();
        $xpath = $this->xpath($response);
        $this->assertNoAnnualEditor($response);
        $this->assertSame(0, $xpath->query('//input[starts-with(@name, "rates[")]')->length);
        $this->assertGreaterThan(0, $xpath->query('//a[contains(@href, "annual_edit_year=2026")]')->length);
        $this->assertGreaterThan(0, $xpath->query('//a[contains(@href, "annual_new=1") and contains(@href, "annual_new_year=2025")]')->length);
        $this->assertDatabaseCount('annual_exchange_rates', 3);
    }

    public function test_submitting_rates_with_comma_decimals_normalizes_to_dots_and_saves_successfully(): void
    {
        $this->annual(2024);
        $payload = $this->payload(2024, 1, ['USD' => '17500,50', 'JPY' => '106,1234', 'CNY' => '2300,75']);

        $this->post(route('admin.exchange-rates.annual.store'), $payload)
            ->assertRedirect($this->index(['tab' => 'annual', 'annual_page' => 1]))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('annual_exchange_rates', [
            'year' => 2024,
            'revision' => 2,
            'currency' => 'USD',
            'rate_to_idr' => '17500.5000',
        ]);
        $this->assertDatabaseHas('annual_exchange_rates', [
            'year' => 2024,
            'revision' => 2,
            'currency' => 'JPY',
            'rate_to_idr' => '106.1234',
        ]);
    }

    public function test_tabs_render_segmented_container_and_semantic_icons(): void
    {
        $response = $this->get($this->index())->assertOk();
        $xpath = $this->xpath($response);
        $nav = $xpath->query('//nav[@data-server-tabs-nav]')->item(0);
        $this->assertNotNull($nav);
        $this->assertStringContainsString('bg-surface-container', $nav->getAttribute('class'));

        $effectiveTab = $xpath->query('//a[@data-tab-name="effective"]')->item(0);
        $this->assertNotNull($effectiveTab);
        $annualTab = $xpath->query('//a[@data-tab-name="annual"]')->item(0);
        $this->assertNotNull($annualTab);
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

    private function index(array $query = []): string
    {
        return route('admin.exchange-rates.index', $query);
    }

    private function payload(int $year, int $revision, array $rates = ['USD' => '17500', 'JPY' => '106', 'CNY' => '2300']): array
    {
        return ['year' => $year, 'expected_revision' => $revision, 'rates' => $rates];
    }

    private function xpath(TestResponse $response): DOMXPath
    {
        $document = new DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);

        return new DOMXPath($document);
    }

    private function annualForm(DOMXPath $xpath): DOMElement
    {
        $forms = $xpath->query('//form[@action="'.route('admin.exchange-rates.annual.store').'"]');
        $this->assertSame(1, $forms->length, 'Exactly one form may submit an annual revision.');

        return $forms->item(0);
    }

    private function assertNoAnnualEditor(TestResponse $response): void
    {
        $this->assertSame(0, $this->xpath($response)->query('//form[@action="'.route('admin.exchange-rates.annual.store').'"]')->length);
    }

    private function assertActiveTab(TestResponse $response, string $tab): void
    {
        $this->assertSame(1, $this->xpath($response)->query('//a[@aria-current="page" and contains(@href, "tab='.$tab.'")]')->length);
    }

    private function assertEditor(TestResponse $response, int $year, string $revision, ?array $values = null): void
    {
        $xpath = $this->xpath($response);
        $form = $this->annualForm($xpath);
        $this->assertSame('post', strtolower($form->getAttribute('method')));
        $this->assertSame((string) $year, $xpath->evaluate('string(.//input[@name="year"]/@value)', $form));
        $this->assertSame('hidden', $xpath->evaluate('string(.//input[@name="year"]/@type)', $form));
        $this->assertSame($revision, $xpath->evaluate('string(.//input[@name="expected_revision"]/@value)', $form));
        $this->assertSame(1, $xpath->query('.//table[@data-annual-rates-matrix]', $form)->length);
        $this->assertSame(3, $xpath->query('.//input[starts-with(@name, "rates[")]', $form)->length);
        $this->assertSame(0, $xpath->query('.//input[@name="rates[IDR]" or @name="idr_identity"]', $form)->length);

        $header = $xpath->query('.//th[@scope="col" and @data-annual-year="'.$year.'"]', $form)->item(0);
        $this->assertNotNull($header);
        $this->assertSame(0, $xpath->query('.//input[not(@type="hidden")]', $header)->length, 'The selected year must not be editable.');
        $columnIndex = $xpath->query('preceding-sibling::th | preceding-sibling::td', $header)->length;
        foreach (AnnualExchangeRate::CURRENCIES as $currency) {
            $inputs = $xpath->query('.//input[@name="rates['.$currency.']"]', $form);
            $this->assertSame(1, $inputs->length);
            $input = $inputs->item(0);
            $this->assertTrue($input->hasAttribute('data-annual-rate-input'), 'The rate input must have data-annual-rate-input attribute.');
            $cell = $xpath->query('ancestor::td[1]', $input)->item(0);
            $this->assertNotNull($cell, 'The currency input must be inside its matrix cell.');
            $this->assertSame($columnIndex, $xpath->query('preceding-sibling::th | preceding-sibling::td', $cell)->length);
            $label = $xpath->evaluate('string(.//label[@for="'.$input->getAttribute('id').'"])', $form);
            $this->assertStringContainsString($currency, $label);
            $this->assertStringContainsString((string) $year, $label);
            if ($values !== null) {
                $this->assertSame($values[$currency], $input->getAttribute('value'));
            }
        }
        $this->assertNoNestedForms($response->getContent());
    }

    private function redirectQuery(TestResponse $response): array
    {
        $this->assertNotNull($response->headers->get('Location'));
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY) ?? '', $query);

        return $query;
    }

    private function assertNoNestedForms(string $html): void
    {
        preg_match_all('/<\/?form\b[^>]*>/i', $html, $forms);
        $depth = 0;
        foreach ($forms[0] as $form) {
            $depth += str_starts_with($form, '</') ? -1 : 1;
            $this->assertContains($depth, [0, 1], 'Forms must not be nested; DOM parsing would silently repair them.');
        }
        $this->assertSame(0, $depth);
    }
}
