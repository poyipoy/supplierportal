<?php

use App\Models\Shipment;
use App\Models\User;
use App\Services\ShipmentService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Shipment concurrency worker: `<action> <shipmentId> <start> <mtcEnabled> [<attachmentId> <userId>]`.
// action submit: submits the saved draft; remove-mtc: removes one MTC as the owner.
// Prints submitted|removed, refused:<exception class> or deadlock.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[, $action, $shipmentId, $start, $mtcEnabled] = $argv;
config([
    'database.connections.mysql.database' => 'adasi_portal_test',
    'database.connections.mysql.url' => null,
    'cache.default' => 'array',
    'procurement.shipment_mtc_enabled' => $mtcEnabled === 'true',
]);
DB::purge('mysql');
if (DB::connection()->getDatabaseName() !== 'adasi_portal_test') {
    exit(3);
}
while (microtime(true) < (float) $start) {
    usleep(500);
}
try {
    if ($action === 'remove-mtc') {
        app(ShipmentService::class)->removeMtc((int) $shipmentId, (int) $argv[5], User::findOrFail((int) $argv[6]));
        echo 'removed';
    } else {
        app(ShipmentService::class)->submitShipment(Shipment::findOrFail($shipmentId));
        echo 'submitted';
    }
} catch (InvalidArgumentException|ValidationException $exception) {
    echo 'refused:'.class_basename($exception);
} catch (QueryException $exception) {
    if (($exception->errorInfo[0] ?? null) !== '40001') {
        throw $exception;
    }
    echo 'deadlock';
}
