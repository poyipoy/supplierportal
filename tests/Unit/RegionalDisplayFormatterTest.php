<?php

namespace Tests\Unit;

use App\Services\RegionalDisplayFormatter;
use App\Support\NumberFormat;
use Carbon\Carbon;
use DateTimeImmutable;
use Tests\TestCase;

class RegionalDisplayFormatterTest extends TestCase
{
    public function test_php_and_javascript_share_calendar_date_golden_cases(): void
    {
        $cases = json_decode(file_get_contents(base_path('tests/Fixtures/regional-date-cases.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach ($cases as $case) {
            $formatter = new RegionalDisplayFormatter(['date_format' => $case['target'], 'timezone' => 'Asia/Jakarta']);
            $this->assertSame($case['expected'], $formatter->date($case['value'], $case['profile']));
        }
    }

    public function test_php_and_javascript_share_numeric_golden_cases(): void
    {
        $cases = json_decode(file_get_contents(base_path('tests/Fixtures/regional-display-cases.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach ($cases as $case) {
            $formatter = new RegionalDisplayFormatter(['number_format' => $case['target']]);
            $this->assertSame($case['expected'], $formatter->number($case['text'], $case['profile']));
        }
    }

    public function test_system_preserves_original_numeric_text_byte_for_byte(): void
    {
        $formatter = new RegionalDisplayFormatter([]);
        foreach (['1,250,000.50', '1.250.000,50', '1250000.5', '-0.00', '0', ' 12.500 ', '-', ''] as $text) {
            $this->assertSame($text, $formatter->number($text));
        }
    }

    public function test_number_presets_change_only_separators_and_preserve_precision(): void
    {
        $indonesian = new RegionalDisplayFormatter(['number_format' => 'indonesian']);
        $international = new RegionalDisplayFormatter(['number_format' => 'international']);
        foreach ([
            ['1,250,000.50', 'international', '1.250.000,50'],
            ['1250000.5', 'decimal', '1.250.000,5'],
            ['-0.00', 'decimal', '-0,00'],
            ['0', 'plain', '0'],
            ['1250000', 'plain', '1.250.000'],
            ['-1,250.5000', 'international', '-1.250,5000'],
            ['  1234.50  ', 'decimal', '  1.234,50  '],
            ['999999999999999999999999.1234567890', 'decimal', '999.999.999.999.999.999.999.999,1234567890'],
        ] as [$source, $profile, $expected]) {
            $this->assertSame($expected, $indonesian->number($source, $profile));
            $this->assertSame($source === '  1234.50  ' ? '  1,234.50  ' : strtr($expected, ['.' => ',', ',' => '.']), $international->number($expected, 'indonesian'));
        }
        $this->assertSame('1,250,000.50', $international->number('1.250.000,50', 'indonesian'));
    }

    public function test_trusted_max_decimal_output_retains_its_existing_scale(): void
    {
        $formatter = new RegionalDisplayFormatter(['number_format' => 'indonesian']);
        foreach ([0, -1250.5, 1234, 1.23456789] as $value) {
            $text = NumberFormat::maxDecimals($value);
            $this->assertSame(str_replace('.', ',', $text), str_replace('.', '', $formatter->number($text, 'decimal')));
        }
    }

    public function test_placeholders_currency_units_and_unrecognized_text_are_not_parsed(): void
    {
        $formatter = new RegionalDisplayFormatter(['number_format' => 'indonesian']);
        foreach (['-', '', 'Rp 1,234.00', '12 kg', '1e6', '1,2,3', 'NaN'] as $text) {
            $this->assertSame($text, $formatter->number($text));
        }
    }

    public function test_system_date_and_timestamp_profiles_preserve_legacy_behavior(): void
    {
        $formatter = new RegionalDisplayFormatter([]);
        $instant = new DateTimeImmutable('2026-09-28T23:35:00Z');
        $this->assertSame('28 Sep 2026', $formatter->date('2026-09-28'));
        $this->assertSame('2026-09-28', $formatter->date('2026-09-28', 'iso'));
        $this->assertSame('28 Sep 2026', $formatter->timestamp($instant, 'date'));
        $this->assertSame('28 Sep 2026 23:35', $formatter->timestamp($instant));
        $this->assertSame('28 Sep 2026, 23:35', $formatter->timestamp($instant, 'datetime_comma'));
        $this->assertSame('23:35', $formatter->time($instant));
    }

    public function test_jakarta_converts_an_instant_without_mutating_its_source(): void
    {
        $formatter = new RegionalDisplayFormatter(['timezone' => 'Asia/Jakarta', 'time_format' => '12h']);
        $source = Carbon::parse('2026-09-28T23:35:00Z');
        $before = [$source->format('c'), $source->getTimezone()->getName(), $source->getTimestamp()];
        $this->assertSame('29 Sep 2026 6:35 AM WIB', $formatter->timestamp($source));
        $this->assertSame('29 Sep 2026 WIB', $formatter->timestamp($source, 'date'));
        $this->assertSame('6:35 AM WIB', $formatter->time($source));
        $this->assertSame($before, [$source->format('c'), $source->getTimezone()->getName(), $source->getTimestamp()]);
    }

    public function test_calendar_date_never_timezone_shifts_or_gains_hours(): void
    {
        $formatter = new RegionalDisplayFormatter(['timezone' => 'Asia/Jakarta', 'date_format' => 'dmy', 'time_format' => '12h']);
        $source = Carbon::parse('2026-09-28T23:35:00Z');
        $this->assertSame('28/09/2026', $formatter->date($source));
        $this->assertSame('28/09/2026', $formatter->date('2026-09-28'));
        $this->assertSame('2026-09-28T23:35:00+00:00', $source->format('c'));
    }

    public function test_date_presets_and_time_presets_are_independent(): void
    {
        $instant = new DateTimeImmutable('2026-09-28T14:35:00Z');
        foreach (['human' => '28 Sep 2026', 'dmy' => '28/09/2026', 'iso' => '2026-09-28'] as $key => $expected) {
            $formatter = new RegionalDisplayFormatter(['date_format' => $key, 'time_format' => '12h']);
            $this->assertSame($expected, $formatter->date('2026-09-28', 'iso'));
            $this->assertSame($expected.' 2:35 PM', $formatter->timestamp($instant));
            $this->assertSame($expected, $formatter->timestamp($instant, 'date'));
        }
    }

    public function test_forged_preferences_fall_back_without_changing_global_state(): void
    {
        $zone = date_default_timezone_get();
        $locale = app()->getLocale();
        $formatter = new RegionalDisplayFormatter(['timezone' => 'Asia/Jayapura', 'date_format' => 'D M j', 'time_format' => '<script>', 'number_format' => 'arbitrary']);
        $this->assertSame('28 Sep 2026 23:35', $formatter->timestamp(new DateTimeImmutable('2026-09-28T23:35:00Z')));
        $this->assertSame('1250.5', $formatter->number('1250.5'));
        $this->assertSame($zone, date_default_timezone_get());
        $this->assertSame($locale, app()->getLocale());
    }

    public function test_detail_legacy_calendar_profiles_preserve_system_and_allow_explicit_choices(): void
    {
        $source = new DateTimeImmutable('2026-09-28T00:00:00Z');
        $system = new RegionalDisplayFormatter(['timezone' => 'Asia/Jakarta']);
        $this->assertSame('28 September 2026', $system->date($source, 'full_human'));
        $this->assertSame('28/09/2026', $system->date($source, 'dmy'));

        foreach (['human' => '28 Sep 2026', 'dmy' => '28/09/2026', 'iso' => '2026-09-28'] as $choice => $expected) {
            $formatter = new RegionalDisplayFormatter(['date_format' => $choice, 'timezone' => 'Asia/Jakarta']);
            $this->assertSame($expected, $formatter->date($source, 'full_human'));
            $this->assertSame($expected, $formatter->date($source, 'dmy'));
        }
        $this->assertSame('2026-09-28T00:00:00+00:00', $source->format('c'));
    }

    public function test_detail_views_receive_the_same_scoped_formatter_without_global_injection(): void
    {
        $factory = app('view');
        $formatter = app(RegionalDisplayFormatter::class);
        foreach ([
            'purchasing.po.show', 'supplier.po.show',
            'purchasing.quotations.show', 'supplier.quotations.show',
            'purchasing.shipments.show', 'supplier.shipments.show',
            'ga.claims.index', 'ga.claims.show',
            'finance.ga-claims.index', 'finance.ga-claims.show',
            'exports.index',
        ] as $name) {
            $view = $factory->make($name);
            $factory->callComposer($view);
            $this->assertSame($formatter, $view->getData()['regionalFormatter'] ?? null, $name);
        }
        foreach (['auth.login', 'purchasing.claims.create', 'supplier.quotations.period'] as $name) {
            $view = $factory->make($name);
            $factory->callComposer($view);
            $this->assertArrayNotHasKey('regionalFormatter', $view->getData(), $name);
        }
    }

    public function test_claim_and_history_timestamp_profiles_preserve_system_and_explicit_preferences(): void
    {
        $instant = new DateTimeImmutable('2026-09-28T23:35:00Z');
        $system = new RegionalDisplayFormatter([]);
        $this->assertSame('28 September 2026', $system->timestamp($instant, 'date_full_human'));
        $this->assertSame('28 Sep 23:35', $system->timestamp($instant, 'short_datetime'));
        $jakarta = new RegionalDisplayFormatter(['timezone' => 'Asia/Jakarta']);
        $this->assertSame('29 September 2026 WIB', $jakarta->timestamp($instant, 'date_full_human'));
        $this->assertSame('29 Sep 06:35 WIB', $jakarta->timestamp($instant, 'short_datetime'));
        foreach (['human' => '29 Sep 2026', 'dmy' => '29/09/2026', 'iso' => '2026-09-29'] as $key => $date) {
            $formatter = new RegionalDisplayFormatter(['timezone' => 'Asia/Jakarta', 'date_format' => $key, 'time_format' => '12h']);
            $this->assertSame($date.' WIB', $formatter->timestamp($instant, 'date_full_human'));
            $this->assertSame($date.' 6:35 AM WIB', $formatter->timestamp($instant, 'short_datetime'));
        }
        $this->assertSame('2026-09-28T23:35:00+00:00', $instant->format('c'));
    }

    public function test_material_claim_show_views_receive_only_the_existing_scoped_formatter(): void
    {
        $factory = app('view');
        $formatter = app(RegionalDisplayFormatter::class);
        foreach (['purchasing.claims.show', 'supplier.claims.show'] as $name) {
            $view = $factory->make($name);
            $factory->callComposer($view);
            $this->assertSame($formatter, $view->getData()['regionalFormatter'] ?? null, $name);
        }
    }
}
