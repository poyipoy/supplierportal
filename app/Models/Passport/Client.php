<?php

namespace App\Models\Passport;

use Illuminate\Contracts\Auth\Authenticatable;

class Client extends \Laravel\Passport\Client
{
    public function skipsAuthorization(Authenticatable $user, array $scopes): bool
    {
        $clientId = (string) config('sso.client_id');

        return $clientId !== '' && (string) $this->getKey() === $clientId;
    }
}
