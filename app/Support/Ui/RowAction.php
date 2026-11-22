<?php

namespace App\Support\Ui;

/**
 * Canonical HTML builder and shared attribute resolver for table row action controls.
 */
final class RowAction
{
    private const ALLOWED_TONES = ['primary', 'neutral', 'danger', 'warning', 'success'];

    /**
     * Render a standardized primary Details link for a DataTables row.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function detail(string $url, ?string $context = null, array $attributes = []): string
    {
        return self::link(
            $url,
            __('common.actions.details'),
            'primary',
            $context,
            $attributes
        );
    }

    /**
     * Render a standardized link row action with strict URL scheme validation and XSS escaping.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function link(
        string $url,
        string $label,
        string $tone = 'primary',
        ?string $context = null,
        array $attributes = []
    ): string {
        $extraClass = isset($attributes['class']) ? (string) $attributes['class'] : null;
        $explicitAriaLabel = isset($attributes['aria-label']) ? (string) $attributes['aria-label'] : null;
        unset($attributes['class'], $attributes['aria-label'], $attributes['href']);

        $safeUrl = self::sanitizeUrl($url);
        $classAttr = self::classesForTone($tone, $extraClass);
        $isDefaultDetails = $label === __('common.actions.details');
        $ariaLabel = self::resolveAriaLabel($label, $context, $explicitAriaLabel, $isDefaultDetails);

        if (($attributes['target'] ?? null) === '_blank' && ! array_key_exists('rel', $attributes)) {
            $attributes['rel'] = 'noopener';
        }

        $parts = [
            'href="'.e($safeUrl).'"',
            'class="'.e($classAttr).'"',
        ];

        if ($ariaLabel !== null) {
            $parts[] = 'aria-label="'.e($ariaLabel).'"';
        }

        foreach ($attributes as $key => $value) {
            $attrName = (string) $key;
            if (! preg_match('/^[a-zA-Z_:][a-zA-Z0-9_.:-]*$/', $attrName)) {
                continue;
            }
            if ($value === null || $value === false) {
                continue;
            }
            if ($value === true) {
                $parts[] = e($attrName);

                continue;
            }
            $parts[] = e($attrName).'="'.e((string) $value).'"';
        }

        return '<a '.implode(' ', $parts).'>'.e($label).'</a>';
    }

    /**
     * Resolve canonical CSS classes for a row action tone.
     */
    public static function classesForTone(string $tone = 'primary', ?string $extraClasses = null): string
    {
        $normalizedTone = in_array($tone, self::ALLOWED_TONES, true) ? $tone : 'neutral';
        $classes = ['ui-data-action'];

        if ($normalizedTone !== 'neutral') {
            $classes[] = 'ui-data-action--'.$normalizedTone;
        }

        $classes[] = 'ui-focus-ring';

        if ($extraClasses !== null && trim($extraClasses) !== '') {
            $classes[] = trim($extraClasses);
        }

        return implode(' ', $classes);
    }

    /**
     * Build WCAG 2.2 SC 2.5.3 compliant aria-label starting with visible button text.
     */
    public static function resolveAriaLabel(
        string $visibleLabel,
        ?string $context = null,
        ?string $explicitAriaLabel = null,
        bool $isDefaultDetailsLabel = false
    ): ?string {
        if ($explicitAriaLabel !== null && trim($explicitAriaLabel) !== '') {
            return trim($explicitAriaLabel);
        }

        if ($context === null || trim($context) === '') {
            return null;
        }

        $trimmedContext = trim($context);

        if ($isDefaultDetailsLabel) {
            return __('common.actions.details_for', ['name' => $trimmedContext]);
        }

        return trim($visibleLabel).' — '.$trimmedContext;
    }

    /**
     * Permit only root-relative paths or explicit http(s) URLs; reject dangerous schemes.
     */
    public static function sanitizeUrl(?string $url): string
    {
        if ($url === null) {
            return '#';
        }

        $trimmed = trim($url);
        if ($trimmed === '') {
            return '#';
        }

        $normalized = preg_replace('/[\x00-\x20\x7F]+/', '', $trimmed) ?? '';
        if ($normalized === '') {
            return '#';
        }

        if (str_starts_with($normalized, '/') && ! str_starts_with($normalized, '//')) {
            return $trimmed;
        }

        if (preg_match('#^https?://#i', $normalized)) {
            $parsed = parse_url($trimmed);
            $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
            if ($parsed !== false && in_array($scheme, ['http', 'https'], true) && ! empty($parsed['host'])) {
                return $trimmed;
            }
        }

        return '#';
    }
}
