<?php

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * Thrown by the PO generation choke point while the procurement kill switch
 * is off. It extends InvalidArgumentException so every existing caller maps it
 * to a user-facing message and rolls back its enclosing transaction.
 */
final class PoGenerationDisabledException extends InvalidArgumentException
{
    public function __construct()
    {
        parent::__construct(__('procurement.po_generation.disabled'));
    }
}
