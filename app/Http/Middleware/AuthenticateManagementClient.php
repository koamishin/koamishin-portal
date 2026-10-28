<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ManagementApiClient;
use Closure;
use Illuminate\Http\Request;
use Laravel\Passport\AccessToken;
use Laravel\Passport\Exceptions\AuthenticationException;
use Laravel\Passport\Exceptions\MissingScopeException;
use Laravel\Passport\Http\Middleware\EnsureClientIsResourceOwner;
use Laravel\Passport\Token;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateManagementClient extends EnsureClientIsResourceOwner
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next, string ...$scopes): Response
    {
        $token = $this->validateToken($request);

        if (! $token instanceof AccessToken) {
            throw new AuthenticationException;
        }

        $this->validate($token, ...$scopes);
        $clientId = $token->toArray()['oauth_client_id'] ?? null;

        if (! is_string($clientId) || ! Token::query()
            ->whereKey($token->oauth_access_token_id)
            ->where('client_id', $clientId)
            ->whereNull('user_id')
            ->exists()) {
            throw new AuthenticationException;
        }

        $binding = ManagementApiClient::query()
            ->with(['environment.application.tenant', 'oauthClient'])
            ->where('oauth_client_id', $clientId)
            ->first();

        if (! $binding || ! $binding->is_active || ! $binding->oauthClient || $binding->oauthClient->getAttribute('revoked')
            || $binding->oauthClient->getAttribute('grant_types') !== ['client_credentials']
            || ! $binding->environment?->application?->tenant?->is_active
            || ! in_array($binding->environment->environment, ['development', 'production'], true)) {
            abort(403);
        }

        foreach ($scopes as $scope) {
            if (! in_array($scope, $binding->allowed_scopes ?? [], true)
                || ! in_array($scope, $token->oauth_scopes ?? [], true)) {
                throw new MissingScopeException($scope);
            }
        }

        $request->attributes->set('koamishin_management_environment', $binding->environment);

        return $next($request);
    }
}
