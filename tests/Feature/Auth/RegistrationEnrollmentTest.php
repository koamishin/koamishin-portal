<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\ApplicationUserMetadata;
use App\Models\Tenant;
use App\Models\TenantApplicationEnvironment;
use App\Models\User;
use App\Settings\ApplicationFeaturesSettings;
use App\Settings\SocialLoginSettings;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Client;
use Laravel\Socialite\Facades\Socialite;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
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

function registrationAccessToken(Client $client, string $consentToken, string $flow): string
{
    $approved = post('/oauth/authorize', ['auth_token' => $consentToken, 'flow' => $flow]);
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

/** @return array{string, TestResponse} */
function registrationLogin(Client $client, User $user, string $password = 'password'): array
{
    $flow = registrationFlow($client, signup: false);
    $response = post('/login', ['email' => $user->email, 'password' => $password, 'flow' => $flow]);
    $response->assertRedirect();

    return [$flow, $response];
}

function enableApplicationSocialLogin(): void
{
    artisan('migrate', ['--path' => 'database/settings', '--no-interaction' => true]);
    app()->forgetInstance(SocialLoginSettings::class);
    $settings = app(SocialLoginSettings::class);
    $settings->github_enabled = true;
    $settings->github_client_id = 'test-id';
    $settings->github_client_secret = 'test-secret';
    $settings->save();
    app()->forgetInstance(SocialLoginSettings::class);
}

function applicationFeatureSettings(array $values): void
{
    $settings = app(ApplicationFeaturesSettings::class);
    foreach ($values as $name => $value) {
        $settings->{$name} = $value;
    }
    app()->instance(ApplicationFeaturesSettings::class, $settings);
}

test('application registration displays its context and resumes OAuth after enrolling the new user', function (): void {
    applicationFeatureSettings(['email_verification_required' => false]);
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
    applicationFeatureSettings(['email_verification_required' => false]);
    [$clientA, $environmentA] = registrationTestEnvironment('app-a');
    [$clientB, $environmentB] = registrationTestEnvironment('app-b');
    $flow = registrationFlow($clientA);
    $signup = post('/register', registrationInput() + ['flow' => $flow])->assertRedirect();
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

    $consentA = get((string) $signup->headers->get('Location'))->assertOk();
    $tokenA = registrationAccessToken($clientA, registrationConsentToken($consentA), $flow);
    Auth::forgetGuards();
    withToken($tokenA)->getJson('/api/oauth/userinfo')->assertOk()
        ->assertJsonPath('sub', (string) $user->getKey())
        ->assertJsonPath('application_id', (string) $environmentA->tenant_application_id)
        ->assertJsonPath('public_metadata.theme', 'dark')
        ->assertJsonMissingPath('private_metadata')
        ->assertJsonMissingPath('metadata');
    flushHeaders();
    Auth::forgetGuards();

    [$flowB, $loginB] = registrationLogin($clientB, $user, 'Password-1234!');
    $consentB = get((string) $loginB->headers->get('Location'))->assertOk();
    expect(registrationPageProps($consentB)['joiningApplication'])->toBeTrue();
    expect($environmentB->application->tenant->users()->whereKey($user->getKey())->exists())->toBeFalse();
    expect($environmentB->application->users('development')->whereKey($user->getKey())->exists())->toBeFalse();

    $tokenB = registrationAccessToken($clientB, registrationConsentToken($consentB), $flowB);
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
    [$flow, $login] = registrationLogin($client, $user);
    $consent = get((string) $login->headers->get('Location'))->assertOk();
    registrationAccessToken($client, registrationConsentToken($consent), $flow);
    $version = app(HandleInertiaRequests::class)->version(Request::create('/'));

    [, $login] = registrationLogin($client, $user);
    $response = get((string) $login->headers->get('Location'), ['X-Inertia' => 'true', 'X-Inertia-Version' => $version ?? '']);

    $response->assertStatus(409)->assertHeader('X-Inertia-Location');
    $location = $response->baseResponse->headers->get('X-Inertia-Location');
    expect($location)->toStartWith($client->getAttribute('redirect_uris')[0])->toContain('code=')->toContain('state=signup123');
});

test('an invalid Passport consent token does not enroll an existing user', function (): void {
    [$client, $environment] = registrationTestEnvironment();
    $user = User::factory()->create();
    [$flow, $login] = registrationLogin($client, $user);
    get((string) $login->headers->get('Location'))->assertOk();

    post('/oauth/authorize', ['auth_token' => 'forged-consent', 'flow' => $flow])->assertStatus(400);

    expect($environment->application->tenant->users()->whereKey($user->getKey())->exists())->toBeFalse();
    expect($environment->application->users('development')->whereKey($user->getKey())->exists())->toBeFalse();
});

test('declining authorization does not enroll an existing user in the application', function (): void {
    [$client, $environment] = registrationTestEnvironment();
    $user = User::factory()->create();
    [$flow, $login] = registrationLogin($client, $user);
    $consent = get((string) $login->headers->get('Location'))->assertOk();

    $denied = delete('/oauth/authorize', ['auth_token' => registrationConsentToken($consent), 'flow' => $flow]);
    $denied->assertRedirect();
    expect($denied->baseResponse->headers->get('Location'))->toContain('error=access_denied');
    expect($environment->application->tenant->users()->whereKey($user->getKey())->exists())->toBeFalse();
    expect($environment->application->users('development')->whereKey($user->getKey())->exists())->toBeFalse();
});

test('a missing CSRF token prevents enrollment even with a valid Passport consent token', function (): void {
    [$client, $environment] = registrationTestEnvironment();
    $user = User::factory()->create();
    [$flow, $login] = registrationLogin($client, $user);
    $consent = get((string) $login->headers->get('Location'))->assertOk();
    app()->bind(PreventRequestForgery::class, EnforceApplicationRegistrationCsrf::class);

    post('/oauth/authorize', ['auth_token' => registrationConsentToken($consent), 'flow' => $flow])->assertStatus(419);

    expect($environment->application->tenant->users()->whereKey($user->getKey())->exists())->toBeFalse();
    expect($environment->application->users('development')->whereKey($user->getKey())->exists())->toBeFalse();
});

test('changing the signed in account between consent and approval prevents enrollment', function (): void {
    [$client, $environment] = registrationTestEnvironment();
    $first = User::factory()->create();
    $second = User::factory()->create();
    [$flow, $login] = registrationLogin($client, $first);
    $consent = get((string) $login->headers->get('Location'))->assertOk();
    session()->put('login_application_'.$flow.'_'.sha1(SessionGuard::class), $second->getKey());

    post('/oauth/authorize', ['auth_token' => registrationConsentToken($consent), 'flow' => $flow])->assertForbidden();

    expect($environment->application->users('development')->count())->toBe(0);
    expect($environment->application->tenant->users()->count())->toBe(0);
});

test('an existing user cannot join an application with closed or missing signup policy', function (array $settings): void {
    [$client, $environment] = registrationTestEnvironment(settings: $settings);
    $user = User::factory()->create();

    [, $login] = registrationLogin($client, $user);
    get((string) $login->headers->get('Location'))->assertForbidden();

    expect($environment->application->users('development')->whereKey($user->getKey())->exists())->toBeFalse();
})->with([
    'closed application' => [['allow_signups' => false]],
    'missing signup policy' => [[]],
]);

test('a portal session does not sign in to another application and remains unchanged', function (): void {
    [$client] = registrationTestEnvironment('separate-login');
    $administrator = User::factory()->create();
    $applicationUser = User::factory()->create();
    actingAs($administrator, 'web');

    $flow = registrationFlow($client, signup: false);
    $page = registrationPageProps(get('/login?flow='.$flow)->assertOk());
    expect($page['application']['name'])->toBe('separate-login');
    expect(Auth::guard('web')->id())->toBe($administrator->getKey());

    $login = post('/login', ['flow' => $flow, 'email' => $applicationUser->email, 'password' => 'password'])->assertRedirect();
    $consent = get((string) $login->headers->get('Location'))->assertOk();
    expect(registrationPageProps($consent)['user']['id'])->toBe($applicationUser->getKey());
    expect(Auth::guard('web')->id())->toBe($administrator->getKey());
});

test('registration for an application remains available while the portal is signed in', function (): void {
    applicationFeatureSettings(['email_verification_required' => false]);
    [$client, $environment] = registrationTestEnvironment('signed-in-signup');
    $administrator = User::factory()->create();
    actingAs($administrator, 'web');
    $flow = registrationFlow($client);

    get('/register?flow='.$flow)->assertOk();
    $registration = post('/register', registrationInput('separate@example.test') + ['flow' => $flow])->assertRedirect();

    $user = User::query()->where('email', 'separate@example.test')->firstOrFail();
    expect($environment->application->users('development')->whereKey($user->getKey())->exists())->toBeTrue();
    expect(Auth::guard('web')->id())->toBe($administrator->getKey());
    get((string) $registration->headers->get('Location'))->assertOk();
});

test('wrong credentials do not authenticate an application flow', function (): void {
    [$client] = registrationTestEnvironment();
    $user = User::factory()->create();
    $flow = registrationFlow($client, signup: false);

    post('/login', ['flow' => $flow, 'email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
    $authorize = session('koamishin.application_flows.'.$flow.'.authorize_url');
    get($authorize)->assertRedirect(route('login', ['flow' => $flow]));
});

test('signing in to one application does not authenticate a different application flow', function (): void {
    [$clientA] = registrationTestEnvironment('login-a');
    [$clientB] = registrationTestEnvironment('login-b');
    $user = User::factory()->create();
    [, $loginA] = registrationLogin($clientA, $user);
    get((string) $loginA->headers->get('Location'))->assertOk();

    $flowB = registrationFlow($clientB, signup: false);
    $authorizeB = session('koamishin.application_flows.'.$flowB.'.authorize_url');

    get('/login?flow='.$flowB)->assertOk();
    get($authorizeB)->assertRedirect(route('login', ['flow' => $flowB]));
    expect(session('koamishin.application_flows.'.$flowB))->not->toBeNull();
});

test('application flows isolate consent and cannot be replayed after completion', function (): void {
    [$clientA] = registrationTestEnvironment('parallel-a');
    [$clientB] = registrationTestEnvironment('parallel-b');
    $user = User::factory()->create();
    [$flowA, $loginA] = registrationLogin($clientA, $user);
    [$flowB, $loginB] = registrationLogin($clientB, $user);
    $consentA = get((string) $loginA->headers->get('Location'))->assertOk();
    $consentB = get((string) $loginB->headers->get('Location'))->assertOk();

    post('/oauth/authorize', ['flow' => $flowB, 'auth_token' => registrationConsentToken($consentA)])->assertStatus(400);
    registrationAccessToken($clientB, registrationConsentToken($consentB), $flowB);
    registrationAccessToken($clientA, registrationConsentToken($consentA), $flowA);
    get((string) $loginA->headers->get('Location'))->assertStatus(419);
    post('/oauth/authorize', ['flow' => $flowA, 'auth_token' => registrationConsentToken($consentA)])->assertStatus(419);
});

test('silent authorization cannot reuse a portal session', function (): void {
    [$client] = registrationTestEnvironment();
    actingAs(User::factory()->create(), 'web');

    $response = get(registrationAuthorizeUrl($client, ['prompt' => 'none']))->assertRedirect();

    expect($response->headers->get('Location'))->toStartWith($client->getAttribute('redirect_uris')[0])
        ->toContain('error=login_required')->toContain('state=signup123');
});

test('two factor authentication is required before an application flow can continue', function (): void {
    [$client] = registrationTestEnvironment();
    $user = User::factory()->twoFactorConfirmed()->create([
        'two_factor_secret' => encrypt('test-secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code'])),
    ]);
    $flow = registrationFlow($client, signup: false);

    post('/login', ['flow' => $flow, 'email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.login', ['flow' => $flow]));
    $authorize = session('koamishin.application_flows.'.$flow.'.authorize_url');
    get($authorize)->assertRedirect(route('login', ['flow' => $flow]));
    get('/two-factor-challenge?flow='.$flow)->assertOk();
    post('/two-factor-challenge', ['flow' => $flow, 'recovery_code' => 'incorrect'])->assertSessionHasErrors();
    $response = post('/two-factor-challenge', ['flow' => $flow, 'recovery_code' => 'recovery-code'])->assertRedirect();
    expect($response->headers->get('Location'))->toContain('flow='.$flow);
    get((string) $response->headers->get('Location'))->assertOk();
});

test('two simultaneous two factor challenges remain bound to their own users and applications', function (): void {
    [$clientA] = registrationTestEnvironment('mfa-a');
    [$clientB] = registrationTestEnvironment('mfa-b');
    $first = User::factory()->twoFactorConfirmed()->create([
        'two_factor_secret' => encrypt('secret-a'),
        'two_factor_recovery_codes' => encrypt(json_encode(['recovery-a'])),
    ]);
    $second = User::factory()->twoFactorConfirmed()->create([
        'two_factor_secret' => encrypt('secret-b'),
        'two_factor_recovery_codes' => encrypt(json_encode(['recovery-b'])),
    ]);
    $flowA = registrationFlow($clientA, signup: false);
    $flowB = registrationFlow($clientB, signup: false);
    post('/login', ['flow' => $flowA, 'email' => $first->email, 'password' => 'password'])->assertRedirect();
    post('/login', ['flow' => $flowB, 'email' => $second->email, 'password' => 'password'])->assertRedirect();

    post('/two-factor-challenge', ['flow' => $flowA, 'recovery_code' => 'recovery-b'])->assertSessionHasErrors();
    $completedB = post('/two-factor-challenge', ['flow' => $flowB, 'recovery_code' => 'recovery-b'])->assertRedirect();
    $completedA = post('/two-factor-challenge', ['flow' => $flowA, 'recovery_code' => 'recovery-a'])->assertRedirect();

    expect(registrationPageProps(get((string) $completedA->headers->get('Location'))->assertOk())['user']['id'])->toBe($first->id);
    expect(registrationPageProps(get((string) $completedB->headers->get('Location'))->assertOk())['user']['id'])->toBe($second->id);
});

test('application passkey challenges do not reuse the portal or another application challenge', function (): void {
    [$clientA] = registrationTestEnvironment('passkey-a');
    [$clientB] = registrationTestEnvironment('passkey-b');
    $administrator = User::factory()->create();
    actingAs($administrator, 'web');
    $flowA = registrationFlow($clientA, signup: false);
    $flowB = registrationFlow($clientB, signup: false);

    $optionsA = get('/passkeys/login/options?flow='.$flowA)->assertOk()->json('options.challenge');
    $optionsB = get('/passkeys/login/options?flow='.$flowB)->assertOk()->json('options.challenge');

    expect($optionsA)->toBeString();
    expect($optionsA)->not->toBe($optionsB);
    expect(session('koamishin.application_state.'.$flowA.'.passkey.verification_options'))->toBeNull();
    expect(session('koamishin.application_state.'.$flowA)['passkey.verification_options'])->not->toBeNull();
    expect(session('koamishin.application_state.'.$flowB)['passkey.verification_options'])->not->toBeNull();
    expect(Auth::guard('web')->id())->toBe($administrator->getKey());
});

test('social login returns to its application flow without changing the portal session', function (): void {
    enableApplicationSocialLogin();
    [$client] = registrationTestEnvironment('social-flow');
    $administrator = User::factory()->create();
    $user = User::factory()->create();
    actingAs($administrator, 'web');
    $flow = registrationFlow($client, signup: false);

    $redirect = get('/auth/github/redirect?flow='.$flow)->assertRedirect();
    parse_str((string) parse_url((string) $redirect->headers->get('Location'), PHP_URL_QUERY), $query);
    get('/auth/github/callback?state=invalid&code=test')->assertRedirect(route('login'));
    expect(Auth::guard('web')->id())->toBe($administrator->id);
    $socialUser = (new Laravel\Socialite\Two\User)->map([
        'id' => 'social-user', 'name' => $user->name, 'email' => $user->email,
        'nickname' => 'social-user', 'avatar' => null,
    ]);
    $socialUser->setToken('test-token');
    Socialite::fake('github', $socialUser);

    $callback = get('/auth/github/callback?state='.$query['state'].'&code=test')->assertRedirect();
    expect($callback->headers->get('Location'))->toContain('flow='.$flow);
    expect(registrationPageProps(get((string) $callback->headers->get('Location'))->assertOk())['user']['id'])->toBe($user->id);
    expect(Auth::guard('web')->id())->toBe($administrator->getKey());
});

test('social login cannot create a new account when application signup is closed', function (): void {
    enableApplicationSocialLogin();
    [$client, $environment] = registrationTestEnvironment('social-closed', ['allow_signups' => false]);
    $flow = registrationFlow($client, signup: false);
    $redirect = get('/auth/github/redirect?flow='.$flow)->assertRedirect();
    parse_str((string) parse_url((string) $redirect->headers->get('Location'), PHP_URL_QUERY), $query);
    $socialUser = (new Laravel\Socialite\Two\User)->map([
        'id' => 'new-social', 'name' => 'New user', 'email' => 'new-social@example.test',
        'nickname' => 'new-social', 'avatar' => null,
    ]);
    $socialUser->setToken('test-token');
    Socialite::fake('github', $socialUser);

    get('/auth/github/callback?state='.$query['state'].'&code=test')->assertForbidden();

    assertDatabaseMissing('users', ['email' => 'new-social@example.test']);
    expect($environment->application->users('development')->count())->toBe(0);
});

test('prompt login does not destroy the portal session or the application flow', function (): void {
    [$client] = registrationTestEnvironment('prompt-login');
    $administrator = User::factory()->create();
    $user = User::factory()->create();
    actingAs($administrator, 'web');
    $start = get(registrationAuthorizeUrl($client, ['prompt' => 'login']))->assertRedirect();
    parse_str((string) parse_url((string) $start->headers->get('Location'), PHP_URL_QUERY), $query);
    $flow = $query['flow'];

    $login = post('/login', ['flow' => $flow, 'email' => $user->email, 'password' => 'password'])->assertRedirect();
    get((string) $login->headers->get('Location'))->assertOk();
    expect(Auth::guard('web')->id())->toBe($administrator->id);
});

test('required verification resumes the originating application after the signed email link', function (): void {
    applicationFeatureSettings(['email_verification_required' => true]);
    Notification::fake();
    [$client] = registrationTestEnvironment('verify-required');
    $flow = registrationFlow($client);

    $registration = post('/register', registrationInput('verify-flow@example.test') + ['flow' => $flow])->assertRedirect();
    $user = User::query()->where('email', 'verify-flow@example.test')->firstOrFail();
    $notifications = Notification::sent($user, VerifyEmail::class);
    expect($notifications)->toHaveCount(1);
    $link = $notifications->first()->toMail($user)->actionUrl;
    expect($link)->toContain('flow='.$flow);

    $authorize = (string) $registration->headers->get('Location');
    get($authorize)->assertRedirect(route('verification.notice', ['flow' => $flow]));
    $notice = get(route('verification.notice', ['flow' => $flow]))->assertOk();
    expect(registrationPageProps($notice)['flow'])->toBe($flow);
    post('/email/verification-notification', ['flow' => $flow])->assertRedirect();
    Notification::assertSentToTimes($user, VerifyEmail::class, 2);
    $verified = get($link)->assertRedirect($authorize);
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    get((string) $verified->headers->get('Location'))->assertOk();
});

test('disabled email verification permits application authorization without sending verification mail', function (): void {
    applicationFeatureSettings(['email_verification_required' => false]);
    Notification::fake();
    [$client] = registrationTestEnvironment('verify-disabled');
    $flow = registrationFlow($client);

    $registration = post('/register', registrationInput('no-verify@example.test') + ['flow' => $flow])->assertRedirect();
    $user = User::query()->where('email', 'no-verify@example.test')->firstOrFail();

    Notification::assertNotSentTo($user, VerifyEmail::class);
    expect($user->hasVerifiedEmail())->toBeFalse();
    get((string) $registration->headers->get('Location'))->assertOk();
});

test('the portal dashboard permits an unverified account when verification is disabled', function (): void {
    applicationFeatureSettings(['email_verification_required' => false]);
    $user = User::factory()->unverified()->create();

    actingAs($user)->get('/dashboard')->assertOk();
});

test('an existing unverified user receives a verification link before accessing an application', function (): void {
    applicationFeatureSettings(['email_verification_required' => true]);
    Notification::fake();
    [$client] = registrationTestEnvironment('verify-login');
    $user = User::factory()->unverified()->create();
    [$flow, $login] = registrationLogin($client, $user);
    $authorize = (string) $login->headers->get('Location');

    get($authorize)->assertRedirect(route('verification.notice', ['flow' => $flow]));
    get($authorize)->assertRedirect(route('verification.notice', ['flow' => $flow]));
    Notification::assertSentToTimes($user, VerifyEmail::class, 1);
});

test('turning off verification lets a pending application flow continue', function (): void {
    applicationFeatureSettings(['email_verification_required' => true]);
    Notification::fake();
    [$client] = registrationTestEnvironment('verify-toggle');
    $user = User::factory()->unverified()->create();
    [$flow, $login] = registrationLogin($client, $user);
    $authorize = (string) $login->headers->get('Location');
    get($authorize)->assertRedirect(route('verification.notice', ['flow' => $flow]));
    applicationFeatureSettings(['email_verification_required' => false]);

    get(route('verification.notice', ['flow' => $flow]))->assertRedirect($authorize);
    get($authorize)->assertOk();
});

test('an expired application verification link can be used after signing into the portal', function (): void {
    applicationFeatureSettings(['email_verification_required' => true]);
    Notification::fake();
    [$client] = registrationTestEnvironment('verify-expired');
    $flow = registrationFlow($client);
    post('/register', registrationInput('expired-link@example.test') + ['flow' => $flow])->assertRedirect();
    $user = User::query()->where('email', 'expired-link@example.test')->firstOrFail();
    $link = Notification::sent($user, VerifyEmail::class)->first()->toMail($user)->actionUrl;
    travel(11)->minutes();

    actingAs($user, 'web')->get($link)->assertRedirect(route('dashboard', absolute: false).'?verified=1');

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    get('/register?flow='.$flow)->assertStatus(419);
});

test('turning on verification after consent was displayed prevents an unverified grant', function (): void {
    applicationFeatureSettings(['email_verification_required' => false]);
    [$client, $environment] = registrationTestEnvironment('verify-late');
    $user = User::factory()->unverified()->create();
    [$flow, $login] = registrationLogin($client, $user);
    $consent = get((string) $login->headers->get('Location'))->assertOk();
    applicationFeatureSettings(['email_verification_required' => true]);

    post('/oauth/authorize', ['flow' => $flow, 'auth_token' => registrationConsentToken($consent)])->assertForbidden();

    expect($environment->application->users('development')->whereKey($user->getKey())->exists())->toBeFalse();
});

test('a verification link cannot be used by a different application flow', function (): void {
    applicationFeatureSettings(['email_verification_required' => true]);
    Notification::fake();
    [$clientA] = registrationTestEnvironment('verify-a');
    [$clientB] = registrationTestEnvironment('verify-b');
    $flowA = registrationFlow($clientA);
    post('/register', registrationInput('verify-a@example.test') + ['flow' => $flowA])->assertRedirect();
    $user = User::query()->where('email', 'verify-a@example.test')->firstOrFail();
    $link = Notification::sent($user, VerifyEmail::class)->first()->toMail($user)->actionUrl;
    $flowB = registrationFlow($clientB, signup: false);
    $otherUser = User::factory()->create();
    post('/login', ['flow' => $flowB, 'email' => $otherUser->email, 'password' => 'password'])->assertRedirect();

    get(str_replace('flow='.$flowA, 'flow='.$flowB, $link))->assertForbidden();
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('registration and password reset switches are enforced on their endpoints', function (): void {
    applicationFeatureSettings(['registration_enabled' => false, 'password_reset_enabled' => false]);
    [$client] = registrationTestEnvironment('features-disabled');

    get('/register')->assertForbidden();
    post('/register', registrationInput())->assertForbidden();
    get(registrationAuthorizeUrl($client, ['screen_hint' => 'signup']))->assertForbidden();
    get('/forgot-password')->assertForbidden();
    post('/forgot-password', ['email' => 'nobody@example.test'])->assertForbidden();
    $props = registrationPageProps(get('/login')->assertOk());
    expect($props['canRegister'])->toBeFalse()->and($props['canResetPassword'])->toBeFalse();
    assertDatabaseMissing('users', ['email' => 'new.signup@example.test']);
});

test('disabling two factor setup retains existing account challenges', function (): void {
    applicationFeatureSettings(['two_factor_authentication_enabled' => false]);
    $user = User::factory()->twoFactorConfirmed()->create([
        'two_factor_secret' => encrypt('test-secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code'])),
    ]);
    [$client] = registrationTestEnvironment('mfa-disabled');
    $flow = registrationFlow($client, signup: false);

    post('/login', ['flow' => $flow, 'email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.login', ['flow' => $flow]));
    actingAs($user)->post('/user/two-factor-authentication')->assertForbidden();
});

test('disabled Portal notifications reject notification center requests', function (): void {
    applicationFeatureSettings(['notifications_enabled' => false]);
    $user = User::factory()->create();

    actingAs($user)->getJson('/notifications')->assertForbidden();
    $page = get('/dashboard')->assertOk();
    expect(registrationPageProps($page)['notificationsEnabled'])->toBeFalse();
});

test('disabled activity logging does not record user updates', function (): void {
    applicationFeatureSettings(['activity_log_enabled' => false]);
    $user = User::factory()->create();

    $user->update(['name' => 'Updated Name']);

    expect(DB::table('activity_log')->where('subject_type', User::class)->where('subject_id', $user->id)->count())->toBe(0);
});

test('default user role is applied to password registration', function (): void {
    applicationFeatureSettings(['default_user_role' => 'member', 'email_verification_required' => false]);

    post('/register', registrationInput('role@example.test'))->assertRedirect();

    expect(User::query()->where('email', 'role@example.test')->firstOrFail()->hasRole('member'))->toBeTrue();
});

test('the impersonation setting controls whether an administrator may impersonate users', function (): void {
    $user = User::factory()->create();
    $user->assignRole(Role::query()->firstOrCreate(['name' => 'admin', 'guard_name' => 'web']));
    applicationFeatureSettings(['user_impersonation_enabled' => true]);
    expect($user->canImpersonate())->toBeTrue();

    applicationFeatureSettings(['user_impersonation_enabled' => false]);
    expect($user->canImpersonate())->toBeFalse();
});

class EnforceApplicationRegistrationCsrf extends PreventRequestForgery
{
    protected function runningUnitTests(): bool
    {
        return false;
    }
}
