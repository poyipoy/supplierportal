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
                $values = array_replace_recursive($english, is_array($current) ? $current : []);
                if ($domain === 'js') {
                    $values = array_filter($values, fn ($key) => ! str_starts_with($key, 'local_import.'), ARRAY_FILTER_USE_KEY);
                }
                self::flatten($domain, $values, $messages);
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

    /** Import-only copy is delivered once on the import page, outside the shared dictionary. */
    public static function localImportPayload(): array
    {
        $english = trans('js', [], 'en');
        $current = trans('js');
        $values = array_replace_recursive(is_array($english) ? $english : [], is_array($current) ? $current : []);
        $messages = [];
        self::flatten('js', array_filter($values, fn ($key) => str_starts_with($key, 'local_import.'), ARRAY_FILTER_USE_KEY), $messages);

        return $messages;
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
