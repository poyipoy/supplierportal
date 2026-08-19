<?php

namespace App\Enums;

enum TurnstileStatus: string
{
    case Disabled = 'disabled';
    case Passed = 'passed';
    case Invalid = 'invalid';
    case ProviderError = 'provider_error';
}
