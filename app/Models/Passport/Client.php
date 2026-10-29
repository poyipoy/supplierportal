<?php

namespace App\Models\Passport;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Casts\Attribute;

class Client extends \Laravel\Passport\Client
{
    public function skipsAuthorization(Authenticatable $user, array $scopes): bool
    {
        $clientId = (string) config('sso.client_id');

        return $clientId !== '' && (string) $this->getKey() === $clientId;
    }

    public function redirectUris(): Attribute
    {
        return Attribute::make(
            get: function (mixed $value): array {
                $uris = is_string($value) ? (json_decode($value, true) ?: []) : (is_array($value) ? $value : []);
                if (! is_array($uris)) {
                    $uris = [];
                }

                if (! app()->isProduction() && $this->isPortalFirstPartyClient()) {
                    $localUris = [
                        'http://localhost:8000/sso/callback',
                        'http://127.0.0.1:8000/sso/callback',
                        'http://localhost/sso/callback',
                        'http://127.0.0.1/sso/callback',
                        'http://localhost:8000',
                    ];

                    if (app()->bound('request')) {
                        $request = request();
                        $host = $request->getHost();
                        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)
                            || str_ends_with($host, '.test')
                            || str_ends_with($host, '.local')) {
                            $localUris[] = $request->schemeAndHttpHost().'/sso/callback';
                        }
                    }

                    $uris = array_values(array_unique(array_merge($uris, $localUris)));
                }

                return $uris;
            },
            set: fn (mixed $value): string => is_string($value) ? $value : json_encode(array_values((array) $value)),
        );
    }

    public function isPortalFirstPartyClient(): bool
    {
        $configuredId = (string) config('sso.client_id');

        return ($configuredId !== '' && (string) $this->getKey() === $configuredId)
            || ($this->name === 'ADASI Portal' && ! $this->confidential());
    }
}
