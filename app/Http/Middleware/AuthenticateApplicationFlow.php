<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Contracts\Auth\StatefulGuard;

class AuthenticateApplicationFlow extends Authenticate
{
    protected function authenticate($request, array $guards): void
    {
        $guard = $request->attributes->get('koamishin.application_guard');
        if ($guard instanceof StatefulGuard && $request->is('oauth/authorize', 'email/verify', 'email/verify/*', 'email/verification-notification')) {
            if (! $guard->check()) {
                $this->unauthenticated($request, $guards);
            }

            return;
        }

        parent::authenticate($request, $guards);
    }
}
