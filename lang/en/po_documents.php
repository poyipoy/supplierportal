<?php

return [
    'configuration' => 'Asynchronous PO processing is unavailable until hosting capacity is verified.',
    'capacity' => 'PO document processing capacity is unavailable. Try again later.',
    'storage_limit' => 'The ZIP and its extracted contents require more storage than the configured limit or available capacity. Reduce the size or number of PDFs and upload again. If the problem persists, contact an administrator. No new PO documents were published.',
    'changed' => 'The PO or source document changed. Upload the correct documents again.',
    'rejected' => 'The batch was rejected. No new PO documents were published.',
    'failed' => 'The batch failed. No new PO documents were published.',
    'timeout' => 'The processing time budget was exceeded.',
    'retry' => 'Retry processing',
    'progress' => 'PO document processing progress',
    'uploading' => 'Uploading. Document publication has not completed.',
    'offline' => 'Status is temporarily unavailable. Reopen this page to resume.',
    'states' => [
        'PENDING' => 'Queued for native validation.',
        'PROCESSING' => 'Validating PO documents.',
        'VALIDATED' => 'Native validation passed. Preparing publication.',
        'PUBLISHING' => 'Publishing the complete batch.',
        'COMPLETED' => 'All PO documents were published.',
        'REJECTED' => 'The batch was rejected. No documents were published.',
        'FAILED' => 'Processing failed. No documents were published.',
    ],
];
