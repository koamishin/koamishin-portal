# Koamishin Portal

[![CI](https://github.com/koamishin/koamishin-portal/actions/workflows/ci.yml/badge.svg)](https://github.com/koamishin/koamishin-portal/actions/workflows/ci.yml)
[![PHP](https://img.shields.io/badge/PHP-8.4%20%7C%208.5-777BB4?logo=php&logoColor=white)](https://php.net)
[![Laravel](https://img.shields.io/badge/Laravel-13.x-FF2D20?logo=laravel&logoColor=white)](https://laravel.com)
[![Filament](https://img.shields.io/badge/Filament-5.x-F59E0B?logo=filament&logoColor=white)](https://filamentphp.com)
[![Packagist](https://img.shields.io/badge/Packagist-koamishin%2Fauth--php-F28D1A?logo=packagist&logoColor=white)](https://packagist.org/packages/koamishin/auth-php)
[![npm](https://img.shields.io/badge/npm-%40koamishin%2Fauth--js-CB3837?logo=npm&logoColor=white)](https://www.npmjs.com/package/@koamishin/auth-js)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

**Koamishin Portal** is a self-hosted authentication and user-management service for applications that share one user directory. It provides OAuth 2.0 authorization-code login with PKCE, a Laravel SDK, application environments, a scoped management API, and signed webhooks.

A person can register from App A through the Portal and return authenticated to App A, then sign in to App B with the same Portal identity. Access to each application follows that environment's signup policy or an administrator's enrollment decision.

## How signup and shared login work

1. Create an organization and register each application with its own callback URL and client credentials.
2. App A's Register link calls `Koamishin::getRegistrationUrl('/dashboard')`. The SDK stores OAuth state and a PKCE verifier and sends `screen_hint=signup` to the Portal.
3. The Portal validates the client and exact registered callback, then shows App A's name and environment on its registration page. With **Allow self-service signup** enabled, the new account can join App A's organization and environment and complete authorization back to App A.
4. App B's Login link calls `getLoginUrl('/dashboard')`. The Portal can reuse its browser login session. An existing user who is not yet enrolled can join App B after approving consent only when App B's environment also allows self-service signup.
5. Both apps receive the same Portal user ID. Separate application IDs, memberships, sessions, and metadata keep their contexts distinct.

The environment setting is `settings.allow_signups`; only the JSON boolean `true` enables self-service enrollment. A missing or false setting requires administrator enrollment. Direct registration on the generic Portal `/register` page creates a global account without choosing an application or returning to one.

The [two-application walkthrough](docs/getting-started/centralized-auth.mdx) includes complete routes, configuration, signup policy, local Eloquent user projections, metadata, and expected redirects. The SDK guard returns the central identity; a local `users` row keyed by `koamishin_user_id` gives each app normal relationships to its own data. The existing local test harness uses Portal port **8120**, App A **8121**, and App B **8122**, with an isolated Portal test database.

## Features

- Laravel 13 Portal with Vue/Inertia account pages and a Filament 5 administration panel.
- One central user directory with organization and application-environment memberships.
- Contextual registration and login, authorization-code flow with PKCE, and scoped userinfo claims.
- Laravel SDK guard with application/environment checks, token revalidation, and refresh support.
- Public and private metadata scoped to each application environment.
- Server-to-server management for scoped users, metadata, memberships, organization context, and OAuth grants.
- Queued user webhooks with explicit legacy and Standard Webhooks signature formats.
- Optional social login, passkeys, and multi-factor features provided by the installed authentication packages.

The Portal publishes discovery and JWKS endpoints, but does not issue OIDC ID tokens or provide complete OpenID Connect conformance. See [architecture](docs/architecture.mdx) and [security](docs/guides/security.mdx) for the boundaries of the service.

## Install the Portal

Use PHP 8.5 with the extensions required by the lockfile, Composer 2, Git, and a compatible Node.js version. The [installation guide](docs/getting-started/installation.mdx) contains the full prerequisites, Docker considerations, and first-run setup.

For a **new** local SQLite installation:

```bash
git clone https://github.com/koamishin/koamishin-portal.git
cd koamishin-portal
composer install
cp .env.example .env
touch database/database.sqlite
php artisan key:generate --no-interaction
php artisan migrate --no-interaction
php artisan module:migrate --all --force --no-interaction
php artisan passport:keys --no-interaction
php artisan storage:link --no-interaction
npm ci
npm run build
```

Set `APP_URL` in `.env` to your intended Portal origin and configure mail. For a local-only demo, `MAIL_MAILER=log` writes verification messages to the application log. Start `composer run dev`, then visit `/setup` on that origin to create the initial administrator. Keep the instance private until initialization is complete.

These commands generate new keys; do not run them over an existing installation without a recovery and rotation plan. The default seeder creates known-password accounts. Review [deployment and operations](docs/guides/ci-cd.mdx) before exposing the service or using the bundled containers.

## Connect a Laravel application

Create a **Laravel** application in the Portal and save the one-time client credentials. Choose **Allow self-service signup** for the selected environment when users should be able to register or join through consent. Otherwise enroll accounts through the admin panel or scoped management API.

Install an SDK version containing `getRegistrationUrl()` and publish its configuration:

```bash
composer require koamishin/auth-php
php artisan vendor:publish --tag=koamishin-config --no-interaction
```

The source in this checkout can be newer than a published release. The [SDK guide](docs/guides/laravel-sdk.mdx) explains development-branch installation and how to pin its resolved revision.

For the local App A example, configure the consuming server with its actual credentials and numeric Portal application ID:

```dotenv
KOAMISHIN_PORTAL_URL=http://portal.localhost:8120
KOAMISHIN_CLIENT_ID=app-a-development-client-id
KOAMISHIN_CLIENT_SECRET=app-a-development-client-secret
KOAMISHIN_REDIRECT_URI=http://app-a.localhost:8121/koamishin/callback
KOAMISHIN_APPLICATION_ID=your-numeric-application-id
KOAMISHIN_ENVIRONMENT=development
```

Add the SDK guard to the existing `guards` array in `config/auth.php`:

```php
'koamishin' => [
    'driver' => 'koamishin',
],
```

Add application routes with Laravel's web middleware and session enabled:

```php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Koamishin\Auth\Facades\Koamishin;

Route::get('/register', fn () => redirect()->away(
    Koamishin::getRegistrationUrl('/dashboard')
))->name('register');

Route::get('/login', fn () => redirect()->away(
    Koamishin::getLoginUrl('/dashboard')
))->name('login');

Route::get('/koamishin/callback', function (Request $request) {
    if ($request->query('error')) {
        abort(403, 'Sign-in was not completed.');
    }

    $input = $request->validate([
        'code' => ['required', 'string'],
        'state' => ['required', 'string'],
    ]);

    Koamishin::handleCallback($input['code'], $input['state']);

    return redirect()->to(Koamishin::consumeReturnTo('/dashboard'));
});

Route::get('/dashboard', function (Request $request) {
    $user = $request->user();

    return response()->json([
        'id' => $user->id,
        'application_id' => $user->applicationId,
        'environment' => $user->environment,
        'public_metadata' => $user->publicMetadata,
    ]);
})->middleware('auth:koamishin');
```

New users start with empty application metadata. A trusted backend can populate public and private objects with the management API; the `metadata:public` user scope exposes only public values. Separate applications do not automatically share metadata. See [application user metadata](docs/guides/user-metadata.mdx).

## Documentation

- [Installation and getting started](docs/getting-started/installation.mdx)
- [Two-app centralized signup and login walkthrough](docs/getting-started/centralized-auth.mdx)
- [Laravel SDK integration](docs/guides/laravel-sdk.mdx)
- [Application user metadata](docs/guides/user-metadata.mdx)
- [Tenancy, OAuth, and webhooks](docs/guides/core-concepts.mdx)
- [Management API reference](docs/reference/examples.mdx)
- [Production deployment and operations](docs/guides/ci-cd.mdx)
- [Security](docs/guides/security.mdx)

The Astro site renders selected guides under `/docs/`. Start it locally with:

```bash
npm ci --prefix landing
npm run dev --prefix landing
```

See [Astro landing and documentation](docs/guides/landing.mdx) for publishing the site.

## Contributing and license

Read [CONTRIBUTING.md](CONTRIBUTING.md) before submitting changes. Koamishin Portal is licensed under the [MIT License](LICENSE).
