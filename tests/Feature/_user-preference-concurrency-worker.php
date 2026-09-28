<?php

use App\Models\User;
use App\Services\UserPreferenceService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config([
    'database.default' => 'mysql',
    'database.connections.mysql.database' => 'adasi_portal_test',
    'database.connections.mysql.username' => 'root',
    'database.connections.mysql.password' => '',
    'database.connections.mysql.url' => null,
]);
DB::purge('mysql');

if (DB::connection()->getDatabaseName() !== 'adasi_portal_test') {
    exit(3);
}

try {
    $user = User::findOrFail((int) $argv[1]);
    while (microtime(true) < (float) $argv[3]) {
        usleep(1000);
    }

    app(UserPreferenceService::class)->save($user, [
        'theme' => $argv[2],
        'density' => 'comfortable',
        'sidebar_state' => 'expanded',
        'page_size' => 25,
        'quick_access' => [],
        'accent' => $argv[4] ?? 'brand',
        'dashboard' => ['hidden' => ['admin.notifications'], 'order' => ['admin.summary']],
    ]);
    echo 'saved';
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage());
    exit(2);
}
