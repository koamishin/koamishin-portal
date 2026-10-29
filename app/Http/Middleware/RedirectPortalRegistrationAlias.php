<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class RedirectPortalRegistrationAlias
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $alias = config('fortify.registration_alias_origin');
        if (! in_array($request->method(), ['GET', 'HEAD'], true) || ! $request->routeIs('register')
            || ! is_string($alias) || rtrim($alias, '/') !== $request->getSchemeAndHttpHost()
            || $request->query('flow') !== null || $request->header('X-Koamishin-Flow') !== null) {
            return $next($request);
        }

        $configuredTarget = config('fortify.registration_alias_target_url');
        $hasConfiguredTarget = $configuredTarget !== null && $configuredTarget !== '';
        if ($hasConfiguredTarget) {
            abort_unless(is_string($configuredTarget) && $this->isValidDestination($configuredTarget), 503, 'The registration destination is not configured correctly.');
            $destination = $configuredTarget;
        } else {
            $portal = config('app.url');
            if (! is_string($portal) || ! $this->isValidDestination($portal)) {
                return $next($request);
            }

            $destination = rtrim($portal, '/').route('register', absolute: false);
        }

        if (strcasecmp(Request::create($destination)->url(), $request->url()) === 0) {
            abort_if($hasConfiguredTarget, 503, 'The registration destination points back to the same address.');

            return $next($request);
        }

        if ($request->header('X-Inertia')) {
            return Inertia::location($destination);
        }

        return redirect()->away($destination);
    }

    private function isValidDestination(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)
            && parse_url($url, PHP_URL_USER) === null
            && parse_url($url, PHP_URL_PASS) === null;
    }
}
