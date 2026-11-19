<?php

namespace App\Support;

/**
 * ISO 3166-1 alpha-2 country catalog backed by config/country_timezones.php.
 * Resolves localized names via ext-intl when available, with safe fallback to
 * the ISO code or legacy free-text values stored before standardization.
 */
final class CountryCatalog
{
    /** @var array<string, array<string, string>> */
    private static array $reverseLookupCache = [];

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(config('country_timezones', []));
    }

    public static function isValidCode(?string $code): bool
    {
        if ($code === null || $code === '') {
            return false;
        }

        return array_key_exists(strtoupper($code), config('country_timezones', []));
    }

    /**
     * Resolve an ISO alpha-2 code or known country name/alias (EN/ID/common) to
     * its canonical uppercase ISO-2 code, or null when unmapped.
     */
    public static function normalizeCode(?string $value): ?string
    {
        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return null;
        }

        $upper = strtoupper($trimmed);
        if (strlen($upper) === 2 && self::isValidCode($upper)) {
            return $upper;
        }

        $key = mb_strtolower($trimmed, 'UTF-8');
        $lookup = self::reverseLookupMap();

        return $lookup[$key] ?? null;
    }

    /**
     * @return array<string, string> lowercase name/alias => ISO alpha-2 code
     */
    private static function reverseLookupMap(): array
    {
        if (self::$reverseLookupCache !== []) {
            return self::$reverseLookupCache['all'] ?? [];
        }

        $map = [];
        foreach (self::codes() as $code) {
            foreach (['en', 'id'] as $locale) {
                $label = self::labelForCode($code, $locale);
                $normalized = mb_strtolower(trim($label), 'UTF-8');
                if ($normalized !== '' && ! isset($map[$normalized])) {
                    $map[$normalized] = $code;
                }
            }
        }

        $aliases = [
            'china' => 'CN',
            'prc' => 'CN',
            "people's republic of china" => 'CN',
            'tiongkok' => 'CN',
            'rrc' => 'CN',
            'japan' => 'JP',
            'jepang' => 'JP',
            'south korea' => 'KR',
            'republic of korea' => 'KR',
            'korea selatan' => 'KR',
            'taiwan' => 'TW',
            'hong kong' => 'HK',
            'usa' => 'US',
            'u.s.a.' => 'US',
            'united states' => 'US',
            'united states of america' => 'US',
            'amerika serikat' => 'US',
            'uk' => 'GB',
            'u.k.' => 'GB',
            'united kingdom' => 'GB',
            'great britain' => 'GB',
            'inggris' => 'GB',
            'uae' => 'AE',
            'united arab emirates' => 'AE',
            'uni emirat arab' => 'AE',
            'vietnam' => 'VN',
            'viet nam' => 'VN',
            'singapore' => 'SG',
            'singapura' => 'SG',
            'germany' => 'DE',
            'jerman' => 'DE',
            'indonesia' => 'ID',
            'malaysia' => 'MY',
            'thailand' => 'TH',
            'india' => 'IN',
        ];

        foreach ($aliases as $alias => $code) {
            if (self::isValidCode($code)) {
                $map[$alias] = $code;
            }
        }

        self::$reverseLookupCache['all'] = $map;

        return $map;
    }

    /**
     * @return array<string, string> ISO alpha-2 => localized display name
     */
    public static function options(?string $locale = null): array
    {
        $locale = $locale ?: app()->getLocale();
        $countries = [];

        foreach (self::codes() as $code) {
            $countries[$code] = self::labelForCode($code, $locale);
        }

        asort($countries, SORT_NATURAL | SORT_FLAG_CASE);

        return $countries;
    }

    /**
     * Display label for a stored country value. Known ISO codes render localized;
     * legacy free-text values (e.g. "Japan") pass through unchanged.
     */
    public static function label(?string $value, ?string $locale = null): string
    {
        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return '-';
        }

        $upper = strtoupper($trimmed);
        if (strlen($upper) === 2 && self::isValidCode($upper)) {
            return self::labelForCode($upper, $locale ?: app()->getLocale());
        }

        return $trimmed;
    }

    private static function labelForCode(string $code, string $locale): string
    {
        $name = class_exists(\Locale::class) ? \Locale::getDisplayRegion('-'.$code, $locale) : '';

        return is_string($name) && $name !== '' && $name !== $code ? $name : $code;
    }
}
