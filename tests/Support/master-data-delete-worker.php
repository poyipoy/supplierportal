<?php

use App\Models\MaterialMaster;
use App\Models\PrItem;
use App\Models\User;
use App\Services\Materials\MasterDataDeletionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// Mission 06A concurrency worker: `delete <materialId> <actorId> <start>` or
// `insert <prId> <materialId> <start>` (a PR item referencing the material).
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.connections.mysql.database' => 'adasi_portal_test', 'database.connections.mysql.url' => null, 'cache.default' => 'array']);
DB::purge('mysql');
if (DB::connection()->getDatabaseName() !== 'adasi_portal_test') {
    exit(3);
}
[, $mode, $first, $second, $start] = $argv;
while (microtime(true) < (float) $start) {
    usleep(1000);
}
if ($mode === 'delete') {
    $outcome = app(MasterDataDeletionService::class)->delete(MaterialMaster::findOrFail($first), User::findOrFail($second));
    echo $outcome->deleted ? 'deleted' : 'refused';
    exit(0);
}
try {
    PrItem::create([
        'pr_id' => (int) $first, 'material_master_id' => (int) $second, 'hs_code' => '7209.16.00',
        'material_name' => 'Concurrent item', 'quantity' => 1, 'shape' => 'Flat',
        'thickness' => 2, 'width' => 100, 'length' => 200, 'weight_needed' => 3,
    ]);
    echo 'inserted';
} catch (QueryException) {
    echo 'insert_failed';
}
