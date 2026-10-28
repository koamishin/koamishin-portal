<?php

declare(strict_types=1);

use App\Filament\Pages\Tenancy\RegisterTenant;
use App\Filament\Resources\TenantApplications\Pages\CreateTenantApplication;
use App\Filament\Resources\TenantApplications\Pages\ListTenantApplications;
use App\Models\Tenant;
use App\Models\TenantApplication;
use App\Models\User;
use App\Services\OAuth\ApplicationProvisioner;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
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
        ->name->toBe('Marketing WebApp (Development)')
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
    expect($app->getEnvSnippet())->toContain('KOAMISHIN_APPLICATION_ID='.$app->id);
    expect($app->getSetupGuideCode())->toContain('Koamishin::getLoginUrl(');
    expect($app->getSetupGuideCode())->toContain("->middleware('auth:koamishin')");
    expect($app->getSetupGuideCode())->toContain('Koamishin::consumeReturnTo(');
});

test('provisioning creates independent confidential clients for both environments', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Provision org', 'slug' => 'provision-org']);
    $application = app(ApplicationProvisioner::class)->create($tenant, [
        'name' => 'Backend',
        'framework' => 'laravel',
        'environment' => 'production',
        'redirect_uris' => ['https://backend.example.test/auth/callback'],
    ]);
    $development = $application->environments()->where('environment', 'development')->firstOrFail();
    $production = $application->environments()->where('environment', 'production')->firstOrFail();
    $credentials = session('new_app_credentials');

    expect($development->oauth_client_id)->not->toBe($production->oauth_client_id);
    expect($application->oauth_client_id)->toBe($production->oauth_client_id);
    expect($development->oauthClient->grant_types)->toContain('authorization_code', 'refresh_token');
    expect($production->oauthClient->redirect_uris)->toBe(['https://backend.example.test/auth/callback']);
    expect(Hash::check($credentials['production']['secret'], $production->oauthClient->secret))->toBeTrue();
    expect($credentials['production']['secret'])->not->toBe($credentials['development']['secret']);
});

test('production provisioning refuses an insecure callback without creating a client', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Production org', 'slug' => 'production-org']);
    $clientsBefore = Client::query()->count();

    expect(fn () => app(ApplicationProvisioner::class)->create($tenant, [
        'name' => 'Backend',
        'framework' => 'laravel',
        'environment' => 'production',
        'redirect_uris' => ['http://backend.example.test/auth/callback'],
    ]))->toThrow(ValidationException::class);
    expect(Client::query()->count())->toBe($clientsBefore);
    expect($tenant->applications()->count())->toBe(0);
});

test('legacy client migration binds only unambiguous active clients without replacing existing bindings', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Legacy org', 'slug' => 'legacy-org']);
    $makeClient = fn (string $name): Client => Client::query()->create([
        'name' => $name,
        'secret' => 'secret',
        'redirect_uris' => ['https://example.test/callback'],
        'grant_types' => ['authorization_code', 'refresh_token'],
        'revoked' => false,
    ]);
    $makeApplication = fn (string $name, ?Client $client): TenantApplication => $tenant->applications()->create([
        'name' => $name,
        'framework' => 'laravel',
        'environment' => 'production',
        'redirect_uris' => ['https://example.test/callback'],
        'oauth_client_id' => $client?->getKey(),
    ]);

    $missingClient = $makeClient('missing');
    $missing = $makeApplication('missing', $missingClient);
    $unboundClient = $makeClient('unbound');
    $unbound = $makeApplication('unbound', $unboundClient);
    $unbound->environments()->create(['environment' => 'production', 'redirect_uris' => ['https://existing.test/callback']]);
    $sharedClient = $makeClient('shared');
    $sharedOne = $makeApplication('shared one', $sharedClient);
    $sharedTwo = $makeApplication('shared two', $sharedClient);
    $conflictingClient = $makeClient('conflicting');
    $currentClient = $makeClient('current');
    $conflicting = $makeApplication('conflicting', $conflictingClient);
    $conflicting->environments()->create([
        'environment' => 'production', 'oauth_client_id' => $currentClient->getKey(), 'redirect_uris' => [],
    ]);
    $elsewhereClient = $makeClient('elsewhere');
    $elsewhere = $makeApplication('elsewhere', $elsewhereClient);
    $other = $makeApplication('other', null);
    $other->environments()->create([
        'environment' => 'development', 'oauth_client_id' => $elsewhereClient->getKey(), 'redirect_uris' => [],
    ]);
    $revokedClient = $makeClient('revoked');
    $revokedClient->update(['revoked' => true]);
    $revoked = $makeApplication('revoked', $revokedClient);

    $migration = require database_path('migrations/2026_10_28_065707_backfill_legacy_application_environment_clients.php');
    $migration->up();
    $migration->up();

    expect($missing->environments()->sole()->oauth_client_id)->toBe($missingClient->getKey());
    expect($missing->environments()->sole()->redirect_uris)->toBe(['https://example.test/callback']);
    expect($unbound->environments()->sole()->oauth_client_id)->toBe($unboundClient->getKey());
    expect($unbound->environments()->sole()->redirect_uris)->toBe(['https://existing.test/callback']);
    expect($sharedOne->environments()->count())->toBe(0);
    expect($sharedTwo->environments()->count())->toBe(0);
    expect($conflicting->environments()->sole()->oauth_client_id)->toBe($currentClient->getKey());
    expect($elsewhere->environments()->count())->toBe(0);
    expect($revoked->environments()->count())->toBe(0);
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
