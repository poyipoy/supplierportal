<?php

use App\Http\Controllers\Admin\ExchangeRateController;
use App\Http\Requests\StoreAnnualExchangeRatesRequest;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || ! preg_match('/_(test|testing)(_test_\d+)?$/', DB::selectOne('SELECT DATABASE() AS db')->db)) {
    exit(3);
}
$actor = User::findOrFail($argv[1]);
$request = StoreAnnualExchangeRatesRequest::create('/admin/exchange-rates/annual', 'POST', [
    'year' => 2024, 'expected_revision' => (int) $argv[3],
    'rates' => ['USD' => $argv[4], 'JPY' => '105.2', 'CNY' => '2250'],
]);
$request->setContainer($app)->setRedirector(app('redirect'));
$request->setUserResolver(fn () => $actor);
try {
    $request->validateResolved();
    while (microtime(true) < (float) $argv[2]) {
        usleep(1000);
    }
    app(ExchangeRateController::class)->storeAnnual($request);
    echo 'accepted';
} catch (ValidationException) {
    echo 'conflict';
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage());
    exit(2);
}
