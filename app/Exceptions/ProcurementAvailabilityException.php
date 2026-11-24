<?php

namespace App\Exceptions;

use DomainException;

class ProcurementAvailabilityException extends DomainException
{
    public const CODE_PR_CANCELLED = 'pr_cancelled';

    public const CODE_INVITATION_CANCELLED = 'invitation_cancelled';

    public const CODE_PR_UNAVAILABLE = 'pr_unavailable';

    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
