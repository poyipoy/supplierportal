<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Supplier Company Titles
    |--------------------------------------------------------------------------
    |
    | Recognised legal entity prefixes/titles. "Other" triggers a custom
    | free-text title field in the registration form.
    |
    */
    'company_titles' => [
        'PT',
        'CV',
        'UD',
        'PD',
        'VPD',
        'Firma',
        'Koperasi',
        'Yayasan',
        'Company',
        'Other',
    ],

    /*
    |--------------------------------------------------------------------------
    | Supplier Business Categories
    |--------------------------------------------------------------------------
    */
    'categories' => [
        'Steel Manufacturer',
        'Machining & Fabrication',
        'Distributor / Agent',
        'Service Provider',
        'General Supplier',
        'Other',
    ],

    /*
    |--------------------------------------------------------------------------
    | Registration Documents Policy
    |--------------------------------------------------------------------------
    |
    | Maximum file size in kilobytes and allowed MIME types / extensions.
    |
    */
    'documents' => [
        'max_size_kb' => 5120, // 5 MB
        'allowed_mimes' => ['application/pdf', 'image/jpeg', 'image/png'],
        'allowed_extensions' => ['pdf', 'jpg', 'jpeg', 'png'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Registration Access Credential Settings
    |--------------------------------------------------------------------------
    */
    'access' => [
        'token_ttl_days' => 30,
        'session_ttl_minutes' => 60,
        'max_login_attempts_per_window' => 5,
        'rate_limit_window_minutes' => 10,
    ],
];
