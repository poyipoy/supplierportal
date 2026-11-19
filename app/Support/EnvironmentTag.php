<?php

namespace App\Support;

class EnvironmentTag
{
    private const PRODUCTION_ALIASES = ['production', 'prod', 'prd'];

    /**
     * Resolve the non-production environment badge. Fail-closed: always null in production.
     *
     * @return array{code: string, name: string, tone: string, tooltip: string}|null
     */
    public static function resolve(): ?array
    {
        $rawEnv = strtolower(trim((string) config('app.env', 'production')));

        if ($rawEnv === '' || in_array($rawEnv, self::PRODUCTION_ALIASES, true)) {
            return null;
        }

        if (! (bool) config('ui.environment_tag.enabled', true)) {
            return null;
        }

        $customLabel = self::sanitizeCode(substr(trim((string) config('ui.environment_tag.label', '')), 0, 16));

        [$defaultCode, $nameKey, $tone] = match ($rawEnv) {
            'local', 'loc' => ['LOCAL', 'navigation.environment_tag.local', 'info'],
            'development', 'dev' => ['DEV', 'navigation.environment_tag.development', 'info'],
            'staging', 'stg', 'stage' => ['STG', 'navigation.environment_tag.staging', 'warning'],
            'uat', 'preprod', 'demo', 'dem' => ['UAT', 'navigation.environment_tag.uat', 'warning'],
            'testing', 'test', 'tst', 'qa' => ['TST', 'navigation.environment_tag.testing', 'neutral'],
            default => [
                self::sanitizeCode(substr($rawEnv, 0, 8)) ?: 'NON-PROD',
                'navigation.environment_tag.non_production',
                'warning',
            ],
        };

        $code = $customLabel !== '' ? $customLabel : $defaultCode;
        $name = __($nameKey);

        return [
            'code' => $code,
            'name' => $name,
            'tone' => $tone,
            'tooltip' => __('navigation.environment_tag.tooltip', ['env' => $name, 'code' => $code]),
        ];
    }

    private static function sanitizeCode(string $value): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9_-]/', '', $value));
    }
}
