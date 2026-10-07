<?php

use App\Exports\PurchaseOrdersExport;
use App\Models\User;
use App\Support\ExportDispatcher;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config([
    'database.default' => 'mysql',
    'database.connections.mysql.database' => 'adasi_portal_test',
    'database.connections.mysql.username' => 'root',
    'database.connections.mysql.password' => '',
    'database.connections.mysql.url' => null,
    'cache.default' => 'array',
    'broadcasting.default' => 'null',
    'queue.default' => 'database',
    'queue.connections.database.connection' => 'mysql',
    'queue.connections.database.after_commit' => false,
]);
DB::purge('mysql');
if (app()->environment() !== 'testing' || DB::selectOne('SELECT DATABASE() AS db')->db !== 'adasi_portal_test') {
    exit(3);
}
try {
    Auth::setUser(User::findOrFail($argv[1]));
    while (microtime(true) < (float) $argv[2]) {
        usleep(1000);
    }
    ExportDispatcher::dispatch('Concurrent list', PurchaseOrdersExport::class, [], 'concurrent.xlsx');
    echo 'accepted';
} catch (ValidationException) {
    echo 'rejected';
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage());
    exit(2);
}
