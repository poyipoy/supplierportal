<?php

namespace App\Services\Auth\Sso;

use App\Services\Auth\TwoFactorService;
use App\Support\PortalPath;
use Illuminate\Http\Request;
use RuntimeException;

class SsoTransactionStore
{
    private const SESSION_KEY = 'sso.transactions';

    public function begin(Request $request, ?string $intended = null): string
    {
        $prefix = (string) config('sso.state_prefix', 'ADASI');
        $clientId = (string) config('sso.client_id');
        if (! preg_match('/\A[A-Za-z0-9_-]{1,32}\z/', $prefix) || $clientId === '') {
            throw new RuntimeException('SSO configuration is incomplete.');
        }

        $transactions = $this->pending($request);
        $maximum = max(1, (int) config('sso.max_pending_transactions', 5));
        while (count($transactions) >= $maximum) {
            array_shift($transactions);
        }

        $state = $prefix.'~'.$this->base64Url(random_bytes(48));
        $verifier = $this->base64Url(random_bytes(64));
        $redirectUri = route('sso.callback');
        $transactions[$state] = [
            'verifier' => $verifier,
            'intended' => $this->safeIntendedUrl($request, $intended),
            'created_at' => now()->timestamp, // biz-time:ignore transaction expiry is an instant
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
        ];
        $request->session()->put(self::SESSION_KEY, $transactions);
        $request->session()->put('sso.intended', $transactions[$state]['intended']);

        return route('passport.authorizations.authorize').'?'.http_build_query([
            'client_id' => $clientId,
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'scope' => config('sso.scope', 'openid'),
            'state' => $state,
            'code_challenge' => $this->base64Url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function pull(Request $request, mixed $state): ?array
    {
        $transactions = $request->session()->get(self::SESSION_KEY, []);
        $transaction = null;
        if (is_string($state) && strlen($state) <= 128 && is_array($transactions)) {
            foreach ($transactions as $key => $candidate) {
                if (is_string($key) && hash_equals($key, $state)) {
                    unset($transactions[$key]);
                    $transaction = $candidate;
                    break;
                }
            }
            $request->session()->put(self::SESSION_KEY, $transactions);
        }
        $request->session()->put(self::SESSION_KEY, $this->pending($request));

        return $this->valid($transaction) ? $transaction : null;
    }

    public function safeIntendedUrl(Request $request, mixed $intended): string
    {
        $default = route('dashboard', absolute: false);
        if (! is_string($intended) || strlen($intended) > 4096) {
            return $default;
        }
        $decoded = $intended;
        for ($index = 0; $index < 3; $index++) {
            $decoded = rawurldecode($decoded);
            if (preg_match('/[\x00-\x20\\\\]/', $decoded)) {
                return $default;
            }
        }
        $parts = parse_url($decoded);
        if (! is_array($parts) || isset($parts['user']) || isset($parts['pass'])
            || str_starts_with($decoded, '//')) {
            return $default;
        }

        $safe = TwoFactorService::safeIntendedUrl($request, $intended, $default);
        $segments = [];
        foreach (explode('/', (string) parse_url($decoded, PHP_URL_PATH)) as $segment) {
            if ($segment === '..') {
                array_pop($segments);
            } elseif ($segment !== '' && $segment !== '.') {
                $segments[] = $segment;
            }
        }
        $portalPath = PortalPath::strip('/'.implode('/', $segments));
        $firstSegment = strtolower(explode('/', ltrim($portalPath, '/'), 2)[0]);
        if (in_array($firstSegment, [
            'oauth', 'sso', 'login', 'logout', 'two-factor-challenge', 'confirm-password',
        ], true)) {
            return $default;
        }

        return $safe;
    }

    private function pending(Request $request): array
    {
        $transactions = $request->session()->get(self::SESSION_KEY, []);

        return is_array($transactions) ? array_filter($transactions, $this->valid(...)) : [];
    }

    private function valid(mixed $transaction): bool
    {
        $now = now()->timestamp; // biz-time:ignore transaction expiry is an instant

        return is_array($transaction)
            && is_int($transaction['created_at'] ?? null)
            && $transaction['created_at'] > 0 && $transaction['created_at'] <= $now
            && $now < $transaction['created_at'] + (int) config('sso.transaction_ttl_seconds', 600)
            && is_string($transaction['verifier'] ?? null)
            && preg_match('/\A[A-Za-z0-9_-]{86}\z/', $transaction['verifier']) === 1
            && is_string($transaction['intended'] ?? null)
            && is_string($transaction['client_id'] ?? null)
            && is_string($transaction['redirect_uri'] ?? null);
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
