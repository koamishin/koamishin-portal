<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\TenantApplicationEnvironment;
use App\Models\User;
use App\Services\OAuth\ApplicationAuthentication;
use App\Settings\ApplicationFeaturesSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LoginViewResponse;
use Laravel\Fortify\Contracts\RegisterViewResponse;
use Laravel\Fortify\Contracts\TwoFactorChallengeViewResponse;
use Laravel\Fortify\Contracts\VerifyEmailViewResponse;
use Laravel\Fortify\Features;
use Symfony\Component\HttpFoundation\Response;

class HostedAuthenticationController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request): Response
    {
        $data = $request->validate(['screen' => ['required', 'in:login,register,two-factor,verify']]);
        $authentication = app(ApplicationAuthentication::class);
        abort_unless($authentication->flowId($request) !== null && $authentication->isHosted($request), 419);
        $environment = $request->attributes->get('koamishin.application_environment');
        abort_unless($environment instanceof TenantApplicationEnvironment, 403);

        if (in_array($data['screen'], ['login', 'register'], true) && $request->user() instanceof User) {
            return $authentication->resume($request) ?? abort(419);
        }

        $view = match ($data['screen']) {
            'login' => LoginViewResponse::class,
            'register' => RegisterViewResponse::class,
            'two-factor' => TwoFactorChallengeViewResponse::class,
            'verify' => VerifyEmailViewResponse::class,
            default => abort(422),
        };

        if ($data['screen'] === 'register') {
            abort_unless(Features::enabled(Features::registration())
                && app(ApplicationFeaturesSettings::class)->registration_enabled
                && $authentication->allowsSignups($environment), 403);
        }
        if ($data['screen'] === 'two-factor') {
            abort_unless($request->session()->has('login.id'), 403);
        }
        if ($data['screen'] === 'verify') {
            $user = $request->user();
            abort_unless($user instanceof User, 403);
            if (! $environment->requiresEmailVerification() || $user->hasVerifiedEmail()) {
                return $authentication->resume($request) ?? abort(419);
            }
        }

        $previousErrors = Inertia::getShared('errors');
        try {
            Inertia::share('errors', Inertia::always(app(HandleInertiaRequests::class)->resolveValidationErrors($request)));
            $response = app($view)->toResponse($request);
        } finally {
            Inertia::share('errors', $previousErrors);
        }
        if ($response instanceof JsonResponse && $request->header('X-Inertia')) {
            $page = $response->getData(true);
            if (is_array($page) && isset($page['component'])) {
                $page['url'] = route('hosted.restore', absolute: false);
                $response->setData($page);
            }
        }
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    public function restore(Request $request): Response
    {
        Session::reflash();

        $authentication = app(ApplicationAuthentication::class);
        $screen = $request->header('X-Koamishin-Screen');
        if ($authentication->isHosted($request) && $authentication->flowId($request) !== null
            && is_string($screen) && in_array($screen, ['login', 'register', 'two-factor', 'verify'], true)) {
            $request->merge(['screen' => $screen]);

            return $this($request);
        }

        if ($request->header('X-Inertia')) {
            $response = Inertia::render('auth/HostedTransition', [
                'flow' => null,
                'screen' => null,
                'csrfToken' => csrf_token(),
                'restore' => true,
            ])->toResponse($request);
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('Referrer-Policy', 'no-referrer');

            return $response;
        }

        return response()->view('auth-hosted-transition', [
            'flow' => null,
            'screen' => null,
            'restore' => true,
        ])->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }
}
