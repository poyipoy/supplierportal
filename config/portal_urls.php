<?php

return [
    'long_hashids' => [
        'enabled' => (bool) env('PORTAL_LONG_HASHIDS_ENABLED', false),
        'length' => (int) env('PORTAL_LONG_HASHIDS_LENGTH', 24),
    ],
    'prefix' => [
        'enabled' => (bool) env('PORTAL_PATH_PREFIX_ENABLED', false),
        'value' => env('PORTAL_PATH_PREFIX', 'v2/ADASI_PRD'),
        // Keep previously deployed prefixes here before changing prefix.value.
        'legacy_values' => ['v2/ADASI_PRD'],
    ],
    'page_registry' => [
        'enabled' => (bool) env('PORTAL_PAGE_REGISTRY_ENABLED', false),
    ],
    'context_urls' => [
        'enabled' => (bool) env('PORTAL_CONTEXT_URLS_ENABLED', false),
        'app_uuid' => strtolower((string) env('PORTAL_CONTEXT_APP_UUID', '934f47ee-06a4-4f2d-a619-e7cf35e9d91b')),
        'logical_id_prefix' => 'lid://adasi.portal.',
    ],
];
