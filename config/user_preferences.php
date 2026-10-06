<?php

return [
    'defaults' => ['theme' => 'system', 'density' => 'comfortable', 'sidebar_state' => 'expanded', 'page_size' => 25, 'quick_access' => [], 'accent' => 'brand', 'dashboard_preferences' => [], 'sidebar_revision' => 1, 'timezone' => 'system', 'date_format' => 'system', 'time_format' => 'system', 'number_format' => 'system', 'locale' => 'en'],
    'locales' => ['en' => 'English', 'id' => 'Bahasa Indonesia'],
    'accents' => [
        'brand' => 'ADASI Blue',
        'slate' => 'Slate',
        'indigo' => 'Indigo',
        'teal' => 'Teal',
        'violet' => 'Violet',
    ],
    'themes' => ['light', 'dark', 'system'],
    'densities' => ['comfortable', 'compact'],
    'sidebar_states' => ['expanded', 'collapsed'],
    'page_sizes' => [10, 25, 50, 100],
    'quick_access_limit' => 6,
];
