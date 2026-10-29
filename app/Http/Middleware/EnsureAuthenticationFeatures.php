<?php

namespace App\Http\Middleware;

use App\Settings\ApplicationFeaturesSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAuthenticationFeatures
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $settings = app(ApplicationFeaturesSettings::class);

        abort_if($request->is('register') && ! $settings->registration_enabled, 403, 'Registration is disabled.');
        abort_if($request->is('forgot-password', 'reset-password', 'reset-password/*') && ! $settings->password_reset_enabled, 403, 'Password reset is disabled.');
        abort_if($request->is('notifications', 'notifications/*') && ! $settings->notifications_enabled, 403, 'Notifications are disabled.');

        if (! $settings->two_factor_authentication_enabled) {
            abort_if($request->isMethod('POST') && $request->is(
                'user/two-factor-authentication',
                'user/confirmed-two-factor-authentication',
                'settings/security/mfa/app/setup',
                'settings/security/mfa/app/enable',
                'settings/security/mfa/email/start',
                'settings/security/mfa/email/resend',
                'settings/security/mfa/email/enable',
            ), 403, 'Two-factor setup is disabled.');
        }

        return $next($request);
    }
}
