<?php

return [
    'long_hashids' => [
        'enabled' => (bool) env('PORTAL_LONG_HASHIDS_ENABLED', false),
        'length' => (int) env('PORTAL_LONG_HASHIDS_LENGTH', 24),
    ],
    'prefix' => [
        'enabled' => (bool) env('PORTAL_PATH_PREFIX_ENABLED', false),
        'value' => env('PORTAL_PATH_PREFIX', 'v2/ADASI_TEST'),
        // Keep previously deployed prefixes here before changing prefix.value.
        'legacy_values' => ['v2/ADASI_PROD'],
    ],
    'page_registry' => [
        'enabled' => (bool) env('PORTAL_PAGE_REGISTRY_ENABLED', false),
    ],
];
