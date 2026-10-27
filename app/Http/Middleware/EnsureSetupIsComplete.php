<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSetupIsComplete
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->runningUnitTests()) {
            return $next($request);
        }

        if ($request->routeIs('setup.*') || $request->is('setup*') || $request->is('up') || $request->is('oauth/*') || $request->is('.well-known/*')) {
            return $next($request);
        }

        try {
            $hasSuperAdmin = User::query()
                ->whereHas('roles', fn ($q) => $q->where('name', 'super_admin'))
                ->exists();

            if (! $hasSuperAdmin) {
                return redirect()->route('setup.index');
            }
        } catch (\Throwable) {
            // DB connection or migration not ready yet
            return $next($request);
        }

        return $next($request);
    }
}
