<?php

namespace App\Auth;

use App\Services\Auth\RememberTokenService;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use SensitiveParameter;

class DeviceAwareUserProvider extends EloquentUserProvider
{
    public function retrieveByToken($identifier, #[SensitiveParameter] $token): ?Authenticatable
    {
        $rememberTokens = app(RememberTokenService::class);
        $matchedUser = $rememberTokens->resolveUserByToken($identifier, (string) $token);

        if ($matchedUser !== null) {
            return $matchedUser;
        }

        // Legacy fallback for pre-migration sessions or direct remember_token fixtures.
        return parent::retrieveByToken($identifier, $token);
    }
}
