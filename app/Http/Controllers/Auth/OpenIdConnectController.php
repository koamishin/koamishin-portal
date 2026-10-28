<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ApplicationUserMetadata;
use App\Models\User;
use App\Services\OAuth\ApplicationAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\AccessToken;

class OpenIdConnectController extends Controller
{
    /**
     * Return OpenID Connect discovery document.
     */
    public function discovery(): JsonResponse
    {
        $baseUrl = rtrim(config('app.url'), '/');

        return response()->json([
            'issuer' => $baseUrl,
            'authorization_endpoint' => "{$baseUrl}/oauth/authorize",
            'token_endpoint' => "{$baseUrl}/oauth/token",
            'userinfo_endpoint' => "{$baseUrl}/api/oauth/userinfo",
            'jwks_uri' => "{$baseUrl}/oauth/jwks",
            'scopes_supported' => ['openid', 'profile', 'email', 'roles'],
            'response_types_supported' => ['code'],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post', 'none'],
            'claims_supported' => ['sub', 'name', 'email', 'email_verified', 'picture', 'roles', 'permissions', 'updated_at'],
            'code_challenge_methods_supported' => ['S256'],
        ]);
    }

    /**
     * Return JSON Web Key Set (JWKS) with public RSA key.
     */
    public function jwks(): JsonResponse
    {
        $keyPath = storage_path('oauth-public.key');

        if (! file_exists($keyPath)) {
            return response()->json(['keys' => []], 500);
        }

        $publicKey = file_get_contents($keyPath);
        $res = openssl_pkey_get_public($publicKey);

        if ($res === false) {
            return response()->json(['keys' => []], 500);
        }

        $details = openssl_pkey_get_details($res);

        if (! isset($details['rsa']['n'], $details['rsa']['e'])) {
            return response()->json(['keys' => []], 500);
        }

        $modulus = rtrim(strtr(base64_encode($details['rsa']['n']), '+/', '-_'), '=');
        $exponent = rtrim(strtr(base64_encode($details['rsa']['e']), '+/', '-_'), '=');

        return response()->json([
            'keys' => [
                [
                    'kty' => 'RSA',
                    'alg' => 'RS256',
                    'use' => 'sig',
                    'kid' => hash('sha256', $publicKey),
                    'n' => $modulus,
                    'e' => $exponent,
                ],
            ],
        ]);
    }

    /**
     * Return authenticated user profile and identity claims.
     */
    public function userinfo(Request $request, ApplicationAccess $access): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $token = $user->token();

        if (! $token instanceof AccessToken || ($token->oauth_client_id ?? '') === '') {
            abort(403);
        }

        $environment = $access->environmentForClient($token->oauth_client_id);
        if (! $environment || ! $access->allows($environment, $user)) {
            abort(403);
        }

        $application = $environment->application;
        $claims = [
            'sub' => (string) $user->id,
            'organization_id' => (string) $application->tenant_id,
            'application_id' => (string) $application->getKey(),
            'environment' => $environment->environment,
        ];

        if ($token->can('profile')) {
            $claims += [
                'name' => $user->name,
                'preferred_username' => $user->username,
                'picture' => $user->profile_photo_path ? url('storage/'.$user->profile_photo_path) : null,
                'updated_at' => $user->updated_at?->timestamp,
            ];
        }

        if ($token->can('email')) {
            $claims += [
                'email' => $user->email,
                'email_verified' => $user->hasVerifiedEmail(),
            ];
        }

        if ($token->can('phone')) {
            $claims += [
                'phone_number' => $user->phone_number,
                'phone_number_verified' => $user->hasVerifiedPhone(),
            ];
        }

        if ($token->can('wallet')) {
            $claims['web3_wallet'] = $user->web3_wallet;
        }

        if ($token->can('roles')) {
            $role = DB::table('tenant_application_user')
                ->where('tenant_application_id', $application->getKey())
                ->where('environment', $environment->environment)
                ->where('user_id', $user->getKey())
                ->value('role');
            abort_unless(is_string($role) && $role !== '', 403);

            $claims['roles'] = [$role];
            $claims['permissions'] = [];
        }

        if ($token->can('metadata:public')) {
            $metadata = ApplicationUserMetadata::query()
                ->where('tenant_application_environment_id', $environment->getKey())
                ->where('user_id', $user->getKey())
                ->first();
            $claims['public_metadata'] = $metadata ? $metadata->public_metadata ?? (object) [] : (object) [];
        }

        return response()->json($claims);
    }
}
