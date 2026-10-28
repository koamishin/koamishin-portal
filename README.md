# Koamishin Portal

[![CI](https://github.com/koamishin/koamishin-portal/actions/workflows/ci.yml/badge.svg)](https://github.com/koamishin/koamishin-portal/actions/workflows/ci.yml)
[![PHP](https://img.shields.io/badge/PHP-8.4%20%7C%208.5-777BB4?logo=php&logoColor=white)](https://php.net)
[![Laravel](https://img.shields.io/badge/Laravel-13.x-FF2D20?logo=laravel&logoColor=white)](https://laravel.com)
[![Filament](https://img.shields.io/badge/Filament-5.x-F59E0B?logo=filament&logoColor=white)](https://filamentphp.com)
[![Packagist](https://img.shields.io/badge/Packagist-koamishin%2Fauth--php-F28D1A?logo=packagist&logoColor=white)](https://packagist.org/packages/koamishin/auth-php)
[![npm](https://img.shields.io/badge/npm-%40koamishin%2Fauth--js-CB3837?logo=npm&logoColor=white)](https://www.npmjs.com/package/@koamishin/auth-js)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

**Koamishin Portal** is a modern, self-hostable, centralized OAuth2/OIDC identity provider and user management platform. Designed for organizations and teams that run multiple internal or customer-facing applications, it enables a single centralized user directory where users register once and sign in seamlessly across all connected apps with Single Sign-On (SSO).

---

## Centralized Authentication Architecture

```
                                  +-------------------------------------------------------------+
                                  |                 Koamishin Identity Portal                   |
                                  |                (Central User Directory & SSO)               |
                                  +-------------------------------------------------------------+
                                  | - Passport OAuth2 / OIDC Server (PKCE & RS256 JWKS)         |
                                  | - Multi-Factor Auth (WebAuthn Passkeys, TOTP, Email OTP)    |
                                  | - Filament 5 Admin Panel (Clerk-style UI, Topbar Switchers) |
                                  | - Svix-Compliant Signed Webhooks (user.created, updated)    |
                                  +------------------------------+------------------------------+
                                                                 |
                                +--------------------------------+--------------------------------+
                                |                                                                 |
                   OAuth2 Authorization Flow                                         OAuth2 Authorization Flow
                     (Single Sign-On)                                                  (Single Sign-On)
                                v                                                                 v
+-----------------------------------------------+               +-----------------------------------------------+
|          Application A (e.g. CRM Portal)      |               |       Application B (e.g. Analytics Hub)      |
|                                               |               |                                               |
| - Uses `koamishin/auth-php` Laravel SDK       |               | - Uses `@koamishin/auth-js` or PHP SDK        |
| - Receives authorization code + RS256 JWT     |               | - Reuses active Portal SSO browser session    |
| - Accesses user claims & public metadata      |               | - Maintains isolated local application session|
+-----------------------------------------------+               +-----------------------------------------------+
```

### How Shared Login & Registration Work

1. **Centralized Directory**: Users register at the Portal (`/register`) or are provisioned by an administrator. Their credentials, passkeys, multi-factor authentication, and profile reside securely in the Portal database.
2. **Multi-Tenancy & Application Scoping**: An Organization contains multiple Applications (e.g. *CRM*, *Helpdesk*, *Billing*), each with distinct `development` and `production` environments and separate OAuth client credentials.
3. **Seamless Single Sign-On (SSO)**: When a user visits Application A and clicks "Sign in with Koamishin", they are redirected to the Portal. If already authenticated, the Portal immediately approves the authorization without re-prompting for passwords. When the user visits Application B, their active session enables instant one-click login.
4. **Environment Isolation & Custom Metadata**: User access is scoped per application environment. Each application environment maintains isolated `public` and `private` custom metadata buckets.
5. **Realtime Webhook Sync**: Changes made to users (profile updates, bans, metadata alterations) trigger signed HMAC-SHA256 webhooks to synchronize local databases across downstream systems.

---

## Key Features

- **Clerk-Inspired UX & Admin Interface**:
  - Full-featured Filament 5 administration panel with topbar Organization, Application, and Environment breadcrumb switchers.
  - User activity heatmap, active device session killswitches, Web3 EVM wallet linking, @username tagging, and phone verification.
  - Direct inline action toggles and user impersonation controls.
- **Standards-Compliant OAuth2 & OIDC**:
  - RFC 7636 PKCE (Proof Key for Code Exchange) enforcement.
  - Standard OIDC Discovery (`/.well-known/openid-configuration`) and stateless RS256 JWKS verification (`/oauth/jwks`).
  - Scoped `/api/oauth/userinfo` claims.
- **Multi-Factor & Modern Authentication**:
  - WebAuthn Passkeys (biometric/hardware keys).
  - App TOTP (Google Authenticator / 1Password) and Email OTP verification.
  - Optional social login providers (GitHub, Google, Microsoft, GitLab).
- **Application User Metadata**:
  - Key-value metadata editor in Filament.
  - Application-environment scoped `public` (client-accessible) and `private` (server-only) JSON buckets.
  - REST Management API with OAuth2 client-credentials grant.
- **Production-Ready & High Performance**:
  - Powered by Laravel 13 and Laravel Octane (FrankenPHP) for microsecond request processing.
  - Pre-configured Docker Compose with PostgreSQL 17, Redis, and queue workers.

---

## Quickstart

### Method 1: Docker Compose (Fastest)

Launch the full stack with Octane, PostgreSQL 17, and Redis:

```bash
git clone https://github.com/koamishin/koamishin-portal.git
cd koamishin-portal
cp .env.example .env

# Start containers
docker compose up -d

# Initialize keys and database
docker compose exec portal php artisan key:generate --no-interaction
docker compose exec portal php artisan migrate --force --no-interaction
docker compose exec portal php artisan module:migrate --all --force --no-interaction
docker compose exec portal php artisan passport:keys --no-interaction
docker compose exec portal php artisan storage:link --no-interaction
```

Open `http://localhost:8000/setup` to complete the interactive setup wizard.

### Method 2: Local Installation (PHP 8.4/8.5 + Node.js)

```bash
git clone https://github.com/koamishin/koamishin-portal.git
cd koamishin-portal
composer install
cp .env.example .env

# Setup SQLite database and keys
touch database/database.sqlite
php artisan key:generate --no-interaction
php artisan migrate --no-interaction
php artisan module:migrate --all --force --no-interaction
php artisan passport:keys --no-interaction
php artisan storage:link --no-interaction

# Build assets and start services
npm ci
npm run build
composer run dev
```

Visit `http://localhost:8000/setup` in your browser.

---

## First-Run Setup Wizard

On your first visit to `http://localhost:8000/setup`:

1. Provide your **Portal Site Name** (e.g. *Acme Identity*).
2. Create the initial **Super Administrator** account (Name, Email, Password).
3. Click **Initialize Portal & Launch**.

The wizard configures initial settings, creates the administrator, and redirects you directly to the **Filament Administration Panel** at `/admin`. The setup route is automatically locked.

---

## Connecting Applications

### 1. Register an Application in the Portal

1. Log into `/admin` and select or create an Organization.
2. In the navigation sidebar under **Applications**, click **New Application**.
3. Set the Name, Framework, and Callback URL (e.g. `http://localhost:8001/koamishin/callback`).
4. Save and copy the generated **Client ID** and **Client Secret**.

### 2. Using the Laravel SDK (`koamishin/auth-php`)

Install in your consuming Laravel application:

```bash
composer require koamishin/auth-php
php artisan vendor:publish --tag=koamishin-config
```

Configure your application's `.env`:

```dotenv
KOAMISHIN_PORTAL_URL=http://localhost:8000
KOAMISHIN_CLIENT_ID=your-client-id
KOAMISHIN_CLIENT_SECRET=your-client-secret
KOAMISHIN_REDIRECT_URI=http://localhost:8001/koamishin/callback
KOAMISHIN_APPLICATION_ID=1
KOAMISHIN_ENVIRONMENT=development
```

Add the guard to `config/auth.php`:

```php
'guards' => [
    'koamishin' => [
        'driver' => 'koamishin',
    ],
],
```

Register authentication routes in `routes/web.php`:

```php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Koamishin\Auth\Facades\Koamishin;

// Redirect user to Portal login
Route::get('/login', fn () => redirect()->away(Koamishin::getLoginUrl('/dashboard')))->name('login');

// Handle callback from Portal
Route::get('/koamishin/callback', function (Request $request) {
    Koamishin::handleCallback($request->query('code'), $request->query('state'));
    return redirect()->to(Koamishin::consumeReturnTo('/dashboard'));
});

// Protected route
Route::get('/dashboard', function (Request $request) {
    $user = $request->user(); // KoamishinUser instance
    return response()->json([
        'id' => $user->id,
        'name' => $user->name,
        'email' => $user->email,
        'metadata' => $user->publicMetadata,
    ]);
})->middleware('auth:koamishin');

// Logout
Route::post('/logout', function () {
    Koamishin::logout();
    return redirect('/');
});
```

---

## Documentation

Full documentation is available in the `docs/` folder and via the built-in Astro documentation site:

- **[Installation & Getting Started](docs/getting-started/installation.mdx)**: Detailed setup prerequisites and commands.
- **[Centralized Auth Walkthrough](docs/getting-started/centralized-auth.mdx)**: Complete step-by-step tutorial connecting two Laravel applications to one Portal.
- **[Application User Metadata](docs/guides/user-metadata.mdx)**: How to configure and use custom metadata via Filament, the PHP SDK, and the REST API.
- **[Laravel PHP SDK Guide](docs/guides/laravel-sdk.mdx)**: Complete reference for `koamishin/auth-php`.
- **[Tenancy, OAuth & Webhooks](docs/guides/core-concepts.mdx)**: Deep dive into the data model and security boundaries.
- **[Production Deployment & Operations](docs/guides/ci-cd.mdx)**: Hardening, Docker Compose in production, Linux VM setup, SSL, and backups.
- **[API Reference](docs/reference/examples.mdx)**: Endpoints, token requests, and management APIs.

To preview the documentation site locally:

```bash
npm ci --prefix landing
npm run dev --prefix landing
```

---

## Contributing & License

Contributions are welcome! Please read [CONTRIBUTING.md](CONTRIBUTING.md) before submitting pull requests.

Koamishin Portal is open-source software licensed under the [MIT License](LICENSE).
