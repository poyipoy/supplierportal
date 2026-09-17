<?php

namespace App\Services\Sso;

use App\Models\SupplierIdentityProvider;
use Illuminate\Support\Str;

class SsoDomainResolver
{
    public function resolve(string $email): ?SupplierIdentityProvider
    {
        $email = Str::lower(trim($email));

        if (! str_contains($email, '@')) {
            return null;
        }

        return SupplierIdentityProvider::findActiveForEmail($email);
    }
}
