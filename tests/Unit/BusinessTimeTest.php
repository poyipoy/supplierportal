<?php

namespace Tests\Unit;

use App\Support\BusinessTime;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class BusinessTimeTest extends TestCase
{
    public function test_default_timezone_and_label(): void
    {
        $this->assertSame('Asia/Jakarta', BusinessTime::tz());
        $this->assertSame('WIB', BusinessTime::label());
    }

    public function test_now_and_today_use_business_timezone(): void
    {
        $now = BusinessTime::now();
        $this->assertInstanceOf(CarbonImmutable::class, $now);
        $this->assertSame('Asia/Jakarta', $now->getTimezone()->getName());

        $today = BusinessTime::today();
        $this->assertInstanceOf(CarbonImmutable::class, $today);
        $this->assertSame('Asia/Jakarta', $today->getTimezone()->getName());
        $this->assertSame('00:00:00', $today->format('H:i:s'));
    }

    public function test_now_respects_time_travel(): void
    {
        $targetUtc = Carbon::parse('2026-10-14 02:00:00', 'UTC');
        $this->travelTo($targetUtc);

        $now = BusinessTime::now();
        $this->assertSame('2026-10-14 09:00:00', $now->format('Y-m-d H:i:s'));
        $this->assertSame('Asia/Jakarta', $now->getTimezone()->getName());
    }

    public function test_to_business_converts_utc_to_business_timezone(): void
    {
        $utc = Carbon::parse('2026-10-14 02:00:00', 'UTC');
        $biz = BusinessTime::toBusiness($utc);

        $this->assertSame('2026-10-14 09:00:00', $biz->format('Y-m-d H:i:s'));
        $this->assertSame('Asia/Jakarta', $biz->getTimezone()->getName());

        $bizFromString = BusinessTime::toBusiness('2026-10-14 02:00:00');
        $this->assertSame('2026-10-14 09:00:00', $bizFromString->format('Y-m-d H:i:s'));
    }

    public function test_to_storage_converts_to_utc(): void
    {
        $biz = Carbon::parse('2026-10-14 09:00:00', 'Asia/Jakarta');
        $storage = BusinessTime::toStorage($biz);

        $this->assertSame('UTC', $storage->getTimezone()->getName());
        $this->assertSame('2026-10-14 02:00:00', $storage->format('Y-m-d H:i:s'));
    }

    public function test_parse_date_creates_start_of_day_in_business_timezone(): void
    {
        $parsed = BusinessTime::parseDate('2026-10-14');

        $this->assertSame('Asia/Jakarta', $parsed->getTimezone()->getName());
        $this->assertSame('2026-10-14 00:00:00', $parsed->format('Y-m-d H:i:s'));
    }

    public function test_format_presents_datetime_with_or_without_label(): void
    {
        $utc = Carbon::parse('2026-10-14 02:00:00', 'UTC');

        $formattedWithLabel = BusinessTime::format($utc, 'd M Y H:i', true);
        $this->assertSame('14 Oct 2026 09:00 WIB', $formattedWithLabel);

        $formattedWithoutLabel = BusinessTime::format($utc, 'd M Y H:i', false);
        $this->assertSame('14 Oct 2026 09:00', $formattedWithoutLabel);

        $this->assertSame('—', BusinessTime::format(null));
        $this->assertSame('—', BusinessTime::format(''));
    }

    public function test_blade_directive_bizdt(): void
    {
        $utc = Carbon::parse('2026-10-14 02:00:00', 'UTC');

        $rendered = Blade::render('@bizdt($at)', ['at' => $utc]);
        $this->assertSame('14 Oct 2026 09:00 WIB', $rendered);

        $renderedNull = Blade::render('@bizdt($at)', ['at' => null]);
        $this->assertSame('—', $renderedNull);
    }
}
