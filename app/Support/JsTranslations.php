<?php

namespace App\Support;

final class JsTranslations
{
    /** Only application-controlled JS copy is exposed, never business data. */
    public static function payload(): array
    {
        $locale = app()->getLocale() === 'id' ? 'id' : 'en';
        $messages = [];
        foreach (['js', 'datatables'] as $domain) {
            $english = trans($domain, [], 'en');
            $current = trans($domain, [], $locale);
            if (is_array($english)) {
                self::flatten($domain, array_replace_recursive($english, is_array($current) ? $current : []), $messages);
            }
        }
        foreach (['purchasing', 'supplier', 'shipments'] as $domain) {
            $english = trans($domain.'.js', [], 'en');
            $current = trans($domain.'.js', [], $locale);
            if (is_array($english)) {
                self::flatten($domain.'.js', array_replace_recursive($english, is_array($current) ? $current : []), $messages);
            }
        }

        return ['locale' => $locale, 'messages' => $messages];
    }

    private static function flatten(string $prefix, array $values, array &$messages): void
    {
        foreach ($values as $key => $value) {
            $path = $prefix.'.'.$key;
            if (is_string($value)) {
                $messages[$path] = $value;
            } elseif (is_array($value) && isset($value['zero'], $value['one'], $value['other'])) {
                $messages[$path] = $value;
            } elseif (is_array($value)) {
                self::flatten($path, $value, $messages);
            }
        }
    }
}
