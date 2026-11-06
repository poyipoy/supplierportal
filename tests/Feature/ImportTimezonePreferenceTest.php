<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\RegionalDisplayFormatter;
use App\Services\UserPreferenceService;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Supplier Import work-location timezone: display-only, Import context only,
 * with the global regional timezone kept for staff and Local Supplier.
 */
class ImportTimezonePreferenceTest extends TestCase
{
    use RefreshDatabase;

    private const INSTANT = '2026-09-28T23:35:00Z';

    private function supplier(array $scopes): User
    {
        $user = User::factory()->create(['role' => 'supplier']);
        $user->supplierScopes()->delete();
        foreach ($scopes as $scope) {
            DB::table('supplier_scopes')->insert(['supplier_id' => $user->id, 'scope' => $scope, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $user;
    }

    private function payload(string $context, array $values = []): array
    {
        return [
            'theme' => 'system', 'density' => 'comfortable', 'sidebar_state' => 'expanded', 'page_size' => 25,
            'timezone' => 'Asia/Jakarta', 'supplier_context' => $context, ...$values,
        ];
    }

    private function formatterFor(User $user, array $session = []): RegionalDisplayFormatter
    {
        $this->app->forgetScopedInstances();
        auth()->forgetGuards();
        $this->actingAs($user);
        session($session);

        return app(RegionalDisplayFormatter::class);
    }

    public function test_resolution_matches_the_shared_javascript_fixture(): void
    {
        $cases = json_decode(file_get_contents(base_path('tests/Fixtures/import-timezone-resolution-cases.json')), true, flags: JSON_THROW_ON_ERROR);

        foreach ($cases['country_defaults'] as $country => $zone) {
            $this->assertSame($zone, config('country_timezones.'.$country), "fixture default for {$country} mirrors config");
        }
        foreach ($cases['resolution'] as $case) {
            $this->assertSame($case['expected'], UserPreferenceService::resolveDisplayTimezone($case['import_context'], [
                'timezone' => $case['timezone'], 'country_code' => $case['country_code'], 'import_timezone' => $case['import_timezone'],
            ]), $case['name']);
        }
        foreach ($cases['timestamps'] as $case) {
            $formatter = new RegionalDisplayFormatter(['display_timezone' => $case['timezone'], 'date_format' => 'human', 'time_format' => '24h']);
            $this->assertSame($case['expected'], $formatter->timestamp(CarbonImmutable::parse($case['iso'])), $case['timezone']);
        }
    }

    public function test_every_country_default_is_a_zone_of_that_country(): void
    {
        $countries = config('country_timezones');
        $this->assertSame('Asia/Shanghai', $countries['CN']);
        $this->assertSame('Asia/Jakarta', $countries['ID']);
        foreach ($countries as $code => $zone) {
            $this->assertMatchesRegularExpression('/^[A-Z]{2}$/', $code);
            $this->assertContains($zone, DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, $code), "{$code} => {$zone}");
        }
    }

    public function test_import_supplier_saves_country_and_china_defaults_to_shanghai(): void
    {
        $user = $this->supplier(['import']);

        $this->actingAs($user)->get(route('profile.customization'))->assertOk()
            ->assertSee('name="country_code"', false)
            ->assertSee('name="import_timezone"', false);

        $this->patch(route('profile.customization.update'), $this->payload('import', ['country_code' => 'CN', 'import_timezone' => null]))
            ->assertSessionHasNoErrors();

        $saved = $user->fresh()->preference;
        $this->assertSame('CN', $saved->country_code);
        $this->assertNull($saved->import_timezone);
        $this->assertSame('Asia/Jakarta', $saved->timezone, 'the global timezone keeps its own value');

        $source = CarbonImmutable::parse(self::INSTANT);
        $formatter = $this->formatterFor($user);
        $this->assertSame('29 Sep 2026 07:35 UTC+08:00', $formatter->timestamp($source));
        $this->assertSame('28 Sep 2026', $formatter->date('2026-09-28'), 'calendar dates never shift');
        $this->assertSame('Z', $source->getTimezone()->getName(), 'the source instant is not mutated');
        $this->assertSame(self::INSTANT, $source->format('Y-m-d\TH:i:s\Z'));
    }

    public function test_manual_zone_overrides_the_country_and_reset_clears_both(): void
    {
        $user = $this->supplier(['import']);

        $this->actingAs($user)->patch(route('profile.customization.update'), $this->payload('import', ['country_code' => 'CN', 'import_timezone' => 'Asia/Urumqi']))
            ->assertSessionHasNoErrors();
        $this->assertSame('29 Sep 2026 05:35 UTC+06:00', $this->formatterFor($user)->timestamp(CarbonImmutable::parse(self::INSTANT)));

        $this->actingAs($user)->delete(route('profile.customization.reset'))->assertRedirect();
        $saved = $user->fresh()->preference;
        $this->assertNull($saved->country_code);
        $this->assertNull($saved->import_timezone);
        $this->assertSame('system', $saved->timezone);
    }

    public function test_empty_country_keeps_the_global_timezone(): void
    {
        $user = $this->supplier(['import']);

        $this->actingAs($user)->patch(route('profile.customization.update'), $this->payload('import', ['country_code' => null, 'import_timezone' => null]))
            ->assertSessionHasNoErrors();

        $this->assertSame('29 Sep 2026 06:35 WIB', $this->formatterFor($user)->timestamp(CarbonImmutable::parse(self::INSTANT)));
    }

    public function test_invalid_zone_or_country_is_rejected_without_changes(): void
    {
        $user = $this->supplier(['import']);
        $this->actingAs($user);

        foreach ([['import_timezone' => 'Asia/Atlantis'], ['import_timezone' => 'WIB'], ['country_code' => 'XX'], ['country_code' => 'cn']] as $invalid) {
            $this->patch(route('profile.customization.update'), $this->payload('import', $invalid))
                ->assertSessionHasErrors(array_keys($invalid));
        }
        $this->assertNull($user->fresh()->preference);
    }

    public function test_accounts_outside_the_import_context_cannot_change_the_setting(): void
    {
        $local = $this->supplier(['local']);
        $dual = $this->supplier(['import', 'local']);
        $staff = User::factory()->create(['role' => 'purchasing']);

        foreach ([[$local, 'local', []], [$dual, 'local', ['supplier_context' => 'local']], [$staff, '', []]] as [$user, $context, $session]) {
            $this->actingAs($user)->withSession($session)->get(route('profile.customization'))->assertOk()
                ->assertDontSee('name="country_code"', false)
                ->assertDontSee('name="import_timezone"', false);

            $payload = $this->payload($context, ['country_code' => 'CN', 'import_timezone' => 'Asia/Shanghai']);
            if ($context === '') {
                unset($payload['supplier_context']);
            }
            $this->actingAs($user)->withSession($session)->patch(route('profile.customization.update'), $payload)
                ->assertSessionHasErrors(['country_code', 'import_timezone']);
            $this->assertNull($user->fresh()->preference, $user->role.' '.$context);
        }
    }

    public function test_staff_and_local_supplier_keep_the_global_timezone_even_with_a_stored_country(): void
    {
        $local = $this->supplier(['local']);
        $staff = User::factory()->create(['role' => 'purchasing']);
        $dual = $this->supplier(['import', 'local']);
        foreach ([$local, $staff, $dual] as $user) {
            $user->preference()->create([...config('user_preferences.defaults'), 'timezone' => 'Asia/Jakarta', 'country_code' => 'CN', 'import_timezone' => 'Asia/Urumqi']);
        }
        $source = CarbonImmutable::parse(self::INSTANT);

        $this->assertSame('29 Sep 2026 06:35 WIB', $this->formatterFor($local)->timestamp($source));
        $this->assertSame('29 Sep 2026 06:35 WIB', $this->formatterFor($staff)->timestamp($source));
        $this->assertSame('29 Sep 2026 06:35 WIB', $this->formatterFor($dual, ['supplier_context' => 'local'])->timestamp($source));
        $this->assertSame('29 Sep 2026 05:35 UTC+06:00', $this->formatterFor($dual, ['supplier_context' => 'import'])->timestamp($source));
    }

    public function test_frontend_payload_carries_the_effective_zone_per_context(): void
    {
        $import = $this->supplier(['import']);
        $import->preference()->create([...config('user_preferences.defaults'), 'timezone' => 'Asia/Jakarta', 'country_code' => 'CN']);
        $staff = User::factory()->create(['role' => 'purchasing']);
        $staff->preference()->create([...config('user_preferences.defaults'), 'timezone' => 'Asia/Jakarta', 'country_code' => 'CN']);
        $service = app(UserPreferenceService::class);

        $this->actingAs($import);
        $this->assertSame('Asia/Shanghai', $service->frontendPayload($import, $service->for($import))['regional']['timezone']);
        $this->actingAs($staff);
        $this->assertSame('Asia/Jakarta', $service->frontendPayload($staff, $service->for($staff))['regional']['timezone']);
    }

    public function test_saves_that_omit_the_fields_keep_the_stored_country_and_zone(): void
    {
        $dual = $this->supplier(['import', 'local']);
        $dual->preference()->create([...config('user_preferences.defaults'), 'country_code' => 'CN', 'import_timezone' => 'Asia/Urumqi']);

        // A save from the Local context (fields not rendered) and the dashboard reset must not clear them.
        $this->actingAs($dual)->withSession(['supplier_context' => 'local'])
            ->patch(route('profile.customization.update'), $this->payload('local'))->assertSessionHasNoErrors();
        $this->delete(route('profile.customization.reset'), ['scope' => 'dashboard'])->assertRedirect();

        $saved = $dual->fresh()->preference;
        $this->assertSame('CN', $saved->country_code);
        $this->assertSame('Asia/Urumqi', $saved->import_timezone);
    }
}
