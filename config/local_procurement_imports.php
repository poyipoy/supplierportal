<?php

return [
    'max_rows' => 70000,
    'max_file_kib' => 51200,
    'max_expanded_bytes' => 512 * 1024 * 1024,
    'max_zip_entries' => 1000,
    'batch_size' => 500,
    'page_size' => 100,
    'active_per_user' => 2,
    'preview_hours' => 24,
    'retention_days' => 3,
    'queue' => 'imports',
    'timeout_seconds' => 300,
    'commit_seconds' => 60,
];
