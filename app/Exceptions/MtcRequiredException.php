<?php

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * Mission 05 (I-9): a shipment cannot be submitted without at least one
 * VALIDATED Mill Test Certificate. Extends InvalidArgumentException so every
 * existing submit caller keeps mapping it to a user-facing message.
 */
class MtcRequiredException extends InvalidArgumentException
{
    public function __construct()
    {
        parent::__construct(__('shipments.mtc.required_to_submit'));
    }
}
