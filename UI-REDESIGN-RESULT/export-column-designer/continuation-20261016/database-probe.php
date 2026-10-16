<?php

require dirname(__DIR__, 3).'/vendor/autoload.php';
$expected = 'adasi_export_column_designer_20261015_test';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
if ($app->configurationIsCached()) {
    throw new RuntimeException('Refusing cached configuration.');
}
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$configured = config('database.connections.mysql.database');
$live = \Illuminate\Support\Facades\DB::selectOne('SELECT DATABASE() AS db')->db;
if (! app()->environment('testing') || config('database.default') !== 'mysql' || $configured !== $expected || $live !== $expected) {
    throw new RuntimeException('Task-owned database safety check failed.');
}
$otherConnections = \Illuminate\Support\Facades\DB::select('SELECT ID, COMMAND, STATE FROM information_schema.PROCESSLIST WHERE DB = ? AND ID <> CONNECTION_ID()', [$expected]);
if ($otherConnections !== []) {
    throw new RuntimeException('Task-owned namespace is currently in use by another connection.');
}
echo json_encode(['status' => 'PASS', 'environment' => app()->environment(), 'config_database' => $configured, 'live_database' => $live, 'cached_config' => false, 'other_namespace_connections' => count($otherConnections), 'existing_table_count' => \Illuminate\Support\Facades\DB::selectOne('SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?', [$expected])->n], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
