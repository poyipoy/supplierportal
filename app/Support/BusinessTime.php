<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

final class BusinessTime
{
    private const LABELS = [
        'Asia/Jakarta' => 'WIB',
        'Asia/Makassar' => 'WITA',
        'Asia/Jayapura' => 'WIT',
    ];

    public static function tz(): string
    {
        return config('app.business_timezone', 'Asia/Jakarta');
    }

    public static function label(): string
    {
        return self::LABELS[self::tz()] ?? self::now()->format('T');
    }

    /**
     * Sekarang, dalam zona bisnis. Memakai now() agar menghormati travelTo()/setTestNow() di test.
     */
    public static function now(): CarbonImmutable
    {
        return now()->toImmutable()->setTimezone(self::tz());
    }

    /**
     * Awal hari ini menurut kalender zona bisnis. Pengganti today().
     */
    public static function today(): CarbonImmutable
    {
        return self::now()->startOfDay();
    }

    /**
     * Timestamp storage (UTC) atau string DB -> zona bisnis.
     */
    public static function toBusiness(DateTimeInterface|string $at): CarbonImmutable
    {
        $instance = $at instanceof DateTimeInterface
            ? CarbonImmutable::instance($at)
            : CarbonImmutable::parse($at, config('app.timezone'));

        return $instance->setTimezone(self::tz());
    }

    /**
     * Tanggal kalender 'Y-m-d' -> awal hari di zona bisnis (untuk dibandingkan dengan today()).
     */
    public static function parseDate(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, self::tz())->startOfDay();
    }

    /**
     * Wajib dipakai saat mem-bind Carbon ke kolom timestamp pada query (lihat D-5).
     */
    public static function toStorage(DateTimeInterface $at): CarbonImmutable
    {
        return CarbonImmutable::instance($at)->setTimezone(config('app.timezone'));
    }

    /**
     * Format untuk tampilan. Hanya untuk kolom datetime, JANGAN dipakai pada kolom date.
     */
    public static function format(DateTimeInterface|string|null $at, string $format = 'd M Y H:i', bool $withLabel = true): string
    {
        if ($at === null || $at === '') {
            return '—';
        }

        $text = self::toBusiness($at)->translatedFormat($format);

        return $withLabel ? $text.' '.self::label() : $text;
    }
}
