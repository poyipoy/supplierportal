<?php

namespace App\Events;

use App\Models\User;

class AuthSecurityEvent
{
    public function __construct(
        public readonly string $event,
        public readonly ?User $user = null,
        public readonly ?string $email = null,
        public readonly array $metadata = [],
    ) {}
}
