<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

// Dedicated browser QA harness. It cannot serve the operational database.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config([
    'app.env' => 'testing', 'app.url' => 'http://127.0.0.1:8099',
    'database.default' => 'mysql', 'database.connections.mysql.database' => 'adasi_portal_test',
    'database.connections.mysql.username' => 'root', 'database.connections.mysql.password' => '', 'database.connections.mysql.url' => null,
    'filesystems.disks.private.root' => storage_path('framework/testing/local-browser'),
    'session.driver' => 'file', 'session.files' => storage_path('framework/testing/local-browser-sessions'),
    'session.secure' => false, 'session.domain' => null,
    'cache.default' => 'array', 'broadcasting.default' => 'null', 'queue.default' => 'sync', 'mail.default' => 'array',
    'auth_security.turnstile.site_key' => null, 'auth_security.turnstile.secret_key' => null,
]);
DB::purge('mysql');
if (DB::connection()->getDatabaseName() !== 'adasi_portal_test') {
    throw new RuntimeException('Browser QA requires the isolated test database.');
}
File::ensureDirectoryExists(config('session.files'));

return $app;
