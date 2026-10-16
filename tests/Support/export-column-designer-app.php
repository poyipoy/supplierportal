<?php

use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// Task-owned full-application browser harness; never a production entrypoint.
// Seed: php tests/Support/export-column-designer-app.php
// Serve: php -S 127.0.0.1:8102 -t public tests/Support/export-column-designer-app.php
// Only GET is accepted. All data and users belong to the isolated test database.
$project = dirname(__DIR__, 2);
$database = 'adasi_export_column_designer_20261015_test';
if (PHP_SAPI === 'cli-server') {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        exit('Browser harness is GET-only.');
    }
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $file = realpath($project.'/public'.$path);
    if ($path !== '/' && $file && str_starts_with($file, realpath($project.'/public').DIRECTORY_SEPARATOR) && is_file($file)) {
        return false;
    }
}
require $project.'/vendor/autoload.php';
foreach (['APP_ENV' => 'testing', 'DB_DATABASE' => $database, 'DB_URL' => '', 'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'BROADCAST_CONNECTION' => 'null'] as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $_SERVER[$name] = $value;
}
$app = require $project.'/bootstrap/app.php';
if ($app->configurationIsCached()) {
    throw new RuntimeException('Refusing cached configuration.');
}
$request = Request::capture();
$app->instance('request', $request);
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== $database
    || DB::selectOne('SELECT DATABASE() AS name')->name !== $database) {
    throw new RuntimeException('Refusing any database outside the task-owned test namespace.');
}
$roles = ['purchasing', 'supplier', 'finance', 'qc', 'accounting'];
if (PHP_SAPI !== 'cli-server') {
    foreach ($roles as $role) {
        foreach (['en', 'id'] as $locale) {
            $email = 'designer.'.$role.'.'.$locale.'@example.test';
            if (User::where('email', $email)->exists()) {
                continue;
            }
            $actor = User::factory()->create(['name' => 'Designer '.$role.' '.$locale, 'email' => $email, 'role' => $role]);
            $actor->preference()->create([...config('user_preferences.defaults'), 'locale' => $locale]);
        }
    }
    foreach (['en', 'id'] as $locale) {
        $supplier = User::where('email', 'designer.supplier.'.$locale.'@example.test')->firstOrFail();
        $purchasing = User::where('email', 'designer.purchasing.'.$locale.'@example.test')->firstOrFail();
        PurchaseOrder::firstOrCreate(['po_number' => 'PO/DESIGNER/'.strtoupper($locale).'/001'], ['supplier_id' => $supplier->id, 'created_by' => $purchasing->id, 'currency' => 'IDR', 'status' => 'active']);
    }
    echo 'Seeded ten test-only role/locale actors and two POs in '.$database.PHP_EOL;
    exit;
}
$role = $_GET['__as'] ?? $_COOKIE['column_qa_actor'] ?? 'purchasing';
$role = in_array($role, $roles, true) ? $role : 'purchasing';
$locale = ($_GET['__locale'] ?? $_COOKIE['column_qa_locale'] ?? 'en') === 'id' ? 'id' : 'en';
setcookie('column_qa_actor', $role, ['path' => '/', 'samesite' => 'Lax']);
setcookie('column_qa_locale', $locale, ['path' => '/', 'samesite' => 'Lax']);
$actor = User::where('email', 'designer.'.$role.'.'.$locale.'@example.test')->firstOrFail();
$app['auth']->guard('web')->setUser($actor);
if ($role === 'supplier') {
    $request->setLaravelSession($app['session']->driver());
    $app['session']->put('supplier_context', 'import');
}
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);

return true;
