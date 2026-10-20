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

                        return $client && $this->matches($client, $redirect) ? $client : null;
                    }

                    $candidates = Passport::client()->newQuery()->where('name', 'ADASI Portal')
                        ->where('revoked', false)->whereNull('secret')->whereNull('owner_id')
                        ->whereJsonContains('redirect_uris', $redirect)->limit(2)->get();
                    if ($candidates->count() > 1) {
                        return null;
                    }
                    if ($candidates->isNotEmpty()) {
                        return $this->matches($candidates->first(), $redirect) ? $candidates->first() : null;
                    }

                    return $clients->createAuthorizationCodeGrantClient('ADASI Portal', [$redirect], confidential: false);
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
        return ! $client->revoked && ! $client->confidential() && $client->firstParty()
            && $client->redirect_uris === [$redirect]
            && $client->grant_types === ['authorization_code', 'refresh_token'];
    }
}
