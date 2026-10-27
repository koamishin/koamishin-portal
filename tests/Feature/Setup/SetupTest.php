<?php

declare(strict_types=1);

use App\Models\User;
use App\Settings\ApplicationDetailsSettings;
use Spatie\Permission\Models\Role;

test('setup wizard screen is displayed when no super admin exists', function (): void {
    $response = $this->get(route('setup.index'));

    $response->assertOk();
});

test('submitting setup creates super admin user and configures site name', function (): void {
    $response = $this->post(route('setup.store'), [
        'instance_name' => 'Koamishin Cloud Auth',
        'name' => 'Initial Admin',
        'email' => 'admin@koamishin.cloud',
        'password' => 'securePassword123',
        'password_confirmation' => 'securePassword123',
    ]);

    $response->assertRedirect('/admin');

    $admin = User::query()->where('email', 'admin@koamishin.cloud')->first();
    expect($admin)->not->toBeNull()
        ->name->toBe('Initial Admin')
        ->hasRole('super_admin')->toBeTrue();

    expect(auth()->id())->toBe($admin->id);

    $settings = app(ApplicationDetailsSettings::class);
    expect($settings->site_name)->toBe('Koamishin Cloud Auth');
});

test('setup wizard redirects away once a super admin exists', function (): void {
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole('super_admin');

    $response = $this->get(route('setup.index'));

    $response->assertRedirect(route('home'));
});
