<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\TenantApplicationEnvironment;
use App\Models\User;
use App\Services\OAuth\ApplicationAccess;
use App\Services\OAuth\ApplicationAuthentication;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Laravel\Passport\Bridge\Client;
use Laravel\Passport\Bridge\Scope;
use Laravel\Passport\Bridge\User as PassportUser;
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
        if ($request->is('login', 'register')) {
            $this->authentication->resolve($request);

            return $next($request);
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

        if ($request->query('flow') !== null) {
            $this->authentication->begin($request, $clientId);
        }

        $user = $request->user();
        if (! $user instanceof User) {
            if (in_array('none', explode(' ', (string) $request->query('prompt', '')), true)) {
                return $next($request);
            }

            $flow = $this->authentication->begin($request, $clientId);
            $signup = $request->query('screen_hint') === 'signup';
            abort_if($signup && ! $this->authentication->allowsSignups($environment), 403, 'Registration is closed for this application.');

            return redirect()->route($signup ? 'register' : 'login', ['flow' => $flow['id']]);
        }

        abort_if($user->isBanned(), 403);
        if (! $this->access->allows($environment, $user)) {
            abort_unless($this->authentication->allowsSignups($environment), 403, 'You do not have access to this application.');
            abort_if(in_array('none', explode(' ', (string) $request->query('prompt', '')), true), 403);
            $prompts = array_filter(explode(' ', (string) $request->query('prompt', '')));
            $prompts[] = 'consent';
            $request->query->set('prompt', implode(' ', array_unique($prompts)));
            $request->attributes->set('koamishin.joining_application', true);
        }

        return $next($request);
    }

    /** @param Closure(Request): Response $next */
    private function complete(Request $request, Closure $next): Response
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
