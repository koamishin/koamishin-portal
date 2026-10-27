<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\TenantApplication;
use App\Models\User;
use App\Services\Users\LaravelUserImporter;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

test('development and production environments maintain isolated user directories', function (): void {
    Role::query()->firstOrCreate(['name' => 'user', 'guard_name' => 'web']);

    /** @var Tenant $tenant */
    $tenant = Tenant::query()->create([
        'name' => 'Acme Cloud',
        'slug' => 'acme-cloud',
    ]);

    /** @var TenantApplication $app */
    $app = TenantApplication::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Acme WebApp',
        'framework' => 'nextjs',
        'environment' => 'development',
        'redirect_uris' => ['http://localhost:3000/callback'],
    ]);

    $devUser = User::factory()->create(['name' => 'Dev Tester', 'email' => 'dev@acme.test']);
    $prodUser = User::factory()->create(['name' => 'Prod Customer', 'email' => 'customer@acme.com']);

    // Enroll Dev User in development sandbox
    $app->users()->attach($devUser, [
        'environment' => 'development',
        'role' => 'tester',
    ]);

    // Enroll Prod User in production
    $app->users()->attach($prodUser, [
        'environment' => 'production',
        'role' => 'customer',
    ]);

    // Development query only returns dev user
    $devUsers = $app->users('development')->get();
    expect($devUsers->count())->toBe(1);
    expect($devUsers->first()?->email)->toBe('dev@acme.test');

    // Production query only returns prod user
    $prodUsers = $app->users('production')->get();
    expect($prodUsers->count())->toBe(1);
    expect($prodUsers->first()?->email)->toBe('customer@acme.com');
});

test('can import users from other laravel applications and preserve existing bcrypt password hashes', function (): void {
    Role::query()->firstOrCreate(['name' => 'user', 'guard_name' => 'web']);

    /** @var Tenant $tenant */
    $tenant = Tenant::query()->create([
        'name' => 'Acme Labs',
        'slug' => 'acme-labs',
    ]);

    /** @var TenantApplication $app */
    $app = TenantApplication::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Acme API',
        'framework' => 'laravel',
        'environment' => 'production',
        'redirect_uris' => ['https://api.acme.com/callback'],
    ]);

    $importer = new LaravelUserImporter;

    $knownPassword = 'laravel_legacy_secret_password';
    $originalBcryptHash = Hash::make($knownPassword);

    $jsonPayload = json_encode([
        [
            'name' => 'Imported Artisan',
            'email' => 'artisan@laravel.com',
            'password' => $originalBcryptHash,
            'email_verified_at' => '2026-05-01 12:00:00',
        ],
        [
            'name' => 'Taylor Developer',
            'email' => 'taylor@laravel.com',
            'password' => $originalBcryptHash,
            'email_verified_at' => '2026-05-01 12:00:00',
        ],
    ], JSON_THROW_ON_ERROR);

    $result = $importer->importFromJson($jsonPayload, $tenant, $app, 'production', [
        'auto_verify' => true,
    ]);

    expect($result['imported'])->toBe(2);
    expect($result['errors'])->toBeEmpty();

    /** @var User $importedUser */
    $importedUser = User::query()->where('email', 'artisan@laravel.com')->first();
    expect($importedUser)->not->toBeNull();

    // Verbatim hash was preserved
    expect($importedUser->password)->toBe($originalBcryptHash);

    // User can authenticate with their existing legacy password seamlessly!
    expect(Hash::check($knownPassword, $importedUser->password))->toBeTrue();

    // User is bound to the production environment of this application
    expect($app->users('production')->where('email', 'artisan@laravel.com')->exists())->toBeTrue();
});

test('can import users from csv format into a specific environment', function (): void {
    Role::query()->firstOrCreate(['name' => 'user', 'guard_name' => 'web']);

    /** @var Tenant $tenant */
    $tenant = Tenant::query()->create([
        'name' => 'CSV Import Org',
        'slug' => 'csv-import-org',
    ]);

    /** @var TenantApplication $app */
    $app = TenantApplication::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Storefront App',
        'framework' => 'vue',
        'environment' => 'development',
        'redirect_uris' => ['http://localhost:5173/callback'],
    ]);

    $importer = new LaravelUserImporter;

    $csv = <<<'CSV'
name,email,password,role
John Doe,john@imported.test,samplePassword123,member
Jane Smith,jane@imported.test,samplePassword456,admin
CSV;

    $result = $importer->importFromCsv($csv, $tenant, $app, 'development', [
        'auto_verify' => true,
    ]);

    expect($result['imported'])->toBe(2);

    /** @var User $john */
    $john = User::query()->where('email', 'john@imported.test')->first();
    expect($john)->not->toBeNull();
    expect(Hash::check('samplePassword123', $john->password))->toBeTrue();

    expect($app->users('development')->where('email', 'john@imported.test')->exists())->toBeTrue();
});
