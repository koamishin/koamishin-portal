<?php

declare(strict_types=1);

use App\Filament\Pages\Tenancy\RegisterTenant;
use App\Filament\Resources\TenantApplications\Pages\CreateTenantApplication;
use App\Filament\Resources\TenantApplications\Pages\EditTenantApplication;
use App\Filament\Resources\TenantApplications\Pages\ListTenantApplications;
use App\Models\Tenant;
use App\Models\TenantApplication;
use App\Models\User;
use App\Services\OAuth\ApplicationProvisioner;
use App\Settings\ApplicationFeaturesSettings;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Client;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;

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
    expect($development->oauthClient->getAttribute('grant_types'))->toContain('authorization_code', 'refresh_token');
    expect($production->oauthClient->getAttribute('redirect_uris'))->toBe(['https://backend.example.test/auth/callback']);
    expect(Hash::check($credentials['production']['secret'], $production->oauthClient->getAttribute('secret')))->toBeTrue();
    expect($credentials['production']['secret'])->not->toBe($credentials['development']['secret']);
    expect($development->getSetting('allow_signups'))->toBeTrue();
    expect($production->getSetting('allow_signups'))->toBeFalse();
});

test('provisioning respects an explicit self-service signup policy for the selected environment', function (string $environment, bool $allowSignups): void {
    $tenant = Tenant::query()->create(['name' => 'Policy org', 'slug' => 'policy-org']);

    $application = app(ApplicationProvisioner::class)->create($tenant, [
        'name' => 'Policy app',
        'framework' => 'laravel',
        'environment' => $environment,
        'redirect_uris' => ['https://app.example.test/callback'],
        'allow_signups' => $allowSignups,
    ]);

    expect($application->environments()->where('environment', $environment)->sole()->getSetting('allow_signups'))->toBe($allowSignups);
})->with([
    'closed development' => ['development', false],
    'open production' => ['production', true],
]);

test('editing an application saves signup policy only for the selected environment and preserves other settings', function (): void {
    Role::query()->firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $tenant = Tenant::query()->create(['name' => 'Edit policy org', 'slug' => 'edit-policy-org']);
    $application = app(ApplicationProvisioner::class)->create($tenant, [
        'name' => 'Edit policy app',
        'framework' => 'laravel',
        'environment' => 'development',
        'redirect_uris' => ['http://localhost:8000/callback'],
    ]);
    $development = $application->environments()->where('environment', 'development')->sole();
    $production = $application->environments()->where('environment', 'production')->sole();
    $development->update(['settings' => ['custom_setting' => 'development']]);
    $production->update(['settings' => ['allow_signups' => true, 'custom_setting' => 'production']]);
    actingAs($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($tenant);
    Filament::bootCurrentPanel();

    Livewire::actingAs($admin)
        ->test(EditTenantApplication::class, ['record' => $application->getKey()])
        ->assertSetStrict('data.allow_signups', false)
        ->set('data.environment', 'production')
        ->assertSetStrict('data.allow_signups', true)
        ->set('data.allow_signups', false)
        ->set('data.redirect_uris', ['https://app.example.test/callback'])
        ->call('save')
        ->assertHasNoErrors();

    expect($development->fresh()->settings)->toBe(['custom_setting' => 'development']);
    expect($production->fresh()->settings)->toBe(['allow_signups' => false, 'custom_setting' => 'production']);
});

test('application settings keep provider secrets encrypted and scoped to the selected environment', function (): void {
    Role::query()->firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $tenant = Tenant::query()->create(['name' => 'Settings org', 'slug' => 'settings-org']);
    $application = app(ApplicationProvisioner::class)->create($tenant, [
        'name' => 'Settings app', 'framework' => 'laravel', 'environment' => 'development',
        'redirect_uris' => ['http://localhost:8000/callback'],
    ]);
    $development = $application->environments()->where('environment', 'development')->sole();
    $production = $application->environments()->where('environment', 'production')->sole();
    actingAs($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($tenant);
    Filament::bootCurrentPanel();

    Livewire::actingAs($admin)->test(EditTenantApplication::class, ['record' => $application->getKey()])
        ->set('data.login_title', 'Welcome to Billing')
        ->set('data.auth_layout', 'card')
        ->set('data.email_verification_policy', 'required')
        ->set('data.passkeys_enabled', false)
        ->set('data.sso_enabled', true)
        ->set('data.social_github_mode', 'custom')
        ->set('data.social_github_client_id', 'billing-client')
        ->set('data.social_github_client_secret', 'billing-secret')
        ->call('save', false)
        ->assertHasNoErrors()
        ->assertSet('data.social_github_client_secret', null);

    $settings = $development->fresh()->settings;
    expect($settings)->toMatchArray([
        'login_title' => 'Welcome to Billing', 'auth_layout' => 'card',
        'email_verification_required' => true, 'passkeys_enabled' => false, 'sso_enabled' => true,
    ]);
    expect($settings['social_providers']['github']['client_secret'])->not->toBe('billing-secret');
    expect(Crypt::decryptString($settings['social_providers']['github']['client_secret']))->toBe('billing-secret');
    expect(json_encode($settings))->not->toContain('billing-secret');
    expect($production->fresh()->getSetting('sso_enabled', false))->toBeFalse();

    Livewire::actingAs($admin)->test(EditTenantApplication::class, ['record' => $application->getKey()])
        ->assertSet('data.social_github_client_secret', null)
        ->set('data.login_title', 'Updated heading')
        ->call('save', false)
        ->assertHasNoErrors();
    expect(Crypt::decryptString($development->fresh()->settings['social_providers']['github']['client_secret']))->toBe('billing-secret');

    Livewire::actingAs($admin)->test(EditTenantApplication::class, ['record' => $application->getKey()])
        ->set('data.social_github_client_id', 'changed-client')
        ->call('save', false)
        ->assertHasErrors();
    expect($development->fresh()->settings['social_providers']['github']['client_id'])->toBe('billing-client');
});

test('application email verification can be required, disabled, or inherited per environment', function (): void {
    Role::query()->firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $tenant = Tenant::query()->create(['name' => 'Verification policy org', 'slug' => 'verification-policy-org']);
    $application = app(ApplicationProvisioner::class)->create($tenant, [
        'name' => 'Verification policy app', 'framework' => 'laravel', 'environment' => 'development',
        'redirect_uris' => ['http://localhost:8000/callback'],
    ]);
    $development = $application->environments()->where('environment', 'development')->sole();
    $production = $application->environments()->where('environment', 'production')->sole();
    $portalSettings = app(ApplicationFeaturesSettings::class);
    $portalSettings->email_verification_required = true;
    app()->instance(ApplicationFeaturesSettings::class, $portalSettings);
    actingAs($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($tenant);
    Filament::bootCurrentPanel();

    Livewire::actingAs($admin)->test(EditTenantApplication::class, ['record' => $application->getKey()])
        ->assertSet('data.email_verification_policy', 'inherit')
        ->set('data.email_verification_policy', 'disabled')
        ->call('save', false)
        ->assertHasNoErrors();

    expect($development->fresh()->getSetting('email_verification_required'))->toBeFalse();
    expect($development->fresh()->requiresEmailVerification())->toBeFalse();
    expect($production->fresh()->requiresEmailVerification())->toBeTrue();
    Livewire::actingAs($admin)->test(EditTenantApplication::class, ['record' => $application->getKey()])
        ->assertSet('data.email_verification_policy', 'disabled')
        ->set('data.environment', 'production')
        ->assertSet('data.email_verification_policy', 'inherit');

    Livewire::actingAs($admin)->test(EditTenantApplication::class, ['record' => $application->getKey()])
        ->assertSet('data.email_verification_policy', 'disabled')
        ->set('data.email_verification_policy', 'required')
        ->call('save', false)
        ->assertHasNoErrors();
    expect($development->fresh()->getSetting('email_verification_required'))->toBeTrue();

    Livewire::actingAs($admin)->test(EditTenantApplication::class, ['record' => $application->getKey()])
        ->assertSet('data.email_verification_policy', 'required')
        ->set('data.email_verification_policy', 'inherit')
        ->call('save', false)
        ->assertHasNoErrors();
    expect(array_key_exists('email_verification_required', $development->fresh()->settings ?? []))->toBeFalse();
    expect($development->fresh()->requiresEmailVerification())->toBeTrue();
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

test('provisioning rejects malformed application input before creating clients', function (array $overrides): void {
    $tenant = Tenant::query()->create(['name' => 'Invalid org', 'slug' => 'invalid-org']);
    $clientsBefore = Client::query()->count();
    $data = array_replace([
        'name' => 'Backend',
        'framework' => 'laravel',
        'environment' => 'development',
        'redirect_uris' => ['http://localhost:8000/callback'],
    ], $overrides);

    expect(fn () => app(ApplicationProvisioner::class)->create($tenant, $data))
        ->toThrow(ValidationException::class);
    expect(Client::query()->count())->toBe($clientsBefore);
    expect($tenant->applications()->count())->toBe(0);
})->with([
    'non-string framework' => [['framework' => ['laravel']]],
    'non-array redirect list' => [['redirect_uris' => 'http://localhost:8000/callback']],
    'non-string redirect' => [['redirect_uris' => [123]]],
    'non-boolean signup policy' => [['allow_signups' => 'true']],
]);

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
