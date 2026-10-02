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

try {
    if (config('database.connections.mysql.database') !== 'adasi_portal_test'
        || DB::selectOne('SELECT DATABASE() AS db')->db !== 'adasi_portal_test') {
        fwrite(STDERR, 'Preference concurrency workers require adasi_portal_test.');
        exit(3);
    }

    $user = User::findOrFail((int) $argv[1]);
    while (microtime(true) < (float) $argv[3]) {
        usleep(1000);
    }

    if (($argv[5] ?? null) === 'notification-reset') {
        app(UserPreferenceService::class)->resetNotificationPreferences($user);
    } elseif (($argv[5] ?? null) === 'notifications') {
        $enabled = match ($argv[2]) {
            'true' => true,
            'false' => false,
            default => throw new InvalidArgumentException('Notification worker values must be true or false.'),
        };
        app(UserPreferenceService::class)->saveNotificationPreferences($user, [
            'local_invoice_submitted' => $enabled,
        ]);
    } else {
        app(UserPreferenceService::class)->save($user, [
            'theme' => $argv[2],
            'density' => 'comfortable',
            'sidebar_state' => 'expanded',
            'page_size' => 25,
            'quick_access' => [],
            'accent' => $argv[4] ?? 'brand',
            'dashboard' => ['hidden' => ['admin.notifications'], 'order' => ['admin.summary']],
        ]);
    }
    echo 'saved';
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage());
    exit(2);
}
