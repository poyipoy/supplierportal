<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make('Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables')->bootstrap($app);
$app->make('Illuminate\Foundation\Bootstrap\LoadConfiguration')->bootstrap($app);
$app->make('Illuminate\Foundation\Bootstrap\HandleExceptions')->bootstrap($app);
$app->make('Illuminate\Foundation\Bootstrap\RegisterFacades')->bootstrap($app);
$app->make('Illuminate\Foundation\Bootstrap\SetRequestForConsole')->bootstrap($app);
$app->make('Illuminate\Foundation\Bootstrap\RegisterProviders')->bootstrap($app);
$app->make('Illuminate\Foundation\Bootstrap\BootProviders')->bootstrap($app);

$user = App\Models\User::where('role', 'purchasing')->first();
Illuminate\Support\Facades\Auth::loginUsingId($user->id);
echo "Logged in as: " . $user->name . " (Role: " . $user->role . ")\n";

$request = Illuminate\Http\Request::create('/purchasing/requisitions/28', 'GET');
$response = $kernel->handle($request);
echo "Response Status for /purchasing/requisitions/28: " . $response->getStatusCode() . "\n";

$request2 = Illuminate\Http\Request::create('/purchasing/quotations/1', 'GET');
$response2 = $kernel->handle($request2);
echo "Response Status for /purchasing/quotations/1: " . $response2->getStatusCode() . "\n";
