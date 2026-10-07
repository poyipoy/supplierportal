<?php

return [
    'timezones' => [
        'system' => ['label' => 'customization.regional_choices.system', 'example' => 'customization.regional_choices.keep_timezone'],
        'Asia/Jakarta' => ['label' => 'Jakarta — WIB (UTC+07:00)', 'example' => '28 Sep 2026 23:35 UTC → 29 Sep 2026 06:35 WIB', 'zone_label' => 'WIB'],
    ],
    'date_formats' => [
        'system' => ['label' => 'customization.regional_choices.system', 'example' => 'customization.regional_choices.keep_date'],
        'human' => ['label' => 'customization.regional_choices.human', 'example' => '28 Sep 2026', 'format' => 'd M Y'],
        'dmy' => ['label' => 'customization.regional_choices.dmy', 'example' => '28/09/2026', 'format' => 'd/m/Y'],
        'iso' => ['label' => 'customization.regional_choices.iso', 'example' => '2026-09-28', 'format' => 'Y-m-d'],
    ],
    'time_formats' => [
        'system' => ['label' => 'customization.regional_choices.system', 'example' => 'customization.regional_choices.keep_time'],
        '24h' => ['label' => 'customization.regional_choices.24h', 'example' => '14:35', 'format' => 'H:i'],
        '12h' => ['label' => 'customization.regional_choices.12h', 'example' => '2:35 PM', 'format' => 'g:i A'],
    ],
    'number_formats' => [
        'system' => ['label' => 'customization.regional_choices.system', 'example' => 'customization.regional_choices.keep_number'],
        'international' => ['label' => 'customization.regional_choices.international', 'example' => '1,250,000.50'],
        'indonesian' => ['label' => 'customization.regional_choices.indonesian', 'example' => '1.250.000,50'],
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
        'date_full_human' => ['date' => 'd F Y', 'time' => null, 'separator' => ''],
        'short_datetime' => ['date' => 'd M', 'time' => 'H:i', 'separator' => ' '],
    ],
    'number_profiles' => [
        'international' => ['decimal' => '.', 'group' => ','],
        'indonesian' => ['decimal' => ',', 'group' => '.'],
        'plain' => ['decimal' => '.', 'group' => ''],
        'decimal' => ['decimal' => '.', 'group' => ''],
    ],
    'months' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
];
