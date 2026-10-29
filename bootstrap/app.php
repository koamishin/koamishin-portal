<?php

use App\Http\Middleware\AuthenticateApplicationFlow;
use App\Http\Middleware\EnsureAuthenticationFeatures;
use App\Http\Middleware\EnsureEmailVerificationEnabled;
use App\Http\Middleware\EnsureOAuthApplicationAccess;
use App\Http\Middleware\EnsureSetupIsComplete;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RedirectIfApplicationFlowAuthenticated;
use App\Http\Middleware\RedirectPortalRegistrationAlias;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'auth' => AuthenticateApplicationFlow::class,
            'guest' => RedirectIfApplicationFlowAuthenticated::class,
            'verified' => EnsureEmailVerificationEnabled::class,
        ]);
        $middleware->prependToPriorityList(
            AuthenticatesRequests::class,
            EnsureOAuthApplicationAccess::class,
        );
        $middleware->prependToPriorityList(
            EnsureOAuthApplicationAccess::class,
            EnsureSetupIsComplete::class,
        );
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            RedirectPortalRegistrationAlias::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            EnsureSetupIsComplete::class,
            EnsureAuthenticationFeatures::class,
            EnsureOAuthApplicationAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
