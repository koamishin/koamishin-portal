<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\ApplicationUserMetadata;
use App\Models\Tenant;
use App\Models\TenantApplicationEnvironment;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Client;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\delete;
use function Pest\Laravel\flushHeaders;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;
use function Pest\Laravel\travel;
use function Pest\Laravel\withToken;

/** @return array{Client, TenantApplicationEnvironment} */
function registrationTestEnvironment(string $name = 'signup-app', array $settings = ['allow_signups' => true]): array
{
    $client = new Client;
    $client->forceFill([
        'name' => $name,
        'secret' => 'signup-secret',
        'redirect_uris' => ['https://'.$name.'.internal/callback'],
        'grant_types' => ['authorization_code', 'refresh_token'],
        'revoked' => false,
    ])->save();

    $tenant = Tenant::query()->create(['name' => $name, 'slug' => $name, 'is_active' => true]);
    $application = $tenant->applications()->create([
        'name' => $name,
        'framework' => 'laravel',
        'environment' => 'development',
        'redirect_uris' => $client->getAttribute('redirect_uris'),
        'oauth_client_id' => $client->getKey(),
    ]);
    $environment = $application->environments()->create([
        'environment' => 'development',
        'oauth_client_id' => $client->getKey(),
        'redirect_uris' => $client->getAttribute('redirect_uris'),
        'settings' => $settings,
    ]);

    return [$client, $environment];
}

/** @param array<string, string> $parameters */
function registrationAuthorizeUrl(Client $client, array $parameters = []): string
{
    return '/oauth/authorize?'.http_build_query(array_replace([
        'client_id' => $client->getKey(),
        'redirect_uri' => $client->getAttribute('redirect_uris')[0],
        'response_type' => 'code',
        'state' => 'signup123',
        'scope' => 'openid profile email roles metadata:public',
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', str_repeat('a', 64), true)), '+/', '-_'), '='),
        'code_challenge_method' => 'S256',
    ], $parameters));
}

function registrationFlow(Client $client, bool $signup = true): string
{
    $response = get(registrationAuthorizeUrl($client, $signup ? ['screen_hint' => 'signup'] : []));
    $response->assertRedirect();
    $location = $response->baseResponse->headers->get('Location');
    expect($location)->toContain($signup ? '/register?flow=' : '/login?flow=');
    parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);
    $flow = $query['flow'] ?? null;

    if (! is_string($flow) || $flow === '') {
        throw new LogicException('The application authentication response did not contain an opaque flow ID.');
    }

    return $flow;
}

/** @return array<string, string> */
function registrationInput(string $email = 'new.signup@example.test'): array
{
    return [
        'name' => 'New Signup',
        'email' => $email,
        'password' => 'Password-1234!',
        'password_confirmation' => 'Password-1234!',
    ];
}

/** @return array<string, mixed> */
function registrationPageProps(TestResponse $response): array
{
    $page = $response->viewData('page');

    if (! is_array($page) || ! is_array($page['props'] ?? null)) {
        throw new LogicException('Expected an Inertia application authentication page.');
    }

    return $page['props'];
}

function registrationConsentToken(TestResponse $response): string
{
    $props = registrationPageProps($response);
    $token = $props['authToken'] ?? null;

    if (! is_string($token) || $token === '') {
        throw new LogicException('Expected a Passport consent token.');
    }

    return $token;
}

function registrationAccessToken(Client $client, string $consentToken): string
{
    $approved = post('/oauth/authorize', ['auth_token' => $consentToken]);
    $approved->assertRedirect();
    $location = $approved->baseResponse->headers->get('Location');
    expect($location)->toStartWith($client->getAttribute('redirect_uris')[0]);
    parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $parameters);
    expect($parameters['state'] ?? null)->toBe('signup123');
    $response = postJson('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $client->getKey(),
        'client_secret' => 'signup-secret',
        'redirect_uri' => $client->getAttribute('redirect_uris')[0],
        'code' => $parameters['code'] ?? '',
        'code_verifier' => str_repeat('a', 64),
    ]);
    $response->assertOk()->assertJsonStructure(['access_token', 'refresh_token']);
    $token = $response->json('access_token');

    if (! is_string($token) || $token === '') {
        throw new LogicException('Expected a real OAuth access token.');
    }

    return $token;
}

test('application registration displays its context and resumes OAuth after enrolling the new user', function (): void {
    [$client, $environment] = registrationTestEnvironment();
    $flow = registrationFlow($client);

    $page = get('/register?flow='.$flow)->assertOk();
    $props = registrationPageProps($page);
    expect($props['application'])->toMatchArray([
        'id' => (string) $environment->tenant_application_id,
        'name' => 'signup-app',
        'organization' => 'signup-app',
        'environment' => 'development',
        'allowSignups' => true,
    ])->and($props['flow'])->toBe($flow);

    $response = post('/register', registrationInput() + ['flow' => $flow], ['X-Inertia' => 'true']);
    $response->assertStatus(409)->assertHeader('X-Inertia-Location');
    $resume = $response->baseResponse->headers->get('X-Inertia-Location');
    expect($resume)->toContain('/oauth/authorize?')->toContain('flow='.$flow)->toContain('state=signup123');
    expect(str_contains($resume ?? '', 'screen_hint'))->toBeFalse();

    $user = User::query()->where('email', 'new.signup@example.test')->firstOrFail();
    expect($environment->application->tenant->users()->whereKey($user->getKey())->exists())->toBeTrue();
    expect(DB::table('tenant_application_user')->where('user_id', $user->getKey())->get()->map(fn (object $membership): array => [
        'application' => $membership->tenant_application_id,
        'environment' => $membership->environment,
        'role' => $membership->role,
    ])->all())->toBe([['application' => $environment->tenant_application_id, 'environment' => 'development', 'role' => 'member']]);

    $consent = get((string) $resume)->assertOk();
    $consentProps = registrationPageProps($consent);
    expect($consentProps['joiningApplication'])->toBeFalse()
        ->and($consentProps['csrfToken'])->toBe(session()->token());
});

test('registration outside an application request does not enroll the user anywhere', function (): void {
    registrationTestEnvironment();

    post('/register', registrationInput('portal.signup@example.test'))->assertRedirect();

    $user = User::query()->where('email', 'portal.signup@example.test')->firstOrFail();
    expect(DB::table('tenant_user')->where('user_id', $user->getKey())->exists())->toBeFalse();
    expect(DB::table('tenant_application_user')->where('user_id', $user->getKey())->exists())->toBeFalse();
});

test('registration through one application does not enroll the user in another', function (): void {
    [$client, $environment] = registrationTestEnvironment();
    [, $otherEnvironment] = registrationTestEnvironment('other-signup-app');
    $flow = registrationFlow($client);

    post('/register', registrationInput('scoped.signup@example.test') + ['flow' => $flow])->assertRedirect();

    $user = User::query()->where('email', 'scoped.signup@example.test')->firstOrFail();
    expect(DB::table('tenant_application_user')->where('user_id', $user->getKey())->pluck('tenant_application_id')->all())
        ->toBe([$environment->tenant_application_id]);
    expect($otherEnvironment->application->tenant->users()->whereKey($user->getKey())->exists())->toBeFalse();
});

test('application signup requires an explicit boolean allow signups setting', function (array $settings): void {
    [$client] = registrationTestEnvironment(settings: $settings);

    get(registrationAuthorizeUrl($client, ['screen_hint' => 'signup']))->assertForbidden();

    expect(User::query()->count())->toBe(0);
    expect(DB::table('tenant_application_user')->count())->toBe(0);
})->with([
    'missing policy' => [[]],
    'closed policy' => [['allow_signups' => false]],
    'string policy' => [['allow_signups' => 'true']],
]);

test('invalid OAuth callbacks cannot create a registration flow or enroll a user', function (): void {
    [$client] = registrationTestEnvironment();

    get(registrationAuthorizeUrl($client, ['screen_hint' => 'signup', 'redirect_uri' => 'https://unregistered.internal/callback']))
        ->assertStatus(400);

    expect(session('koamishin.application_flows', []))->toBe([]);
    expect(User::query()->count())->toBe(0);
    expect(DB::table('tenant_application_user')->count())->toBe(0);
});

test('a forged registration flow cannot create a user', function (): void {
    registrationTestEnvironment();

    post('/register', registrationInput() + ['flow' => str_repeat('f', 40)])->assertStatus(419);

    assertDatabaseMissing('users', ['email' => 'new.signup@example.test']);
    expect(DB::table('tenant_application_user')->count())->toBe(0);
});

test('an application flow cannot be rebound to another client', function (): void {
    [$client] = registrationTestEnvironment();
    [$otherClient] = registrationTestEnvironment('other-flow-app');
    $flow = registrationFlow($client);

    get(registrationAuthorizeUrl($otherClient, ['screen_hint' => 'signup', 'flow' => $flow]))->assertStatus(419);

    expect(User::query()->count())->toBe(0);
    expect(DB::table('tenant_application_user')->count())->toBe(0);
});

test('an expired application flow rejects registration without creating a user', function (): void {
    [$client] = registrationTestEnvironment();
    $flow = registrationFlow($client);
    travel(11)->minutes();

    get('/register?flow='.$flow)->assertStatus(419);
    post('/register', registrationInput() + ['flow' => $flow])->assertStatus(419);

    assertDatabaseMissing('users', ['email' => 'new.signup@example.test']);
    expect(DB::table('tenant_application_user')->count())->toBe(0);
});

test('closing registration after the signup page was opened prevents account creation and enrollment', function (): void {
    [$client, $environment] = registrationTestEnvironment();
    $flow = registrationFlow($client);
    $environment->update(['settings' => ['allow_signups' => false]]);

    post('/register', registrationInput() + ['flow' => $flow])->assertForbidden();

    assertDatabaseMissing('users', ['email' => 'new.signup@example.test']);
    expect(DB::table('tenant_application_user')->count())->toBe(0);
});

test('application login resumes through a full browser navigation while enrollment still requires consent', function (): void {
    [$client, $environment] = registrationTestEnvironment();
    $user = User::factory()->create(['password' => 'Password-1234!']);
    $flow = registrationFlow($client, signup: false);
    $props = registrationPageProps(get('/login?flow='.$flow)->assertOk());
    expect($props['application']['id'])->toBe((string) $environment->tenant_application_id);

    $response = post('/login', ['email' => $user->email, 'password' => 'Password-1234!', 'flow' => $flow], ['X-Inertia' => 'true']);
    $response->assertStatus(409)->assertHeader('X-Inertia-Location');
    $resume = $response->baseResponse->headers->get('X-Inertia-Location');
    expect($resume)->toContain('/oauth/authorize?')->toContain('flow='.$flow);
    expect(DB::table('tenant_application_user')->where('user_id', $user->getKey())->exists())->toBeFalse();
});

test('existing users join another open application only after approving and receive its scoped claims', function (): void {
    [$clientA, $environmentA] = registrationTestEnvironment('app-a');
    [$clientB, $environmentB] = registrationTestEnvironment('app-b');
    $flow = registrationFlow($clientA);
    post('/register', registrationInput() + ['flow' => $flow])->assertRedirect();
    $user = User::query()->where('email', 'new.signup@example.test')->firstOrFail();
    $user->update(['metadata' => ['legacy_secret' => 'never-share']]);
    ApplicationUserMetadata::query()->create([
        'tenant_application_environment_id' => $environmentA->getKey(),
        'user_id' => $user->getKey(),
        'public_metadata' => ['theme' => 'dark'],
        'private_metadata' => ['internal_secret' => 'app-a-private'],
    ]);
    ApplicationUserMetadata::query()->create([
        'tenant_application_environment_id' => $environmentB->getKey(),
        'user_id' => $user->getKey(),
        'public_metadata' => ['theme' => 'light'],
        'private_metadata' => ['internal_secret' => 'app-b-private'],
    ]);

    $consentA = get(registrationAuthorizeUrl($clientA))->assertOk();
    $tokenA = registrationAccessToken($clientA, registrationConsentToken($consentA));
    Auth::forgetGuards();
    withToken($tokenA)->getJson('/api/oauth/userinfo')->assertOk()
        ->assertJsonPath('sub', (string) $user->getKey())
        ->assertJsonPath('application_id', (string) $environmentA->tenant_application_id)
        ->assertJsonPath('public_metadata.theme', 'dark')
        ->assertJsonMissingPath('private_metadata')
        ->assertJsonMissingPath('metadata');
    flushHeaders();
    Auth::forgetGuards();

    $consentB = actingAs($user, 'web')->get(registrationAuthorizeUrl($clientB))->assertOk();
    expect(registrationPageProps($consentB)['joiningApplication'])->toBeTrue();
    expect($environmentB->application->tenant->users()->whereKey($user->getKey())->exists())->toBeFalse();
    expect($environmentB->application->users('development')->whereKey($user->getKey())->exists())->toBeFalse();

    $tokenB = registrationAccessToken($clientB, registrationConsentToken($consentB));
    expect($environmentB->application->tenant->users()->whereKey($user->getKey())->exists())->toBeTrue();
    expect($environmentB->application->users('development')->whereKey($user->getKey())->exists())->toBeTrue();
    Auth::forgetGuards();
    withToken($tokenB)->getJson('/api/oauth/userinfo')->assertOk()
        ->assertJsonPath('sub', (string) $user->getKey())
        ->assertJsonPath('application_id', (string) $environmentB->tenant_application_id)
        ->assertJsonPath('public_metadata.theme', 'light')
        ->assertJsonMissingPath('private_metadata')
        ->assertJsonMissingPath('metadata');
});

test('previously authorized applications use a full browser redirect for an Inertia authorization request', function (): void {
    [$client] = registrationTestEnvironment();
    $user = User::factory()->create();
    $consent = actingAs($user)->get(registrationAuthorizeUrl($client))->assertOk();
    registrationAccessToken($client, registrationConsentToken($consent));
    $version = app(HandleInertiaRequests::class)->version(Request::create('/'));

    $response = get(registrationAuthorizeUrl($client), ['X-Inertia' => 'true', 'X-Inertia-Version' => $version ?? '']);

    $response->assertStatus(409)->assertHeader('X-Inertia-Location');
    $location = $response->baseResponse->headers->get('X-Inertia-Location');
    expect($location)->toStartWith($client->getAttribute('redirect_uris')[0])->toContain('code=')->toContain('state=signup123');
});

test('an invalid Passport consent token does not enroll an existing user', function (): void {
    [$client, $environment] = registrationTestEnvironment();
    $user = User::factory()->create();
    actingAs($user)->get(registrationAuthorizeUrl($client))->assertOk();

    post('/oauth/authorize', ['auth_token' => 'forged-consent'])->assertStatus(400);

    expect($environment->application->tenant->users()->whereKey($user->getKey())->exists())->toBeFalse();
    expect($environment->application->users('development')->whereKey($user->getKey())->exists())->toBeFalse();
});

test('declining authorization does not enroll an existing user in the application', function (): void {
    [$client, $environment] = registrationTestEnvironment();
    $user = User::factory()->create();
    $consent = actingAs($user)->get(registrationAuthorizeUrl($client))->assertOk();

    $denied = delete('/oauth/authorize', ['auth_token' => registrationConsentToken($consent)]);
    $denied->assertRedirect();
    expect($denied->baseResponse->headers->get('Location'))->toContain('error=access_denied');
    expect($environment->application->tenant->users()->whereKey($user->getKey())->exists())->toBeFalse();
    expect($environment->application->users('development')->whereKey($user->getKey())->exists())->toBeFalse();
});

test('a missing CSRF token prevents enrollment even with a valid Passport consent token', function (): void {
    [$client, $environment] = registrationTestEnvironment();
    $user = User::factory()->create();
    $consent = actingAs($user)->get(registrationAuthorizeUrl($client))->assertOk();
    app()->bind(PreventRequestForgery::class, EnforceApplicationRegistrationCsrf::class);

    post('/oauth/authorize', ['auth_token' => registrationConsentToken($consent)])->assertStatus(419);

    expect($environment->application->tenant->users()->whereKey($user->getKey())->exists())->toBeFalse();
    expect($environment->application->users('development')->whereKey($user->getKey())->exists())->toBeFalse();
});

test('changing the signed in account between consent and approval prevents enrollment', function (): void {
    [$client, $environment] = registrationTestEnvironment();
    $first = User::factory()->create();
    $second = User::factory()->create();
    $consent = actingAs($first)->get(registrationAuthorizeUrl($client))->assertOk();

    actingAs($second)->post('/oauth/authorize', ['auth_token' => registrationConsentToken($consent)])->assertForbidden();

    expect($environment->application->users('development')->count())->toBe(0);
    expect($environment->application->tenant->users()->count())->toBe(0);
});

test('an existing user cannot join an application with closed or missing signup policy', function (array $settings): void {
    [$client, $environment] = registrationTestEnvironment(settings: $settings);
    $user = User::factory()->create();

    actingAs($user)->get(registrationAuthorizeUrl($client))->assertForbidden();

    expect($environment->application->users('development')->whereKey($user->getKey())->exists())->toBeFalse();
})->with([
    'closed application' => [['allow_signups' => false]],
    'missing signup policy' => [[]],
]);

class EnforceApplicationRegistrationCsrf extends PreventRequestForgery
{
    protected function runningUnitTests(): bool
    {
        return false;
    }
}
