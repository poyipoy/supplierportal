<?php

use App\Models\User;
use App\Services\Pricing\MaterialReferencePriceService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Mission 06B concurrency worker: `<materialId> <actorId> <min> <max> <start>`
// inserts one USD Flat reference price; prints created or overlap.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.connections.mysql.database' => 'adasi_portal_test', 'database.connections.mysql.url' => null, 'cache.default' => 'array']);
DB::purge('mysql');
if (DB::connection()->getDatabaseName() !== 'adasi_portal_test') {
    exit(3);
}
[, $materialId, $actorId, $min, $max, $start] = $argv;
while (microtime(true) < (float) $start) {
    usleep(1000);
}
try {
    app(MaterialReferencePriceService::class)->create([
        'material_master_id' => (int) $materialId, 'shape' => 'Flat', 'dimension_min' => $min, 'dimension_max' => $max,
        'currency' => 'USD', 'price_per_kg' => '2.5', 'valid_from' => '2026-01-01',
    ], User::findOrFail($actorId));
    echo 'created';
} catch (ValidationException) {
    echo 'overlap';
}
