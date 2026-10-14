<?php

use App\Http\Controllers\Finance\LocalProcurementImportController;
use App\Models\LocalProcurementImport;
use App\Models\User;
use App\Services\LocalInvoice\LocalProcurementImportService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'mysql', 'database.connections.mysql.database' => 'adasi_portal_test',
    'database.connections.mysql.username' => 'root', 'database.connections.mysql.password' => '', 'database.connections.mysql.url' => null,
    'filesystems.disks.private.root' => $argv[5], 'cache.default' => 'array', 'broadcasting.default' => 'null', 'queue.default' => 'sync', 'session.driver' => 'array']);
DB::purge('mysql');
if (DB::connection()->getDatabaseName() !== 'adasi_portal_test') {
    exit(3);
}
Queue::fake();
try {
    $actor = User::findOrFail($argv[1]);
    $import = LocalProcurementImport::findOrFail($argv[2]);
    auth()->setUser($actor);
    while (microtime(true) < (float) $argv[4]) {
        usleep(1000);
    }
    if ($argv[3] === 'revalidate') {
        app(LocalProcurementImportService::class)->revalidate($actor, $import, (int) $argv[6]);
    } elseif ($argv[3] === 'confirm') {
        app(LocalProcurementImportService::class)->confirm($actor, $argv[7], 'GR');
    } elseif ($argv[3] === 'cleanup') {
        Artisan::call('imports:cleanup');
    } else {
        $request = Request::create('/fixture/cancel', 'POST');
        $request->setUserResolver(fn () => $actor);
        app(LocalProcurementImportController::class)->cancel($request, $import);
    }
    echo 'accepted';
} catch (ValidationException|HttpExceptionInterface $exception) {
    echo 'rejected';
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage());
    exit(2);
}
