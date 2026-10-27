@php
    $tenant = $this->getTenant();
    $currentApp = $this->getCurrentApplication();
    $user = auth()->user();
@endphp

<x-filament-widgets::widget>
    <div class="rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900 p-6 shadow-xs">
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-6">
            <!-- Left: Greeting & Current Context -->
            <div class="space-y-2">
                <div class="flex items-center gap-2">
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-bold bg-primary-50 dark:bg-primary-500/10 text-primary-600 dark:text-primary-400 border border-primary-500/20">
                        {{ $tenant ? $tenant->name : 'Central Auth Portal' }}
                    </span>

                    @if($currentApp)
                        <span class="text-gray-300 dark:text-gray-600">•</span>
                        <span class="inline-flex items-center gap-1 text-xs font-semibold text-gray-700 dark:text-gray-300 font-mono">
                            <x-filament::icon icon="heroicon-m-cube" class="w-3.5 h-3.5 text-primary-500" />
                            {{ $currentApp->name }}
                        </span>

                        @if($currentApp->environment === 'production')
                            <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-bold bg-emerald-50 dark:bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
                                PROD
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-bold bg-amber-50 dark:bg-amber-500/10 text-amber-600 dark:text-amber-400">
                                DEV
                            </span>
                        @endif
                    @endif
                </div>

                <h1 class="text-xl sm:text-2xl font-bold text-gray-950 dark:text-white tracking-tight">
                    Welcome back, {{ $user?->name ?? 'Administrator' }}
                </h1>

                <p class="text-xs text-gray-500 dark:text-gray-400 max-w-2xl leading-relaxed">
                    Centralized Authentication & User Directory for all internal systems. Powered by OAuth 2.0, OpenID Connect (OIDC), and realtime Svix event webhooks.
                </p>
            </div>

            <!-- Right: Quick Actions -->
            <div class="flex flex-wrap items-center gap-2.5">
                <a
                    href="{{ \App\Filament\Resources\TenantApplications\TenantApplicationResource::getUrl('create') }}"
                    class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg text-xs font-semibold bg-primary-600 hover:bg-primary-500 text-white shadow-xs transition-colors"
                >
                    <x-filament::icon icon="heroicon-m-plus" class="w-4 h-4" />
                    New Application
                </a>

                <a
                    href="{{ \App\Filament\Resources\Users\UserResource::getUrl('index') }}"
                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-medium bg-white hover:bg-gray-50 dark:bg-white/5 dark:hover:bg-white/10 text-gray-700 dark:text-gray-200 border border-gray-300 dark:border-white/10 shadow-xs transition-colors"
                >
                    <x-filament::icon icon="heroicon-m-users" class="w-3.5 h-3.5 text-gray-500" />
                    Users
                </a>

                <a
                    href="{{ \App\Filament\Resources\OAuthClients\OAuthClientResource::getUrl('index') }}"
                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-medium bg-white hover:bg-gray-50 dark:bg-white/5 dark:hover:bg-white/10 text-gray-700 dark:text-gray-200 border border-gray-300 dark:border-white/10 shadow-xs transition-colors"
                >
                    <x-filament::icon icon="heroicon-m-key" class="w-3.5 h-3.5 text-gray-500" />
                    OAuth Clients
                </a>

                <a
                    href="{{ \App\Filament\Resources\Webhooks\WebhookEndpointResource::getUrl('index') }}"
                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-medium bg-white hover:bg-gray-50 dark:bg-white/5 dark:hover:bg-white/10 text-gray-700 dark:text-gray-200 border border-gray-300 dark:border-white/10 shadow-xs transition-colors"
                >
                    <x-filament::icon icon="heroicon-m-signal" class="w-3.5 h-3.5 text-gray-500" />
                    Webhooks
                </a>
            </div>
        </div>

        <!-- Bottom status bar -->
        <div class="mt-6 pt-4 border-t border-gray-100 dark:border-white/5 flex flex-wrap items-center justify-between gap-4 text-[11px] text-gray-500 dark:text-gray-400">
            <div class="flex items-center gap-4">
                <span class="inline-flex items-center gap-1.5">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                    <span>OIDC Discovery: <a href="{{ url('.well-known/openid-configuration') }}" target="_blank" class="font-mono text-primary-600 dark:text-primary-400 hover:underline">/.well-known/openid-configuration</a></span>
                </span>

                <span class="inline-flex items-center gap-1.5">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                    <span>Public JWKS: <a href="{{ url('oauth/jwks') }}" target="_blank" class="font-mono text-primary-600 dark:text-primary-400 hover:underline">/oauth/jwks</a></span>
                </span>
            </div>

            <div class="flex items-center gap-3">
                <span class="font-mono text-[10px]">Algorithm: RS256</span>
                <span>•</span>
                <span class="font-mono text-[10px]">PKCE: S256 Enforced</span>
            </div>
        </div>
    </div>
</x-filament-widgets::widget>
