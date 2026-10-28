<?php

declare(strict_types=1);

use App\Jobs\DispatchWebhookJob;
use App\Models\ApplicationUserMetadata;
use App\Models\ManagementApiClient;
use App\Models\Tenant;
use App\Models\TenantApplication;
use App\Models\TenantApplicationEnvironment;
use App\Models\User;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;

/** @return array{Tenant, TenantApplication, TenantApplicationEnvironment} */
function managementApiApplication(string $slug, string $environment = 'development'): array
{
    $tenant = Tenant::query()->create(['name' => $slug, 'slug' => $slug, 'is_active' => true]);
    $application = TenantApplication::query()->create([
        'tenant_id' => $tenant->getKey(),
        'name' => $slug.' app',
        'framework' => 'laravel',
        'environment' => $environment,
        'redirect_uris' => [],
    ]);
    $config = $application->environments()->create([
        'environment' => $environment,
        'redirect_uris' => [],
        'settings' => [],
    ]);

    return [$tenant, $application, $config];
}

function enrollManagementUser(Tenant $tenant, TenantApplication $application, User $user, string $environment): void
{
    $tenant->users()->attach($user, ['role' => 'member']);
    $application->users()->attach($user, ['role' => 'member', 'environment' => $environment]);
}

/** @param list<string> $allowedScopes @param list<string> $requestedScopes @return array{Client, string} */
function managementApiToken(TenantApplicationEnvironment $environment, array $allowedScopes, array $requestedScopes): array
{
    $client = app(ClientRepository::class)->createClientCredentialsGrantClient('management test');
    ManagementApiClient::query()->create([
        'tenant_application_environment_id' => $environment->getKey(),
        'oauth_client_id' => $client->getKey(),
        'allowed_scopes' => $allowedScopes,
        'is_active' => true,
    ]);

    $response = test()->postJson('/oauth/token', [
        'grant_type' => 'client_credentials',
        'client_id' => $client->getKey(),
        'client_secret' => $client->plainSecret,
        'scope' => implode(' ', $requestedScopes),
    ]);
    $response->assertOk();

    return [$client, (string) $response->json('access_token')];
}

test('management API requires client credentials', function (): void {
    $this->getJson('/api/v1/users')->assertUnauthorized();

    $user = User::factory()->create();
    Passport::actingAs($user, ['users:read']);
    $this->getJson('/api/v1/users')->assertUnauthorized();
});

test('management user reads are limited to the bound application and environment', function (): void {
    [$tenant, $application, $development] = managementApiApplication('alpha');
    [$otherTenant, $otherApplication] = managementApiApplication('beta');
    $included = User::factory()->create(['metadata' => ['secret' => 'global']]);
    $otherTenantUser = User::factory()->create();
    $otherEnvironmentUser = User::factory()->create();
    $notTenantMember = User::factory()->create();

    enrollManagementUser($tenant, $application, $included, 'development');
    enrollManagementUser($otherTenant, $otherApplication, $otherTenantUser, 'development');
    enrollManagementUser($tenant, $application, $otherEnvironmentUser, 'production');
    $application->users()->attach($notTenantMember, ['role' => 'member', 'environment' => 'development']);
    [, $token] = managementApiToken($development, ['users:read'], ['users:read']);

    $this->withToken($token)->getJson('/api/v1/users?per_page=1')
        ->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', (string) $included->id)
        ->assertJsonPath('meta.per_page', 1)
        ->assertJsonMissingPath('data.0.metadata');
    $this->withToken($token)->getJson('/api/v1/users/'.$included->id)->assertOk();
    foreach ([$otherTenantUser, $otherEnvironmentUser, $notTenantMember] as $user) {
        $this->withToken($token)->getJson('/api/v1/users/'.$user->id)->assertNotFound();
    }
    $this->withToken($token)->getJson('/api/v1/users?per_page=101')->assertUnprocessable();
});

test('management API requires both a token scope and an explicit client grant', function (): void {
    [, , $environment] = managementApiApplication('scope-check');
    [, $ungranted] = managementApiToken($environment, ['metadata:read'], ['users:read']);
    [, $unrequested] = managementApiToken($environment, ['users:read'], ['metadata:read']);

    $this->withToken($ungranted)->getJson('/api/v1/users')->assertForbidden();
    $this->withToken($unrequested)->getJson('/api/v1/users')->assertForbidden();
});

test('management credentials can only read their bound organization with explicit scope', function (): void {
    [$tenant, $application, $environment] = managementApiApplication('org-context');
    [, $token] = managementApiToken($environment, ['organization:read'], ['organization:read']);
    [, $insufficient] = managementApiToken($environment, ['users:read'], ['users:read']);

    $this->withToken($insufficient)->getJson('/api/v1/organization')->assertForbidden();
    $this->withToken($token)->getJson('/api/v1/organization')->assertOk()->assertExactJson(['data' => [
        'id' => (string) $tenant->id,
        'name' => $tenant->name,
        'slug' => $tenant->slug,
        'application_id' => (string) $application->id,
        'environment' => 'development',
    ]]);
});

test('unbound revoked or inactive management clients cannot access the API', function (): void {
    [$tenant, , $environment] = managementApiApplication('revocations');
    $unbound = app(ClientRepository::class)->createClientCredentialsGrantClient('unbound');
    $unboundToken = $this->postJson('/oauth/token', [
        'grant_type' => 'client_credentials',
        'client_id' => $unbound->getKey(),
        'client_secret' => $unbound->plainSecret,
        'scope' => 'users:read',
    ])->assertOk()->json('access_token');
    $this->withToken($unboundToken)->getJson('/api/v1/users')->assertForbidden();

    [$client, $token] = managementApiToken($environment, ['users:read'], ['users:read']);
    $binding = ManagementApiClient::query()->where('oauth_client_id', $client->getKey())->firstOrFail();
    $binding->update(['is_active' => false]);
    $this->withToken($token)->getJson('/api/v1/users')->assertForbidden();
    $binding->update(['is_active' => true]);
    $tenant->update(['is_active' => false]);
    $this->withToken($token)->getJson('/api/v1/users')->assertForbidden();
    $tenant->update(['is_active' => true]);
    $client->update(['revoked' => true]);
    $this->withToken($token)->getJson('/api/v1/users')->assertForbidden();

    [$secondClient, $secondToken] = managementApiToken($environment, ['users:read'], ['users:read']);
    Token::query()->where('client_id', $secondClient->getKey())->update(['revoked' => true]);
    $this->withToken($secondToken)->getJson('/api/v1/users')->assertUnauthorized();
});

test('metadata is scoped to an application environment and replaces only supplied buckets', function (): void {
    [$tenant, $application, $development] = managementApiApplication('metadata');
    [$otherTenant, $otherApplication, $otherEnvironment] = managementApiApplication('metadata-other');
    $user = User::factory()->create(['metadata' => ['global' => 'not shared']]);
    enrollManagementUser($tenant, $application, $user, 'development');
    enrollManagementUser($otherTenant, $otherApplication, $user, 'development');
    $scopes = ['metadata:read', 'metadata:write'];
    [, $firstToken] = managementApiToken($development, $scopes, $scopes);
    [, $secondToken] = managementApiToken($otherEnvironment, $scopes, $scopes);
    $path = '/api/v1/users/'.$user->id.'/metadata';

    $this->withToken($firstToken)->patchJson($path, [
        'public' => ['language' => 'en'],
        'private' => ['employee_id' => '123'],
    ])->assertOk()->assertJsonPath('data.public.language', 'en')->assertJsonPath('data.private.employee_id', '123');

    $this->withToken($secondToken)->getJson($path)
        ->assertOk()->assertExactJson(['data' => ['public' => [], 'private' => []]]);
    $this->withToken($firstToken)->patchJson($path, ['public' => (object) []])
        ->assertOk()->assertJsonPath('data.private.employee_id', '123')
        ->assertJsonMissingPath('data.public.language');
    expect(ApplicationUserMetadata::query()->count())->toBe(1);
    expect($user->refresh()->metadata)->toBe(['global' => 'not shared']);
});

test('metadata writes require read and write scopes and valid JSON objects', function (): void {
    [$tenant, $application, $environment] = managementApiApplication('metadata-validation');
    $user = User::factory()->create();
    enrollManagementUser($tenant, $application, $user, 'development');
    [, $readOnly] = managementApiToken($environment, ['metadata:read'], ['metadata:read']);
    [, $writeOnly] = managementApiToken($environment, ['metadata:write'], ['metadata:write']);
    [, $token] = managementApiToken($environment, ['metadata:read', 'metadata:write'], ['metadata:read', 'metadata:write']);
    $path = '/api/v1/users/'.$user->id.'/metadata';

    $this->withToken($readOnly)->patchJson($path, ['public' => ['x' => 1]])->assertForbidden();
    $this->withToken($writeOnly)->patchJson($path, ['public' => ['x' => 1]])->assertForbidden();
    $this->withToken($token)->patchJson($path, ['public' => []])->assertUnprocessable();
    $this->withToken($token)->patchJson($path, ['unexpected' => ['x' => 1]])->assertUnprocessable();
    $this->withToken($token)->patchJson($path, ['public' => ['x' => str_repeat('x', 17000)]])->assertStatus(413);
    expect(ApplicationUserMetadata::query()->count())->toBe(0);
});

test('management credentials must be provisioned with explicit supported scopes', function (): void {
    [, $application] = managementApiApplication('provision');
    $this->artisan('koamishin:management-client', [
        'application' => $application->getKey(),
        'environment' => 'development',
    ])->assertFailed();
    $this->artisan('koamishin:management-client', [
        'application' => $application->getKey(),
        'environment' => 'development',
        '--scope' => ['users:read'],
    ])->assertSuccessful();

    $binding = ManagementApiClient::query()->with('oauthClient')->firstOrFail();
    expect($binding->allowed_scopes)->toBe(['users:read']);
    expect($binding->oauthClient->grant_types)->toBe(['client_credentials']);
    expect($binding->oauthClient->secret)->not->toBeEmpty();
});

test('metadata updates queue only the matching application webhook with public metadata', function (): void {
    [$tenant, $application, $environment] = managementApiApplication('webhook-metadata');
    [, , $otherEnvironment] = managementApiApplication('webhook-other');
    $user = User::factory()->create(['metadata' => ['legacy_secret' => 'hidden']]);
    enrollManagementUser($tenant, $application, $user, 'development');
    $endpoint = WebhookEndpoint::query()->create([
        'name' => 'Application', 'url' => 'https://app.example/webhook', 'secret' => 'secret',
        'tenant_application_environment_id' => $environment->id, 'events' => ['user.updated'], 'is_active' => true,
    ]);
    WebhookEndpoint::query()->create([
        'name' => 'Other', 'url' => 'https://other.example/webhook', 'secret' => 'secret',
        'tenant_application_environment_id' => $otherEnvironment->id, 'events' => ['user.updated'], 'is_active' => true,
    ]);
    [, $token] = managementApiToken($environment, ['metadata:read', 'metadata:write'], ['metadata:read', 'metadata:write']);
    Queue::fake([DispatchWebhookJob::class]);

    $path = '/api/v1/users/'.$user->id.'/metadata';
    $this->withToken($token)->patchJson($path, ['public' => [], 'private' => ['secret' => 'hidden']])->assertUnprocessable();
    Queue::assertNotPushed(DispatchWebhookJob::class);
    $this->withToken($token)->patchJson($path, ['public' => ['plan' => 'pro'], 'private' => ['secret' => 'hidden']])->assertOk();

    Queue::assertPushed(DispatchWebhookJob::class, 1);
    Queue::assertPushed(DispatchWebhookJob::class, fn (DispatchWebhookJob $job): bool => $job->endpoint->is($endpoint)
        && $job->event === 'user.updated'
        && $job->payload['data']['public_metadata']->plan === 'pro'
        && ! array_key_exists('private_metadata', $job->payload['data'])
        && ! array_key_exists('metadata', $job->payload['data']));
});

test('management sessions are application-scoped and revoking one also revokes its refresh token', function (): void {
    [$tenant, $application, $environment] = managementApiApplication('sessions');
    [$otherTenant, $otherApplication, $otherEnvironment] = managementApiApplication('sessions-other');
    $user = User::factory()->create();
    enrollManagementUser($tenant, $application, $user, 'development');
    enrollManagementUser($otherTenant, $otherApplication, $user, 'development');
    $clients = app(ClientRepository::class);
    $client = $clients->createAuthorizationCodeGrantClient('application', ['https://app.example/callback']);
    $otherClient = $clients->createAuthorizationCodeGrantClient('other', ['https://other.example/callback']);
    $environment->update(['oauth_client_id' => $client->getKey()]);
    $otherEnvironment->update(['oauth_client_id' => $otherClient->getKey()]);
    $activeToken = Token::query()->create([
        'id' => str_repeat('a', 80), 'user_id' => $user->id, 'client_id' => $client->id,
        'scopes' => ['openid'], 'revoked' => false, 'expires_at' => now()->addHour(),
    ]);
    $otherToken = Token::query()->create([
        'id' => str_repeat('b', 80), 'user_id' => $user->id, 'client_id' => $otherClient->id,
        'scopes' => ['openid'], 'revoked' => false, 'expires_at' => now()->addHour(),
    ]);
    $refreshToken = RefreshToken::query()->create([
        'id' => str_repeat('c', 80), 'access_token_id' => $activeToken->id,
        'revoked' => false, 'expires_at' => now()->addDay(),
    ]);
    [, $token] = managementApiToken($environment, ['sessions:read', 'sessions:revoke'], ['sessions:read', 'sessions:revoke']);
    [, $readOnly] = managementApiToken($environment, ['sessions:read'], ['sessions:read']);
    $path = '/api/v1/users/'.$user->id.'/sessions';

    $this->withToken($token)->getJson($path)->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $activeToken->id)->assertJsonPath('data.0.access_revoked', false);
    $this->withToken($readOnly)->deleteJson($path.'/'.$activeToken->id)->assertForbidden();
    $this->withToken($token)->deleteJson($path.'/'.$otherToken->id)->assertNotFound();
    expect($otherToken->refresh()->revoked)->toBeFalse();
    $this->withToken($token)->deleteJson($path.'/'.$activeToken->id)->assertNoContent();
    expect($activeToken->refresh()->revoked)->toBeTrue();
    expect($refreshToken->refresh()->revoked)->toBeTrue();
    $this->withToken($token)->getJson($path)->assertOk()->assertJsonCount(0, 'data');
});

test('session operations fail closed without a unique authorization client', function (): void {
    [$tenant, $application, $environment] = managementApiApplication('sessions-unconfigured');
    $user = User::factory()->create();
    enrollManagementUser($tenant, $application, $user, 'development');
    [, $token] = managementApiToken($environment, ['sessions:read'], ['sessions:read']);

    $this->withToken($token)->getJson('/api/v1/users/'.$user->id.'/sessions')->assertStatus(409);
});

test('membership management is confined to organization members and one application environment', function (): void {
    [$tenant, $application, $environment] = managementApiApplication('membership');
    [$otherTenant, $otherApplication, $otherEnvironment] = managementApiApplication('membership-other');
    $user = User::factory()->create();
    $stranger = User::factory()->create();
    $tenant->users()->attach($user, ['role' => 'member']);
    enrollManagementUser($otherTenant, $otherApplication, $user, 'development');
    $clients = app(ClientRepository::class);
    $client = $clients->createAuthorizationCodeGrantClient('application', ['https://app.example/callback']);
    $otherClient = $clients->createAuthorizationCodeGrantClient('other', ['https://other.example/callback']);
    $environment->update(['oauth_client_id' => $client->getKey()]);
    $otherEnvironment->update(['oauth_client_id' => $otherClient->getKey()]);
    $endpoint = WebhookEndpoint::query()->create([
        'name' => 'Application', 'url' => 'https://app.example/webhook', 'secret' => 'secret',
        'tenant_application_environment_id' => $environment->id, 'events' => ['user.created'], 'is_active' => true,
    ]);
    [, $token] = managementApiToken($environment, ['users:read', 'memberships:write'], ['users:read', 'memberships:write']);
    [, $readOnly] = managementApiToken($environment, ['users:read'], ['users:read']);
    Queue::fake([DispatchWebhookJob::class]);

    $path = '/api/v1/users/'.$user->id.'/membership';
    $this->withToken($readOnly)->putJson($path)->assertForbidden();
    $this->withToken($token)->putJson('/api/v1/users/'.$stranger->id.'/membership')->assertNotFound();
    $this->withToken($token)->putJson($path)->assertNoContent();
    $this->withToken($token)->putJson($path)->assertNoContent();
    Queue::assertPushed(DispatchWebhookJob::class, 1);
    Queue::assertPushed(DispatchWebhookJob::class, fn (DispatchWebhookJob $job): bool => $job->endpoint->is($endpoint) && $job->event === 'user.created');
    $this->withToken($token)->getJson('/api/v1/users/'.$user->id)->assertOk();

    ApplicationUserMetadata::query()->create([
        'tenant_application_environment_id' => $environment->id, 'user_id' => $user->id,
        'public_metadata' => ['tier' => 'standard'], 'private_metadata' => [],
    ]);
    $activeToken = Token::query()->create([
        'id' => str_repeat('d', 80), 'user_id' => $user->id, 'client_id' => $client->id,
        'scopes' => ['openid'], 'revoked' => false, 'expires_at' => now()->addHour(),
    ]);
    $otherToken = Token::query()->create([
        'id' => str_repeat('e', 80), 'user_id' => $user->id, 'client_id' => $otherClient->id,
        'scopes' => ['openid'], 'revoked' => false, 'expires_at' => now()->addHour(),
    ]);
    $refreshToken = RefreshToken::query()->create([
        'id' => str_repeat('f', 80), 'access_token_id' => $activeToken->id,
        'revoked' => false, 'expires_at' => now()->addDay(),
    ]);

    $this->withToken($token)->deleteJson($path)->assertNoContent();
    $this->withToken($token)->getJson('/api/v1/users/'.$user->id)->assertNotFound();
    $this->withToken($token)->deleteJson($path)->assertNotFound();
    expect($activeToken->refresh()->revoked)->toBeTrue();
    expect($refreshToken->refresh()->revoked)->toBeTrue();
    expect($otherToken->refresh()->revoked)->toBeFalse();
    expect($otherApplication->users('development')->whereKey($user->id)->exists())->toBeTrue();
    expect($tenant->users()->whereKey($user->id)->exists())->toBeTrue();
    $this->assertDatabaseHas('application_user_metadata', ['tenant_application_environment_id' => $environment->id, 'user_id' => $user->id]);
});

test('removing membership refuses an OAuth client shared with another environment', function (): void {
    [$tenant, $application, $environment] = managementApiApplication('membership-ambiguous');
    [, , $otherEnvironment] = managementApiApplication('membership-ambiguous-other');
    $user = User::factory()->create();
    enrollManagementUser($tenant, $application, $user, 'development');
    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient('shared', ['https://app.example/callback']);
    $environment->update(['oauth_client_id' => $client->id]);
    $otherEnvironment->update(['oauth_client_id' => $client->id]);
    [, $token] = managementApiToken($environment, ['memberships:write'], ['memberships:write']);

    $this->withToken($token)->deleteJson('/api/v1/users/'.$user->id.'/membership')->assertStatus(409);
    expect($application->users('development')->whereKey($user->id)->exists())->toBeTrue();
});

test('revoking an OAuth grant rejects its real refresh token', function (): void {
    [$tenant, $application, $environment] = managementApiApplication('sessions-real-flow');
    $user = User::factory()->create();
    enrollManagementUser($tenant, $application, $user, 'development');
    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient('application', ['https://app.example/callback']);
    $environment->update(['oauth_client_id' => $client->id]);
    $verifier = str_repeat('a', 64);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    $authorization = $this->actingAs($user)->get('/oauth/authorize?'.http_build_query([
        'client_id' => $client->id,
        'redirect_uri' => 'https://app.example/callback',
        'response_type' => 'code',
        'scope' => 'openid',
        'state' => 'testing',
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ]))->assertOk();
    $approval = $this->post('/oauth/authorize', [
        'state' => 'testing', 'client_id' => $client->id,
        'auth_token' => $authorization->original->getData()['page']['props']['authToken'],
    ])->assertRedirect();
    parse_str((string) parse_url((string) $approval->headers->get('Location'), PHP_URL_QUERY), $query);
    $grant = $this->postJson('/oauth/token', [
        'grant_type' => 'authorization_code', 'client_id' => $client->id, 'client_secret' => $client->plainSecret,
        'redirect_uri' => 'https://app.example/callback', 'code' => $query['code'], 'code_verifier' => $verifier,
    ])->assertOk();
    [, $managementToken] = managementApiToken($environment, ['sessions:read', 'sessions:revoke'], ['sessions:read', 'sessions:revoke']);
    $path = '/api/v1/users/'.$user->id.'/sessions';
    $sessionId = $this->withToken($managementToken)->getJson($path)->assertOk()->json('data.0.id');

    $this->withToken($managementToken)->deleteJson($path.'/'.$sessionId)->assertNoContent();
    $this->postJson('/oauth/token', [
        'grant_type' => 'refresh_token', 'client_id' => $client->id,
        'client_secret' => $client->plainSecret, 'refresh_token' => $grant->json('refresh_token'),
    ])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
});
