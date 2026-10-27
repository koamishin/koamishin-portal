<?php

declare(strict_types=1);

namespace App\Models;

use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Laravel\Passport\Client;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string|null $oauth_client_id
 * @property string $name
 * @property string $framework
 * @property string $environment
 * @property array<string> $redirect_uris
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TenantApplication extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'oauth_client_id',
        'name',
        'framework',
        'environment',
        'redirect_uris',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'redirect_uris' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function oauthClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'oauth_client_id');
    }

    /**
     * Get the active application for the given (or current) tenant.
     */
    public static function current(?Tenant $tenant = null): ?self
    {
        $tenant ??= Filament::getTenant();

        if (! $tenant) {
            return null;
        }

        $appId = session('current_application_id');

        $tenantKey = $tenant->getKey();

        if ($appId) {
            /** @var self|null $app */
            $app = self::query()->where('tenant_id', $tenantKey)->find($appId);
            if ($app) {
                return $app;
            }
        }

        /** @var self|null $default */
        $default = self::query()->where('tenant_id', $tenantKey)->first();
        if ($default) {
            session(['current_application_id' => $default->id]);

            return $default;
        }

        return null;
    }

    public function getFrameworkLabel(): string
    {
        return match ($this->framework) {
            'laravel' => 'Laravel (PHP)',
            'nextjs' => 'Next.js (React)',
            'vue' => 'Vue 3 / Nuxt',
            'react' => 'React (Vite / CRA)',
            'nodejs' => 'Node.js (Express / Fastify)',
            default => 'Standard Web (Vanilla JS)',
        };
    }

    public function getInstallCommand(): string
    {
        return match ($this->framework) {
            'laravel' => 'composer require koamishin/auth-php',
            'nextjs', 'vue', 'react' => 'npm install @koamishin/auth-js',
            default => 'npm install @koamishin/auth-js',
        };
    }

    public function getEnvSnippet(): string
    {
        $portalUrl = rtrim(config('app.url'), '/');
        $clientId = $this->oauth_client_id ?? 'your-client-id';
        $callback = $this->redirect_uris[0] ?? "{$portalUrl}/callback";

        if ($this->framework === 'laravel') {
            return <<<ENV
KOAMISHIN_PORTAL_URL={$portalUrl}
KOAMISHIN_CLIENT_ID={$clientId}
KOAMISHIN_CLIENT_SECRET=YOUR_CLIENT_SECRET_HERE
KOAMISHIN_REDIRECT_URI={$callback}
ENV;
        }

        if ($this->framework === 'nextjs') {
            return <<<ENV
NEXT_PUBLIC_KOAMISHIN_PORTAL_URL={$portalUrl}
NEXT_PUBLIC_KOAMISHIN_CLIENT_ID={$clientId}
NEXT_PUBLIC_KOAMISHIN_REDIRECT_URI={$callback}
ENV;
        }

        return <<<ENV
VITE_KOAMISHIN_PORTAL_URL={$portalUrl}
VITE_KOAMISHIN_CLIENT_ID={$clientId}
VITE_KOAMISHIN_REDIRECT_URI={$callback}
ENV;
    }

    public function getSetupGuideCode(): string
    {
        $portalUrl = rtrim(config('app.url'), '/');
        $clientId = $this->oauth_client_id ?? 'your-client-id';
        $callback = $this->redirect_uris[0] ?? 'http://localhost:3000/auth/callback';

        return match ($this->framework) {
            'laravel' => <<<PHP
// 1. In routes/web.php:
use Koamishin\Auth\Facades\Koamishin;
use Illuminate\Http\Request;

Route::get('/login', fn () => redirect()->away(Koamishin::getLoginUrl()))->name('login');

Route::get('/koamishin/callback', function (Request \$request) {
    \$user = Koamishin::handleCallback(
        code: (string) \$request->query('code'),
        state: (string) \$request->query('state'),
    );

    // Sync or authenticate user in your local database
    // \$user->id, \$user->name, \$user->email, \$user->roles

    return redirect('/dashboard');
});

// 2. Protect routes using middleware:
use Koamishin\Auth\Middleware\AuthenticateWithKoamishin;

Route::middleware(AuthenticateWithKoamishin::class)->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index']);
});
PHP,
            'nextjs' => <<<'TS'
// 1. Initialize client in lib/auth.ts:
import { createKoamishinClient } from '@koamishin/auth-js';

export const koamishin = createKoamishinClient({
  portalUrl: process.env.NEXT_PUBLIC_KOAMISHIN_PORTAL_URL!,
  clientId: process.env.NEXT_PUBLIC_KOAMISHIN_CLIENT_ID!,
  redirectUri: process.env.NEXT_PUBLIC_KOAMISHIN_REDIRECT_URI!,
});

// 2. Add Login Button in app/page.tsx:
'use client';
import { koamishin } from '@/lib/auth';

export default function LoginPage() {
  return (
    <button onClick={() => koamishin.redirectToSignIn()}>
      Sign In with Koamishin
    </button>
  );
}

// 3. Callback page in app/auth/callback/page.tsx:
'use client';
import { useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { koamishin } from '@/lib/auth';

export default function CallbackPage() {
  const router = useRouter();

  useEffect(() => {
    koamishin.handleRedirectCallback()
      .then(({ user }) => {
        console.log('Logged in:', user);
        router.push('/dashboard');
      })
      .catch((err) => console.error(err));
  }, [router]);

  return <div>Authenticating with Koamishin...</div>;
}
TS,
            'vue' => <<<'VUE'
<!-- In your Vue Component (e.g. src/views/Login.vue) -->
<script setup lang="ts">
import { createKoamishinClient } from '@koamishin/auth-js';

const koamishin = createKoamishinClient({
  portalUrl: import.meta.env.VITE_KOAMISHIN_PORTAL_URL,
  clientId: import.meta.env.VITE_KOAMISHIN_CLIENT_ID,
  redirectUri: import.meta.env.VITE_KOAMISHIN_REDIRECT_URI,
});

async function login() {
  await koamishin.redirectToSignIn();
}
</script>

<template>
  <button @click="login">Sign In with Koamishin</button>
</template>
VUE,
            default => <<<JS
// Standard JavaScript / TypeScript with PKCE
import { createKoamishinClient } from '@koamishin/auth-js';

const koamishin = createKoamishinClient({
  portalUrl: '{$portalUrl}',
  clientId: '{$clientId}',
  redirectUri: '{$callback}',
});

// Trigger login:
await koamishin.redirectToSignIn();

// Handle callback on redirect URL:
const { user, tokens } = await koamishin.handleRedirectCallback();
console.log('User signed in:', user.name, user.email);
JS,
        };
    }
}
