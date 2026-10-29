<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\TenantApplicationEnvironment;
use App\Models\User;
use App\Services\OAuth\ApplicationAccess;
use App\Services\OAuth\ApplicationAuthentication;
use App\Settings\ApplicationFeaturesSettings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Laravel\Passport\Bridge\Client;
use Laravel\Passport\Bridge\Scope;
use Laravel\Passport\Bridge\User as PassportUser;
use Laravel\Passport\Exceptions\OAuthServerException as PassportOAuthServerException;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\RequestTypes\AuthorizationRequest;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Response;

class EnsureOAuthApplicationAccess
{
    public function __construct(
        private readonly ApplicationAccess $access,
        private readonly ApplicationAuthentication $authentication,
        private readonly AuthorizationServer $server,
    ) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('login', 'register', 'two-factor-challenge', 'passkeys/login', 'passkeys/login/options', 'auth/*/redirect', 'email/verify', 'email/verify/*', 'email/verification-notification')) {
            $flow = $request->input('flow');
            $expiredVerificationLink = $request->is('email/verify/*') && is_string($flow) && ! $this->authentication->hasFlow($request, $flow);
            if (! $expiredVerificationLink) {
                $this->authentication->resolve($request);
            }

            return $this->authentication->withinFlow($request, function () use ($request, $next): Response {
                $environment = $request->attributes->get('koamishin.application_environment');
                abort_if($environment instanceof TenantApplicationEnvironment && ! $environment->allowsPasskeys()
                    && $request->is('passkeys/login', 'passkeys/login/options'), 403, 'Passkey sign-in is disabled for this application.');
                if ($request->is('email/verify') && $this->authentication->flowId($request) !== null
                    && $environment instanceof TenantApplicationEnvironment && $request->user() instanceof User
                    && (! $environment->requiresEmailVerification() || $request->user()->hasVerifiedEmail())) {
                    return $this->authentication->resume($request);
                }
                $response = $next($request);
                $flow = $this->authentication->flowId($request);
                if ($flow !== null && $request->is('login') && $request->isMethod('POST')
                    && $request->session()->has('login.id') && $response->isRedirection()) {
                    $response->headers->set('Location', route('two-factor.login', ['flow' => $flow]));
                }
                if ($flow !== null && $request->is('auth/*/redirect') && is_string($state = $request->session()->get('state'))) {
                    $request->session()->put('koamishin.social_flows.'.$state, [
                        'flow' => $flow,
                        'provider' => $request->route('provider'),
                    ]);
                }

                return $response;
            });
        }

        if ($request->is('auth/*/callback')) {
            $state = $request->query('state');
            $socialFlow = is_string($state) ? $request->session()->pull('koamishin.social_flows.'.$state) : null;
            if (is_array($socialFlow)) {
                abort_unless(($socialFlow['provider'] ?? null) === $request->route('provider') && is_string($socialFlow['flow'] ?? null), 419);
                $this->authentication->resolve($request, $socialFlow['flow']);
            }

            return $this->authentication->withinFlow($request, fn (): Response => $next($request));
        }

        if (! $request->is('oauth/authorize') || ! in_array($request->method(), ['GET', 'POST', 'DELETE'], true)) {
            return $next($request);
        }

        if ($request->isMethod('GET')) {
            $response = $this->authorize($request, $next);
        } else {
            $response = $this->complete($request, $next);
        }

        if ($request->header('X-Inertia') && $response->isRedirection() && is_string($location = $response->headers->get('Location'))) {
            return Inertia::location($location);
        }

        return $response;
    }

    /** @param Closure(Request): Response $next */
    private function authorize(Request $request, Closure $next): Response
    {
        try {
            $authorization = $this->server->validateAuthorizationRequest(app(ServerRequestInterface::class));
        } catch (OAuthServerException) {
            abort(400, 'Invalid application authorization request. Start again from the application.');
        }

        $clientId = $authorization->getClient()->getIdentifier();
        $environment = $this->access->environmentForClient($clientId);
        abort_unless($environment instanceof TenantApplicationEnvironment, 403);
        $request->attributes->set('koamishin.application_environment', $environment);

        if ($request->query('flow') === null) {
            $flow = $this->authentication->begin($request, $clientId);
            $signup = $request->query('screen_hint') === 'signup';
            abort_if($signup && ! app(ApplicationFeaturesSettings::class)->registration_enabled, 403, 'Registration is disabled.');
            abort_if($signup && ! $this->authentication->allowsSignups($environment), 403, 'Registration is closed for this application.');
            $prompts = explode(' ', (string) $request->query('prompt', ''));
            if (! $signup && ! in_array('login', $prompts, true) && $this->authentication->reuseSingleSignOn($request, $environment)) {
                return $this->authentication->resume($request);
            }
            if (in_array('none', $prompts, true)) {
                $this->authentication->finish($request);
                throw PassportOAuthServerException::loginRequired($authorization);
            }

            return redirect()->route($signup ? 'register' : 'login', ['flow' => $flow['id']]);
        }

        $this->authentication->begin($request, $clientId);

        return $this->authentication->withinFlow($request, function () use ($request, $next, $environment, $authorization): Response {
            $user = $request->user();
            if (! $user instanceof User) {
                if (in_array('none', explode(' ', (string) $request->query('prompt', '')), true)) {
                    throw PassportOAuthServerException::loginRequired($authorization);
                }

                return redirect()->route('login', ['flow' => $this->authentication->flowId($request)]);
            }

            abort_if($user->isBanned(), 403);
            $this->authentication->ensureSingleSignOnStillAllowed($request, $environment, $user);
            if ($environment->requiresEmailVerification() && ! $user->hasVerifiedEmail()) {
                if (in_array('none', explode(' ', (string) $request->query('prompt', '')), true)) {
                    throw PassportOAuthServerException::loginRequired($authorization);
                }
                $flow = $this->authentication->flowId($request);
                if (! $request->session()->get('koamishin.verification_sent.'.$flow)) {
                    $user->sendEmailVerificationNotification();
                }

                return redirect()->route('verification.notice', ['flow' => $flow]);
            }
            $prompts = array_filter(explode(' ', (string) $request->query('prompt', '')));
            $prompts = array_diff($prompts, ['login']);
            if (! $this->access->allows($environment, $user)) {
                if (in_array('none', $prompts, true)) {
                    throw PassportOAuthServerException::consentRequired($authorization);
                }
                abort_unless($this->authentication->allowsSignups($environment), 403, 'You do not have access to this application.');
                $prompts[] = 'consent';
                $request->attributes->set('koamishin.joining_application', true);
            }
            $request->query->set('prompt', implode(' ', array_unique($prompts)));

            $response = $next($request);
            if ($response->isRedirection()) {
                $this->authentication->rememberSingleSignOn($request, $environment, $user, $response);
                $this->authentication->finish($request);
            }

            return $response;
        });
    }

    /** @param Closure(Request): Response $next */
    private function complete(Request $request, Closure $next): Response
    {
        abort_unless(is_string($request->input('flow')), 400);
        $this->authentication->resolve($request);

        return $this->authentication->withinFlow($request, function () use ($request, $next): Response {
            $response = $this->completeAuthorization($request, $next);
            if ($response->isRedirection()) {
                $environment = $request->attributes->get('koamishin.application_environment');
                if ($request->isMethod('POST') && $environment instanceof TenantApplicationEnvironment && $request->user() instanceof User) {
                    $this->authentication->rememberSingleSignOn($request, $environment, $request->user(), $response);
                }
                $this->authentication->finish($request);
            }

            return $response;
        });
    }

    /** @param Closure(Request): Response $next */
    private function completeAuthorization(Request $request, Closure $next): Response
    {
        $authorization = $this->authorizationFromSession($request);
        abort_unless($authorization instanceof AuthorizationRequestInterface, 400);
        $environment = $this->access->environmentForClient($authorization->getClient()->getIdentifier());
        $user = $request->user();
        abort_unless($environment instanceof TenantApplicationEnvironment && $user instanceof User && ! $user->isBanned(), 403);
        abort_unless($authorization->getUser()?->getIdentifier() === (string) $user->getKey(), 403);
        abort_unless(in_array($authorization->getRedirectUri(), $environment->oauthClient?->getAttribute('redirect_uris') ?? [], true), 403);

        $expectedToken = $request->session()->get('authToken');
        $providedToken = $request->input('auth_token');
        abort_unless(is_string($expectedToken) && is_string($providedToken) && hash_equals($expectedToken, $providedToken), 400);

        if ($request->isMethod('DELETE')) {
            return $next($request);
        }

        $this->authentication->ensureSingleSignOnStillAllowed($request, $environment, $user);
        abort_if($environment->requiresEmailVerification() && ! $user->hasVerifiedEmail(), 403, 'Verify your email address before authorizing this application.');

        if ($this->access->allows($environment, $user)) {
            return $next($request);
        }

        abort_unless($this->authentication->allowsSignups($environment), 403);

        return DB::transaction(function () use ($request, $next, $environment, $user): Response {
            $response = $next($request);
            if ($response->isRedirection()) {
                $this->authentication->enroll($environment, $user);
            }

            return $response;
        });
    }

    private function authorizationFromSession(Request $request): ?AuthorizationRequestInterface
    {
        $serialized = $request->session()->get('authRequest');
        if (! is_string($serialized)) {
            return null;
        }

        try {
            $authorization = unserialize($serialized, ['allowed_classes' => [
                AuthorizationRequest::class,
                Client::class,
                Scope::class,
                PassportUser::class,
            ]]);
        } catch (\Throwable) {
            return null;
        }

        return $authorization instanceof AuthorizationRequestInterface ? $authorization : null;
    }
}
