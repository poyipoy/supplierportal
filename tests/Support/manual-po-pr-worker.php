<?php

use App\Models\LocalPurchaseOrder;
use App\Models\User;
use App\Services\LocalInvoice\LocalProcurementMasterService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.connections.mysql.database' => 'adasi_portal_test', 'database.connections.mysql.url' => null, 'cache.default' => 'array']);
DB::purge('mysql');
if (DB::connection()->getDatabaseName() !== 'adasi_portal_test') {
    exit(3);
}
$po = LocalPurchaseOrder::findOrFail($argv[1]);
$actor = User::findOrFail($argv[2]);
$data = $po->only(['po_number', 'supplier_id', 'total_amount', 'description']);
$data['po_date'] = $po->po_date->format('Y-m-d');
$data['pr_number'] = $argv[3];
while (microtime(true) < (float) $argv[4]) {
    usleep(1000);
}
try {
    app(LocalProcurementMasterService::class)->updatePurchaseOrder($actor, $po, $data);
    echo 'accepted';
} catch (ValidationException $exception) {
    echo 'conflict';
}
