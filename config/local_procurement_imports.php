<?php

return [
    'max_rows' => 70000,
    'fast_path_max_rows' => (int) env('LOCAL_PROCUREMENT_FAST_PATH_MAX_ROWS', 2000),
    'fast_path_commit_max_records' => (int) env('LOCAL_PROCUREMENT_FAST_PATH_COMMIT_MAX_RECORDS', 1500),
    'max_file_kib' => 10240,
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
