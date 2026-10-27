<?php

declare(strict_types=1);

use App\Filament\Pages\Tenancy\RegisterTenant;
use App\Filament\Resources\TenantApplications\Pages\CreateTenantApplication;
use App\Filament\Resources\TenantApplications\Pages\ListTenantApplications;
use App\Models\Tenant;
use App\Models\TenantApplication;
use App\Models\User;
use Filament\Facades\Filament;
use Laravel\Passport\Client;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

test('admin can create an organization tenant and applications', function (): void {
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $tenant = Tenant::create([
        'name' => 'Koamishin Global',
        'slug' => 'koamishin-global',
        'domain' => 'global.koamishin.com',
        'is_active' => true,
    ]);

    expect($tenant->id)->not->toBeNull();

    $this->actingAs($admin);

    Filament::setCurrentPanel('admin');
    Filament::setTenant($tenant);
    Filament::bootCurrentPanel();

    // Create development Next.js application
    Livewire::actingAs($admin)
        ->test(CreateTenantApplication::class)
        ->fillForm([
            'name' => 'Marketing WebApp',
            'framework' => 'nextjs',
            'environment' => 'development',
            'redirect_uris' => ['http://localhost:3000/api/auth/callback'],
            'description' => 'Frontend marketing portal',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $app = TenantApplication::where('name', 'Marketing WebApp')->first();
    expect($app)->not->toBeNull()
        ->framework->toBe('nextjs')
        ->environment->toBe('development')
        ->oauth_client_id->not->toBeNull();

    $client = Client::find($app->oauth_client_id);
    expect($client)->not->toBeNull()
        ->name->toBe('Marketing WebApp')
        ->revoked->toBeFalse();

    // Check generated setup guides
    expect($app->getInstallCommand())->toBe('npm install @koamishin/auth-js');
    expect($app->getEnvSnippet())->toContain('NEXT_PUBLIC_KOAMISHIN_CLIENT_ID='.$app->oauth_client_id);
    expect($app->getSetupGuideCode())->toContain("import { createKoamishinClient } from '@koamishin/auth-js';");
});

test('tenant application setup guide generates laravel sdk instructions', function (): void {
    $tenant = Tenant::create([
        'name' => 'Acme Labs',
        'slug' => 'acme-labs',
    ]);

    $client = new Client;
    $client->name = 'Acme Backend API';
    $client->secret = 'testsecret123';
    $client->redirect_uris = ['https://api.acme.com/koamishin/callback'];
    $client->grant_types = ['authorization_code', 'refresh_token'];
    $client->revoked = false;
    $client->save();

    $app = TenantApplication::create([
        'tenant_id' => $tenant->id,
        'oauth_client_id' => $client->id,
        'name' => 'Acme Backend API',
        'framework' => 'laravel',
        'environment' => 'production',
        'redirect_uris' => ['https://api.acme.com/koamishin/callback'],
    ]);

    expect($app->getInstallCommand())->toBe('composer require koamishin/auth-php');
    expect($app->getEnvSnippet())->toContain('KOAMISHIN_CLIENT_ID='.$client->id);
    expect($app->getSetupGuideCode())->toContain('Koamishin::getLoginUrl()');
    expect($app->getSetupGuideCode())->toContain('AuthenticateWithKoamishin');
});

test('user can register a new tenant organization via filament tenancy registration', function (): void {
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole('super_admin');

    $this->actingAs($user);
    Filament::setCurrentPanel('admin');

    Livewire::actingAs($user)
        ->test(RegisterTenant::class)
        ->fillForm([
            'name' => 'New Organization',
            'slug' => 'new-org',
            'domain' => 'neworg.koamishin.com',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $tenant = Tenant::query()->where('slug', 'new-org')->first();
    expect($tenant)->not->toBeNull()
        ->name->toBe('New Organization');

    expect($user->tenants()->whereKey($tenant)->exists())->toBeTrue();
});

test('tenant applications are scoped strictly to the current tenant', function (): void {
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole('super_admin');

    $tenantA = Tenant::create(['name' => 'Org Alpha', 'slug' => 'org-alpha']);
    $tenantB = Tenant::create(['name' => 'Org Beta', 'slug' => 'org-beta']);

    TenantApplication::create([
        'tenant_id' => $tenantA->id,
        'name' => 'Alpha Internal App',
        'framework' => 'nextjs',
        'environment' => 'production',
        'redirect_uris' => ['https://alpha.internal/callback'],
    ]);

    TenantApplication::create([
        'tenant_id' => $tenantB->id,
        'name' => 'Beta Internal App',
        'framework' => 'laravel',
        'environment' => 'development',
        'redirect_uris' => ['http://localhost:8000/callback'],
    ]);

    $this->actingAs($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($tenantA);
    Filament::bootCurrentPanel();

    Livewire::actingAs($user)
        ->test(ListTenantApplications::class)
        ->assertSee('Alpha Internal App')
        ->assertDontSee('Beta Internal App');
});
