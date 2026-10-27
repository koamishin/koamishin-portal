<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
    public function userinfo(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'sub' => (string) $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified' => $user->hasVerifiedEmail(),
            'picture' => $user->profile_photo_path ? url('storage/'.$user->profile_photo_path) : null,
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
            'updated_at' => $user->updated_at?->timestamp,
        ]);
    }
}
