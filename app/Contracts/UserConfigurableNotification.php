<?php

namespace App\Contracts;

interface UserConfigurableNotification
{
    public function preferenceKey(): string;
}
