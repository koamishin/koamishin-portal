<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;

test('openid configuration endpoint returns valid oidc discovery metadata', function (): void {
    $response = $this->getJson('/.well-known/openid-configuration');

    $response->assertOk()
        ->assertJsonStructure([
            'issuer',
            'authorization_endpoint',
            'token_endpoint',
            'userinfo_endpoint',
            'jwks_uri',
            'scopes_supported',
            'response_types_supported',
            'subject_types_supported',
            'id_token_signing_alg_values_supported',
            'claims_supported',
        ]);
});

test('jwks endpoint returns valid rsa public key set', function (): void {
    $response = $this->getJson('/oauth/jwks');

    $response->assertOk()
        ->assertJsonStructure([
            'keys' => [
                [
                    'kty',
                    'alg',
                    'use',
                    'kid',
                    'n',
                    'e',
                ],
            ],
        ]);
});

test('userinfo endpoint returns claims for authenticated bearer token', function (): void {
    $user = User::factory()->create([
        'name' => 'Koamishin Engineer',
        'email' => 'engineer@koamishin.com',
    ]);

    Passport::actingAs($user, ['openid', 'profile', 'email']);

    $response = $this->getJson('/api/oauth/userinfo');

    $response->assertOk()
        ->assertJsonPath('name', 'Koamishin Engineer')
        ->assertJsonPath('email', 'engineer@koamishin.com')
        ->assertJsonPath('sub', (string) $user->id);
});

test('full oauth2 authorization code flow can be executed end to end', function (): void {
    $user = User::factory()->create();

    $client = new Client;
    $client->name = 'Internal Test App';
    $client->secret = 'super-secret-key';
    $client->redirect_uris = ['https://testapp.internal/callback'];
    $client->grant_types = ['authorization_code', 'refresh_token'];
    $client->revoked = false;
    $client->save();

    // 1. Request authorization view
    $authPage = $this->actingAs($user)->get(
        "/oauth/authorize?client_id={$client->id}&redirect_uri=https://testapp.internal/callback&response_type=code&state=flow123&scope=openid profile"
    );
    $authPage->assertOk();

    $authToken = $authPage->original->getData()['page']['props']['authToken'];

    // 2. Approve authorization
    $approve = $this->actingAs($user)->post('/oauth/authorize', [
        'state' => 'flow123',
        'client_id' => $client->id,
        'auth_token' => $authToken,
    ]);

    $approve->assertRedirect();
    parse_str((string) parse_url((string) $approve->headers->get('Location'), PHP_URL_QUERY), $query);
    $code = $query['code'];
    expect($code)->not->toBeEmpty();

    // 3. Exchange code for access token
    $tokenResponse = $this->postJson('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $client->id,
        'client_secret' => 'super-secret-key',
        'redirect_uri' => 'https://testapp.internal/callback',
        'code' => $code,
    ]);

    $tokenResponse->assertOk()
        ->assertJsonStructure(['token_type', 'expires_in', 'access_token', 'refresh_token']);

    $accessToken = $tokenResponse->json('access_token');

    // 4. Fetch userinfo with access token
    $userinfo = $this->withToken($accessToken)->getJson('/api/oauth/userinfo');
    $userinfo->assertOk()
        ->assertJsonPath('email', $user->email);
});
