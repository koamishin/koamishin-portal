<?php

declare(strict_types=1);

use App\Models\ApplicationUserMetadata;
use App\Models\Tenant;
use App\Models\TenantApplicationEnvironment;
use App\Models\User;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;

/** @return array{Client, TenantApplicationEnvironment} */
function oauthTestEnvironment(User $user, string $name = 'test', string $environment = 'development'): array
{
    $client = new Client;
    $client->name = $name;
    $client->secret = 'super-secret-key';
    $client->redirect_uris = ['https://testapp.internal/callback'];
    $client->grant_types = ['authorization_code', 'refresh_token'];
    $client->revoked = false;
    $client->save();

    $tenant = Tenant::query()->create(['name' => $name, 'slug' => $name, 'is_active' => true]);
    $application = $tenant->applications()->create([
        'name' => $name,
        'framework' => 'laravel',
        'environment' => $environment,
        'redirect_uris' => $client->redirect_uris,
        'oauth_client_id' => $client->getKey(),
    ]);
    $configuration = $application->environments()->create([
        'environment' => $environment,
        'oauth_client_id' => $client->getKey(),
        'redirect_uris' => $client->redirect_uris,
    ]);
    $tenant->users()->attach($user);
    $application->users()->attach($user, ['environment' => $environment, 'role' => 'member']);

    return [$client, $configuration];
}

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

    [$client] = oauthTestEnvironment($user);
    Passport::actingAs($user, ['openid', 'profile', 'email'], 'api', $client);

    $response = $this->getJson('/api/oauth/userinfo');

    $response->assertOk()
        ->assertJsonPath('name', 'Koamishin Engineer')
        ->assertJsonPath('email', 'engineer@koamishin.com')
        ->assertJsonPath('sub', (string) $user->id);
});

test('full oauth2 authorization code flow can be executed end to end', function (): void {
    $user = User::factory()->create();

    [$client] = oauthTestEnvironment($user);

    // 1. Request authorization view
    $authPage = $this->actingAs($user)->get(
        "/oauth/authorize?client_id={$client->id}&redirect_uri=https://testapp.internal/callback&response_type=code&state=flow123&scope=openid profile email"
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

    $user->ban('Account disabled');
    $this->postJson('/oauth/token', [
        'grant_type' => 'refresh_token',
        'client_id' => $client->id,
        'client_secret' => 'super-secret-key',
        'refresh_token' => $tokenResponse->json('refresh_token'),
    ])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
});

test('userinfo exposes only requested and environment-scoped claims', function (): void {
    $user = User::factory()->create(['metadata' => ['global_secret' => 'never-expose']]);
    [$client, $environment] = oauthTestEnvironment($user);
    ApplicationUserMetadata::query()->create([
        'tenant_application_environment_id' => $environment->id,
        'user_id' => $user->id,
        'public_metadata' => ['plan' => 'standard'],
        'private_metadata' => ['internal' => 'never-expose'],
    ]);

    Passport::actingAs($user, ['openid', 'metadata:public', 'roles'], 'api', $client);

    $this->getJson('/api/oauth/userinfo')
        ->assertOk()
        ->assertJsonPath('public_metadata.plan', 'standard')
        ->assertJsonPath('roles.0', 'member')
        ->assertJsonMissingPath('private_metadata')
        ->assertJsonMissingPath('metadata')
        ->assertJsonMissingPath('name')
        ->assertJsonMissingPath('email');
});

test('userinfo refuses a token when its application or organization membership is missing', function (string $missingMembership): void {
    $user = User::factory()->create();
    [$client, $environment] = oauthTestEnvironment($user);

    if ($missingMembership === 'organization') {
        $environment->application->tenant->users()->detach($user);
    } else {
        $environment->application->users('development')->detach($user);
        $environment->application->users()->attach($user, ['environment' => 'production', 'role' => 'member']);
    }

    Passport::actingAs($user, ['openid'], 'api', $client);

    $this->getJson('/api/oauth/userinfo')->assertForbidden();
    $this->getJson('/api/user')->assertForbidden();
})->with(['organization', 'application environment']);

test('userinfo refuses revoked clients, inactive organizations, and banned users', function (string $condition): void {
    $user = User::factory()->create();
    [$client, $environment] = oauthTestEnvironment($user);

    match ($condition) {
        'revoked client' => $client->update(['revoked' => true]),
        'inactive organization' => $environment->application->tenant->update(['is_active' => false]),
        'banned user' => $user->forceFill(['banned_at' => now()])->save(),
    };

    Passport::actingAs($user, ['openid'], 'api', $client);

    $this->getJson('/api/oauth/userinfo')->assertForbidden();
})->with(['revoked client', 'inactive organization', 'banned user']);

test('userinfo refuses a client that is bound to more than one application environment', function (): void {
    $user = User::factory()->create();
    [$client, $environment] = oauthTestEnvironment($user);
    $otherApplication = $environment->application->tenant->applications()->create([
        'name' => 'Other application',
        'framework' => 'laravel',
        'environment' => 'development',
        'redirect_uris' => $client->redirect_uris,
        'oauth_client_id' => $client->getKey(),
    ]);
    $otherApplication->environments()->create([
        'environment' => 'development',
        'oauth_client_id' => $client->getKey(),
        'redirect_uris' => $client->redirect_uris,
    ]);

    Passport::actingAs($user, ['openid'], 'api', $client);

    $this->getJson('/api/oauth/userinfo')->assertForbidden();
});

test('authorization approval is refused if application membership changes after consent is displayed', function (): void {
    $user = User::factory()->create();
    [$client, $environment] = oauthTestEnvironment($user);

    $response = $this->actingAs($user)->get(
        "/oauth/authorize?client_id={$client->id}&redirect_uri=https://testapp.internal/callback&response_type=code&state=flow123&scope=openid"
    );
    $response->assertOk();
    $authToken = $response->original->getData()['page']['props']['authToken'];
    $environment->application->users('development')->detach($user);

    $this->post('/oauth/authorize', [
        'state' => 'flow123',
        'client_id' => $client->id,
        'auth_token' => $authToken,
    ])->assertForbidden();
});

test('authorization refuses an application without environment membership before showing consent', function (): void {
    $user = User::factory()->create();
    [$client, $environment] = oauthTestEnvironment($user);
    $environment->application->users('development')->detach($user);

    $this->actingAs($user)->get(
        "/oauth/authorize?client_id={$client->id}&redirect_uri=https://testapp.internal/callback&response_type=code&state=flow123&scope=openid"
    )->assertForbidden();
});
