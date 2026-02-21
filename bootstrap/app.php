<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use App\Http\Middleware\EnforceWidgetOrigin;
use App\Http\Middleware\ForceJsonForApp;
use App\Http\Middleware\AppAuthenticate;
use App\Http\Middleware\RequirePlatformRole;
use App\Http\Middleware\RequireTenantRole;
use App\Http\Middleware\SetCurrentClient;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'widget.origin' => EnforceWidgetOrigin::class,
            'force.json.app' => ForceJsonForApp::class,
            'app.auth' => AppAuthenticate::class,
            'set.current.client' => SetCurrentClient::class,
            'require.tenant.role' => RequireTenantRole::class,
            'require.platform.role' => RequirePlatformRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (TokenMismatchException $e, Request $request) {
            if ($request->is('app/*')) {
                return response()->json([
                    'error' => 'CSRF_INVALID',
                    'reason_code' => \App\Enums\AppDenyReason::CSRF_INVALID->value,
                ], 419);
            }

            return null;
        });
    })->create();
