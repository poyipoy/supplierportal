<?php

use App\Models\User;
use App\Services\LocalInvoice\LocalProcurementImportService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
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
    'filesystems.disks.private.root' => $argv[3],
    'cache.default' => 'array',
    'broadcasting.default' => 'null',
    'queue.default' => 'database',
    'local_procurement_imports.active_per_user' => 1,
    'local_procurement_imports.fast_path_max_rows' => (int) $argv[4],
]);
DB::purge('mysql');
if (DB::connection()->getDatabaseName() !== 'adasi_portal_test') {
    exit(3);
}

try {
    $actor = User::findOrFail((int) $argv[1]);
    $file = new UploadedFile($argv[2], basename($argv[2]), null, null, true);
    while (microtime(true) < (float) $argv[5]) {
        usleep(1000);
    }
    app(LocalProcurementImportService::class)->start($actor, $file, 'PO');
    echo 'accepted';
} catch (ValidationException $exception) {
    echo 'rejected';
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage());
    exit(2);
}
