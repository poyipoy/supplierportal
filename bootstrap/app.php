<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \App\Http\Middleware\RoleMiddleware::class,
            'purchasing.navigation' => \App\Http\Middleware\RememberPurchasingListUrl::class,
        ]);
        
        $middleware->redirectUsersTo(function () {
            if (auth()->check()) {
                return match (auth()->user()->role) {
                    'admin'      => route('admin.dashboard', absolute: false),
                    'purchasing' => route('purchasing.dashboard', absolute: false),
                    'supplier'   => route('supplier.dashboard', absolute: false),
                    'qc'         => route('qc.dashboard', absolute: false),
                    default      => '/',
                };
            }
            return '/';
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
