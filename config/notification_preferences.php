<?php

use App\Notifications\LocalInvoice\InvoiceSubmissionReceivedNotification;

return [
    'local_invoice_submission_received' => [
        'class' => InvoiceSubmissionReceivedNotification::class,
        'label' => 'Invoice submission confirmations',
        'description' => 'Email confirmation when your local invoice is submitted or resubmitted.',
        'category' => 'Local invoices',
        'roles' => ['supplier'],
        'supplier_scopes' => ['local'],
        'channels' => [
            'mail' => ['default' => true, 'configurable' => true],
        ],
    ],
];
