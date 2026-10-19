<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

if (PHP_SAPI !== 'cli-server') {
    exit(1);
}
$public = realpath(dirname(__DIR__, 2).'/public');
$path = realpath($public.'/'.ltrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/'));
if ($path && str_starts_with($path, $public.DIRECTORY_SEPARATOR) && is_file($path)) {
    return false;
}
$app = require __DIR__.'/density-browser-bootstrap.php';
$app['view']->addLocation(__DIR__);
Route::get('/__density-components-public', fn () => view()->file(__DIR__.'/density-browser-components.blade.php', ['fixturePublic' => true]));
Route::middleware(['web', 'auth'])->get('/__density-components', fn () => view()->file(__DIR__.'/density-browser-components.blade.php'))->name('density.qa.components');
// QA-only no-script response; retain the normal authentication/session pipeline.
$request = Request::capture();
if ($request->path() === 'profile/customization' && $request->query('density_nojs') === '1') {
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $response = $kernel->handle($request);
    $response->headers->set('Content-Security-Policy', "script-src 'none'");
    $response->send();
    $kernel->terminate($request, $response);
    exit;
}
$app->handleRequest($request);
