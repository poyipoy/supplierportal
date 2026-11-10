<?php

namespace App\Exceptions;

use App\Enums\LocalInvoiceStatus;

/**
 * Thrown when a domain model attempts an illegal state transition S -> S'.
 * Extends BusinessRuleException so existing HTTP 422 / flash error handlers
 * and feature tests continue to catch it seamlessly.
 */
class InvalidStateTransitionException extends BusinessRuleException
{
    public function __construct(
        public readonly string $domain,
        public readonly string $fromStatus,
        public readonly string $toStatus,
        string $message = '',
    ) {
        parent::__construct(
            $message !== ''
                ? $message
                : sprintf('Illegal %s state transition from [%s] to [%s].', $domain, $fromStatus, $toStatus)
        );
    }

    public static function forLocalInvoice(
        LocalInvoiceStatus|string $from,
        LocalInvoiceStatus|string $target,
        ?string $customMessage = null
    ): self {
        $fromValue = $from instanceof LocalInvoiceStatus ? $from->value : $from;
        $toValue = $target instanceof LocalInvoiceStatus ? $target->value : $target;

        return new self(
            domain: 'LocalInvoice',
            fromStatus: $fromValue,
            toStatus: $toValue,
            message: $customMessage ?? sprintf('Illegal LocalInvoice transition from [%s] to [%s].', $fromValue, $toValue),
        );
    }
}
