<?php

use App\Models\SupplierGoodsLocation;
use App\Models\User;
use App\Services\Procurement\PreshipmentInspectionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// Concurrency worker: `<locationId> <startTimestamp>`
// Calls PreshipmentInspectionService::setDefaultLocation() on the dedicated test database.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $locationId, $start] = $argv;

config([
    'database.connections.mysql.database' => 'adasi_portal_test',
    'database.connections.mysql.url' => null,
    'cache.default' => 'array',
]);
DB::purge('mysql');
if (DB::connection()->getDatabaseName() !== 'adasi_portal_test') {
    exit(3);
}

while (microtime(true) < (float) $start) {
    usleep(500);
}

try {
    $location = SupplierGoodsLocation::findOrFail((int) $locationId);
    $supplier = User::findOrFail((int) $location->supplier_id);
    app(PreshipmentInspectionService::class)->setDefaultLocation($location, $supplier);
    echo 'ok';
} catch (QueryException $exception) {
    if (($exception->errorInfo[0] ?? null) !== '40001') {
        throw $exception;
    }
    echo 'deadlock';
}
