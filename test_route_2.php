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

$q = App\Models\Quotation::first();
if ($q) {
    $request = Illuminate\Http\Request::create('/purchasing/quotations/' . $q->id, 'GET');
    $response = $kernel->handle($request);
    echo "Response Status for /purchasing/quotations/" . $q->id . ": " . $response->getStatusCode() . "\n";
} else {
    echo "No quotation found\n";
}
