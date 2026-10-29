<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Throwable;

class SsoInstallClient extends Command
{
    protected $signature = 'sso:install-client';

    protected $description = 'Create or reuse the portal public authorization-code client without changing environment files';

    public function handle(ClientRepository $clients): int
    {
        $redirect = route('sso.callback');
        $parts = parse_url($redirect);
        if (! is_array($parts) || empty($parts['host'])
            || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || preg_match('/[\x00-\x20\\\\]/', $redirect)) {
            $this->error('Configure a valid APP_URL before installing the SSO client.');

            return self::FAILURE;
        }

        try {
            $client = Cache::lock('sso:install-client', 30)->block(5, function () use ($clients, $redirect): ?Client {
                return DB::transaction(function () use ($clients, $redirect): ?Client {
                    $configured = (string) config('sso.client_id');
                    if ($configured !== '') {
                        $client = $clients->findActive($configured);

                        if ($client && $this->matches($client, $redirect)) {
                            if (! app()->isProduction()) {
                                $this->syncLocalRedirectUris($client, $redirect);
                            }

                            return $client;
                        }

                        return null;
                    }

                    $candidates = Passport::client()->newQuery()->where('name', 'ADASI Portal')
                        ->where('revoked', false)->whereNull('secret')->whereNull('owner_id')
                        ->limit(2)->get();
                    if ($candidates->count() > 1) {
                        return null;
                    }
                    if ($candidates->isNotEmpty()) {
                        $candidate = $candidates->first();
                        if ($this->matches($candidate, $redirect)) {
                            if (! app()->isProduction()) {
                                $this->syncLocalRedirectUris($candidate, $redirect);
                            }

                            return $candidate;
                        }

                        return null;
                    }

                    $redirectUris = array_values(array_unique(array_filter([
                        $redirect,
                        ! app()->isProduction() ? 'http://localhost:8000/sso/callback' : null,
                        ! app()->isProduction() ? 'http://127.0.0.1:8000/sso/callback' : null,
                    ])));

                    return $clients->createAuthorizationCodeGrantClient('ADASI Portal', $redirectUris, confidential: false);
                });
            });
        } catch (Throwable) {
            // Never print database/cryptographic exception details or credentials.
            $this->error('Unable to install the SSO client. Check the portal database migrations and cache lock configuration.');

            return self::FAILURE;
        }

        if (! $client) {
            $this->error('The configured or existing client does not match the portal public client. Review its redirect URI, grants, ownership and revoked status.');

            return self::FAILURE;
        }

        $this->info('Portal SSO client is ready. Set this value in the environment:');
        $this->line('SSO_CLIENT_ID='.$client->getKey());

        return self::SUCCESS;
    }

    private function matches(Client $client, string $redirect): bool
    {
        $redirectMatches = app()->isProduction()
            ? $client->redirect_uris === [$redirect]
            : in_array($redirect, $client->redirect_uris, true);

        return ! $client->revoked && ! $client->confidential() && $client->firstParty()
            && $redirectMatches
            && $client->grant_types === ['authorization_code', 'refresh_token'];
    }

    private function syncLocalRedirectUris(Client $client, string $redirect): void
    {
        $raw = $client->getRawOriginal('redirect_uris');
        $stored = is_string($raw) ? (json_decode($raw, true) ?: []) : (is_array($raw) ? $raw : []);
        $merged = array_values(array_unique(array_filter([
            ...$stored,
            $redirect,
            'http://localhost:8000/sso/callback',
            'http://127.0.0.1:8000/sso/callback',
        ])));

        if ($merged !== $stored) {
            DB::table('oauth_clients')->where('id', $client->getKey())->update([
                'redirect_uris' => json_encode($merged),
            ]);
        }
    }
}
