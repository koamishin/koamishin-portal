<?php

namespace App\Http\Middleware;

use App\Settings\ApplicationFeaturesSettings;
use Closure;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEmailVerificationEnabled
{
    public function __construct(private readonly EnsureEmailIsVerified $verification) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, ?string $redirectToRoute = null): Response
    {
        if (! app(ApplicationFeaturesSettings::class)->email_verification_required) {
            return $next($request);
        }

        return $this->verification->handle($request, $next, $redirectToRoute);
    }
}
