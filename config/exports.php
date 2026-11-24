<?php

return [
    'list_writer' => env('EXPORTS_LIST_WRITER', 'openspout'),
    'stream_chunk_size' => 500,
    'max_rows' => 100000,
    'max_concurrent_per_user' => 5,
    'max_presets_per_key' => 20,
    'team_preset_manager_roles' => ['admin', 'purchasing', 'finance', 'qc'],
    'csv' => [
        'delimiter' => ',',
        'enclosure' => '"',
        'use_bom' => true,
        'input_encoding' => 'UTF-8',
        'output_encoding' => 'UTF-8',
    ],
];
