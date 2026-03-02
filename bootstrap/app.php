<?php

/*
|--------------------------------------------------------------------------
| PsySH / Tinker config directory
|--------------------------------------------------------------------------
| When running artisan commands (e.g. tinker) as www-data or in restricted
| environments, PsySH cannot write to ~/.config/psysh. Redirect to storage.
*/
if (! getenv('XDG_CONFIG_HOME')) {
    putenv('XDG_CONFIG_HOME=' . dirname(__DIR__) . '/storage');
}

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->validateCsrfTokens(except: [
            'api/wix_webhook*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    ->create();
