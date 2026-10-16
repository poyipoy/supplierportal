<?php

use App\Models\Shipment;
use App\Services\ShipmentService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// AC-03 concurrency worker: `<shipmentId> <start>` submits one saved draft;
// prints submitted or refused.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.connections.mysql.database' => 'adasi_portal_test', 'database.connections.mysql.url' => null, 'cache.default' => 'array']);
DB::purge('mysql');
if (DB::connection()->getDatabaseName() !== 'adasi_portal_test') {
    exit(3);
}
[, $shipmentId, $start] = $argv;
while (microtime(true) < (float) $start) {
    usleep(1000);
}
try {
    app(ShipmentService::class)->submitShipment(Shipment::findOrFail($shipmentId));
    echo 'submitted';
} catch (InvalidArgumentException|ValidationException) {
    echo 'refused';
} catch (QueryException $exception) {
    // A MySQL deadlock rolls the losing transaction back (nothing written).
    if (($exception->errorInfo[0] ?? null) !== '40001') {
        throw $exception;
    }
    echo 'deadlock';
}
