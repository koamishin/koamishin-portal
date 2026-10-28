<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\OAuth\ApplicationAccess;
use Closure;
use Illuminate\Http\Request;
use Laravel\Passport\Bridge\Client;
use Laravel\Passport\Bridge\Scope;
use Laravel\Passport\Bridge\User as PassportUser;
use League\OAuth2\Server\RequestTypes\AuthorizationRequest;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use Symfony\Component\HttpFoundation\Response;

class EnsureOAuthApplicationAccess
{
    public function __construct(private readonly ApplicationAccess $access) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('oauth/authorize') || ! in_array($request->method(), ['GET', 'POST'], true)) {
            return $next($request);
        }

        $clientId = $request->isMethod('GET')
            ? $request->input('client_id')
            : $this->authorizationClientId($request);

        if (! is_string($clientId) || $clientId === '') {
            abort(403);
        }

        $environment = $this->access->environmentForClient($clientId);
        if (!$environment instanceof \App\Models\TenantApplicationEnvironment) {
            abort(403);
        }

        $user = $request->user();
        if ($user instanceof User && ! $this->access->allows($environment, $user)) {
            abort(403);
        }

        return $next($request);
    }

    private function authorizationClientId(Request $request): ?string
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

        return $authorization instanceof AuthorizationRequestInterface
            ? $authorization->getClient()->getIdentifier()
            : null;
    }
}
