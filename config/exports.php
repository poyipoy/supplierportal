<?php

return [
    'max_rows' => 100000,
    'max_concurrent_per_user' => 5,
    'max_presets_per_key' => 20,
    'csv' => [
        'delimiter' => ',',
        'enclosure' => '"',
        'use_bom' => true,
        'input_encoding' => 'UTF-8',
        'output_encoding' => 'UTF-8',
    ],
];
