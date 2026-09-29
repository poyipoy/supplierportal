<?php

return [
    'timezones' => [
        'system' => ['label' => 'System (existing display)', 'example' => 'Keeps the timezone already used by each supported dashboard.'],
        'Asia/Jakarta' => ['label' => 'Jakarta — WIB (UTC+07:00)', 'example' => '28 Sep 2026 23:35 UTC → 29 Sep 2026 06:35 WIB', 'zone_label' => 'WIB'],
    ],
    'date_formats' => [
        'system' => ['label' => 'System (existing display)', 'example' => 'Keeps the current calendar date presentation.'],
        'human' => ['label' => 'Human readable', 'example' => '28 Sep 2026', 'format' => 'd M Y'],
        'dmy' => ['label' => 'Day / Month / Year', 'example' => '28/09/2026', 'format' => 'd/m/Y'],
        'iso' => ['label' => 'Year - Month - Day', 'example' => '2026-09-28', 'format' => 'Y-m-d'],
    ],
    'time_formats' => [
        'system' => ['label' => 'System (existing display)', 'example' => 'Keeps the current time presentation.'],
        '24h' => ['label' => '24-hour clock', 'example' => '14:35', 'format' => 'H:i'],
        '12h' => ['label' => '12-hour clock', 'example' => '2:35 PM', 'format' => 'g:i A'],
    ],
    'number_formats' => [
        'system' => ['label' => 'System (existing display)', 'example' => 'Keeps existing separators and decimal precision.'],
        'international' => ['label' => 'International — comma grouping, decimal point', 'example' => '1,250,000.50'],
        'indonesian' => ['label' => 'Indonesian — dot grouping, decimal comma', 'example' => '1.250.000,50'],
    ],
    'date_profiles' => [
        'human' => 'd M Y',
        'iso' => 'Y-m-d',
        'full_human' => 'd F Y',
        'dmy' => 'd/m/Y',
    ],
    'timestamp_profiles' => [
        'date' => ['date' => 'd M Y', 'time' => null, 'separator' => ''],
        'datetime' => ['date' => 'd M Y', 'time' => 'H:i', 'separator' => ' '],
        'datetime_comma' => ['date' => 'd M Y', 'time' => 'H:i', 'separator' => ', '],
    ],
    'number_profiles' => [
        'international' => ['decimal' => '.', 'group' => ','],
        'indonesian' => ['decimal' => ',', 'group' => '.'],
        'plain' => ['decimal' => '.', 'group' => ''],
        'decimal' => ['decimal' => '.', 'group' => ''],
    ],
    'months' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
];
