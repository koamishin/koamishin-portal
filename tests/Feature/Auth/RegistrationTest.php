<?php

use App\Models\User;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\get;
use function Pest\Laravel\head;

test('a configured registration alias redirects direct visitors to the portal', function (): void {
    config([
        'app.url' => 'http://portal.localhost:8120',
        'fortify.registration_alias_origin' => 'http://auth.localhost:8120',
        'fortify.registration_alias_target_url' => null,
    ]);

    get('http://auth.localhost:8120/register')
        ->assertRedirect('http://portal.localhost:8120/register');
    head('http://auth.localhost:8120/register')
        ->assertRedirect('http://portal.localhost:8120/register');
    get('http://portal.localhost:8120/register')->assertOk();
    get('http://other.localhost:8120/register')->assertOk();
});

test('registration alias redirects inertia visits as a full navigation', function (): void {
    config([
        'app.url' => 'http://portal.localhost:8120',
        'fortify.registration_alias_origin' => 'http://auth.localhost:8120',
        'fortify.registration_alias_target_url' => null,
    ]);

    get('http://auth.localhost:8120/register', ['X-Inertia' => 'true'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', 'http://portal.localhost:8120/register');
});

test('a registration alias can start an application authentication flow', function (): void {
    config([
        'app.url' => 'http://portal.localhost:8120',
        'fortify.registration_alias_origin' => 'http://auth.localhost:8120',
        'fortify.registration_alias_target_url' => 'http://app-a.localhost:8121/register',
    ]);

    get('http://auth.localhost:8120/register')
        ->assertRedirect('http://app-a.localhost:8121/register');
    get('http://auth.localhost:8120/register?redirect_uri=https%3A%2F%2Funtrusted.example%2Fcallback')
        ->assertRedirect('http://app-a.localhost:8121/register');
    get('http://auth.localhost:8120/register', ['X-Inertia' => 'true'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', 'http://app-a.localhost:8121/register');
});

test('a misconfigured application registration target fails without redirecting', function (string $target): void {
    config([
        'app.url' => 'http://portal.localhost:8120',
        'fortify.registration_alias_origin' => 'http://auth.localhost:8120',
        'fortify.registration_alias_target_url' => $target,
    ]);

    get('http://auth.localhost:8120/register')->assertStatus(503);
})->with(['javascript:alert(1)', 'https://user:password@example.test/register', 'http://auth.localhost:8120/register?retry=1']);

test('registration alias does not transfer an existing application flow to another host', function (): void {
    config([
        'app.url' => 'http://portal.localhost:8120',
        'fortify.registration_alias_origin' => 'http://auth.localhost:8120',
        'fortify.registration_alias_target_url' => 'http://app-a.localhost:8121/register',
    ]);

    get('http://auth.localhost:8120/register?flow='.str_repeat('x', 40))->assertStatus(419);
});

test('a registration alias equal to the portal does not redirect in a loop', function (): void {
    config([
        'app.url' => 'http://portal.localhost:8120',
        'fortify.registration_alias_origin' => 'http://portal.localhost:8120',
        'fortify.registration_alias_target_url' => null,
    ]);

    get('http://portal.localhost:8120/register')->assertOk();
});

test('registration screen can be rendered', function (): void {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register', function (): void {
    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('registration creates the user role when it is missing', function (): void {
    Role::query()->where('name', 'user')->delete();

    $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect(route('dashboard', absolute: false));

    $user = User::query()->where('email', 'test@example.com')->firstOrFail();

    expect($user->hasRole('user'))->toBeTrue()
        ->and(Role::query()->where('name', 'user')->where('guard_name', 'web')->exists())->toBeTrue();
});
