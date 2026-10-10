<?php

return [
    // Enable only after approving measured cPanel capacity and a supervised worker.
    'async_enabled' => false,
    'host_verified' => false,
    'queue' => 'po-documents',
    'runtime_seconds' => null,
    'slice_seconds' => null,
    'disk_bytes' => null,
    'reserve_bytes' => null,
    'max_active' => null,
    'max_queued_per_user' => null,
    'max_backlog' => null,
    'max_queue_seconds' => null,
    'retry_limit' => null,
    'timeout_seconds' => null,
    'lease_seconds' => null,
    'retention_seconds' => null,
];
