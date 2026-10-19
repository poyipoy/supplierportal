<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

// Reuse the existing isolated browser harness; never boot the operational DB.
$densityProject = dirname(__DIR__, 2);
foreach (['APP_ENV' => 'testing', 'DB_DATABASE' => 'adasi_portal_test', 'DB_URL' => '',
    'HASHIDS_SALT' => 'adasi-isolated-test-salt-never-use-in-production',
    'APP_CONFIG_CACHE' => $densityProject.'/storage/framework/testing/density/absent-config.php',
    'APP_ROUTES_CACHE' => $densityProject.'/storage/framework/testing/density/absent-routes.php',
] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$app = require __DIR__.'/local-invoice-browser-bootstrap.php';
if (! $app->environment('testing') || DB::selectOne('SELECT DATABASE() AS db')->db !== 'adasi_portal_test') {
    throw new RuntimeException('Density browser QA requires the live isolated test database.');
}
config([
    'app.url' => 'http://127.0.0.1:8129',
    'session.cookie' => 'adasi_density_qa',
    'session.files' => storage_path('framework/testing/density/sessions'),
]);
File::ensureDirectoryExists(config('session.files'));

return $app;
