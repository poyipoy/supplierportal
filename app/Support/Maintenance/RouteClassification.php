<?php

namespace App\Support\Maintenance;

/**
 * How a non-admin request relates to maintenance: never blocked, blocked only by
 * Global Maintenance (portal navigation), or owned by one or more modules.
 */
final class RouteClassification
{
    public const ALWAYS = 'always';

    public const PORTAL = 'portal';

    public const MODULE = 'module';

    public const UNMAPPED = 'unmapped';

    /** @param list<string> $modules */
    private function __construct(public readonly string $kind, public readonly array $modules = []) {}

    public static function alwaysAllowed(): self
    {
        return new self(self::ALWAYS);
    }

    public static function portal(): self
    {
        return new self(self::PORTAL);
    }

    /** @param list<string> $modules */
    public static function modules(array $modules): self
    {
        return new self(self::MODULE, array_values(array_unique($modules)));
    }

    public static function unmapped(): self
    {
        return new self(self::UNMAPPED);
    }

    public function isAlwaysAllowed(): bool
    {
        return $this->kind === self::ALWAYS;
    }
}
