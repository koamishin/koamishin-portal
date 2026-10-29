<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\OAuth\ApplicationAuthentication;
use Closure;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectIfApplicationFlowAuthenticated extends RedirectIfAuthenticated
{
    public function handle(Request $request, Closure $next, string ...$guards): Response
    {
        $guard = $request->attributes->get('koamishin.application_guard');
        if ($guard instanceof StatefulGuard) {
            return $guard->check()
                ? app(ApplicationAuthentication::class)->resume($request)
                : $next($request);
        }

        return parent::handle($request, $next, ...$guards);
    }
}
