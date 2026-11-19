<?php

namespace App\Exceptions;

class CommercialInvoiceRequiredException extends BusinessRuleArgumentException
{
    public function __construct(?string $message = null)
    {
        parent::__construct($message ?: __('shipments.copy.commercial_invoice_required_before_submit'));
    }
}
