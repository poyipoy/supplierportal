<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;

class TrustProxies extends Middleware
{
    /**
     * Resolve validated IP/CIDR proxies from cached configuration rather than
     * calling env() during bootstrap/app.php registration.
     *
     * @return array<int, string>|string|null
     */
    protected function proxies()
    {
        $explicit = parent::proxies();
        if ($explicit !== null && $explicit !== [] && $explicit !== '') {
            return $explicit;
        }

        $configured = self::parseConfiguredProxies(config('auth_security.trusted_proxies', ''));

        return $configured !== [] ? $configured : null;
    }

    /**
     * Accept only explicit IPv4/IPv6 addresses or valid CIDR ranges; wildcards are rejected.
     *
     * @return list<string>
     */
    public static function parseConfiguredProxies(mixed $raw): array
    {
        if (is_string($raw) && trim($raw) === '') {
            return [];
        }

        $entries = is_array($raw)
            ? $raw
            : explode(',', is_string($raw) ? $raw : '');

        $validated = [];
        foreach ($entries as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            $proxy = trim($entry);
            if ($proxy === '') {
                continue;
            }

            [$address, $prefix] = array_pad(explode('/', $proxy, 2), 2, null);

            if (filter_var($address, FILTER_VALIDATE_IP) === false) {
                continue;
            }

            if ($prefix !== null) {
                $maximum = str_contains($address, ':') ? 128 : 32;
                if (! ctype_digit($prefix) || (int) $prefix > $maximum) {
                    continue;
                }
            }

            $validated[] = $proxy;
        }

        return array_values(array_unique($validated));
    }
}
