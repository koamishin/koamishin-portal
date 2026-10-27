@php
    $tenant = $this->getActiveTenant();
    $currentApp = $this->getActiveApplication();
    $stats = $this->stats;
    $pagedUsers = $this->getPagedUsers();
    $applications = $this->tenantApplications;
    $user = auth()->user();
@endphp

<x-filament-panels::page>
    <div class="space-y-8 max-w-7xl mx-auto -mt-2">

        <!-- 1. Executive Context Hero -->
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
                            <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-700 dark:text-gray-200">
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
                        Centralized Authentication & Identity Directory. Manage users, applications, and security credentials across all internal systems.
                    </p>
                </div>

                <!-- Right: Header Actions -->
                <div class="flex flex-wrap items-center gap-2.5">
                    {{ $this->getAction('createUser') }}
                    {{ $this->getAction('createApplication') }}
                    {{ $this->getAction('importUsers') }}
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

        <!-- 2. Metrics Ribbon (4 Cards) -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <!-- Card 1: Users -->
            <div class="p-5 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-white/10 shadow-xs">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Total Users</span>
                    <div class="w-7 h-7 rounded-lg bg-primary-50 dark:bg-primary-500/10 text-primary-600 dark:text-primary-400 flex items-center justify-center">
                        <x-filament::icon icon="heroicon-m-users" class="w-4 h-4" />
                    </div>
                </div>
                <div class="mt-3">
                    <span class="text-2xl font-bold text-gray-950 dark:text-white">{{ $stats['total_users'] }}</span>
                    <p class="text-[11px] text-gray-400 mt-0.5">
                        {{ $stats['active_users'] }} active, {{ $stats['banned_users'] }} suspended
                    </p>
                </div>
            </div>

            <!-- Card 2: Active Sessions -->
            <div class="p-5 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-white/10 shadow-xs">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Active Sessions</span>
                    <div class="w-7 h-7 rounded-lg bg-emerald-50 dark:bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center">
                        <x-filament::icon icon="heroicon-m-device-phone-mobile" class="w-4 h-4" />
                    </div>
                </div>
                <div class="mt-3">
                    <span class="text-2xl font-bold text-gray-950 dark:text-white">{{ $stats['active_sessions'] }}</span>
                    <p class="text-[11px] text-gray-400 mt-0.5">Logged in client devices</p>
                </div>
            </div>

            <!-- Card 3: Connected Apps -->
            <div class="p-5 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-white/10 shadow-xs">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Organization Apps</span>
                    <div class="w-7 h-7 rounded-lg bg-blue-50 dark:bg-blue-500/10 text-blue-600 dark:text-blue-400 flex items-center justify-center">
                        <x-filament::icon icon="heroicon-m-cube" class="w-4 h-4" />
                    </div>
                </div>
                <div class="mt-3">
                    <span class="text-2xl font-bold text-gray-950 dark:text-white">{{ $stats['total_apps'] }}</span>
                    <p class="text-[11px] text-gray-400 mt-0.5">Registered internal clients</p>
                </div>
            </div>

            <!-- Card 4: Webhook Sync Rate -->
            <div class="p-5 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-white/10 shadow-xs">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Webhook Sync Health</span>
                    <div class="w-7 h-7 rounded-lg bg-amber-50 dark:bg-amber-500/10 text-amber-600 dark:text-amber-400 flex items-center justify-center">
                        <x-filament::icon icon="heroicon-m-signal" class="w-4 h-4" />
                    </div>
                </div>
                <div class="mt-3">
                    <span class="text-2xl font-bold text-gray-950 dark:text-white">{{ $stats['webhook_rate'] }}</span>
                    <p class="text-[11px] text-gray-400 mt-0.5">Event dispatch success</p>
                </div>
            </div>
        </div>

        <!-- 3. Realtime User Management with Quickactions (Livewire) -->
        <div class="rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900 overflow-hidden shadow-xs">
            <!-- Header with Search & Filter Tabs -->
            <div class="p-5 border-b border-gray-100 dark:border-white/5 space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div>
                        <h2 class="text-base font-bold text-gray-950 dark:text-white tracking-tight">User Directory & Quickactions</h2>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Instant ban, unban, session termination, and verification</p>
                    </div>

                    <!-- Search Input -->
                    <div class="relative w-full sm:w-72">
                        <input
                            type="search"
                            wire:model.live.debounce.300ms="search"
                            placeholder="Search name or email..."
                            class="w-full pl-9 pr-3 py-1.5 text-xs rounded-lg border border-gray-200 dark:border-white/10 bg-gray-50 dark:bg-white/5 text-gray-900 dark:text-white focus:outline-hidden focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500"
                        />
                        <div class="absolute left-2.5 top-2 text-gray-400">
                            <x-filament::icon icon="heroicon-m-magnifying-glass" class="w-4 h-4" />
                        </div>
                    </div>
                </div>

                <!-- Status Filter Tabs -->
                <div class="flex items-center gap-1 text-xs">
                    <button
                        type="button"
                        wire:click="$set('statusFilter', 'all')"
                        class="px-3 py-1.5 rounded-lg font-medium transition-colors {{ $statusFilter === 'all' ? 'bg-primary-50 dark:bg-primary-500/10 text-primary-600 dark:text-primary-400 font-bold' : 'text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white' }}"
                    >
                        All ({{ $stats['total_users'] }})
                    </button>
                    <button
                        type="button"
                        wire:click="$set('statusFilter', 'active')"
                        class="px-3 py-1.5 rounded-lg font-medium transition-colors {{ $statusFilter === 'active' ? 'bg-emerald-50 dark:bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 font-bold' : 'text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white' }}"
                    >
                        Active ({{ $stats['active_users'] }})
                    </button>
                    <button
                        type="button"
                        wire:click="$set('statusFilter', 'banned')"
                        class="px-3 py-1.5 rounded-lg font-medium transition-colors {{ $statusFilter === 'banned' ? 'bg-red-50 dark:bg-red-500/10 text-red-600 dark:text-red-400 font-bold' : 'text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white' }}"
                    >
                        Suspended ({{ $stats['banned_users'] }})
                    </button>
                    <button
                        type="button"
                        wire:click="$set('statusFilter', 'unverified')"
                        class="px-3 py-1.5 rounded-lg font-medium transition-colors {{ $statusFilter === 'unverified' ? 'bg-amber-50 dark:bg-amber-500/10 text-amber-600 dark:text-amber-400 font-bold' : 'text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white' }}"
                    >
                        Unverified ({{ $stats['unverified_users'] }})
                    </button>
                </div>
            </div>

            <!-- Users Table -->
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="bg-gray-50 dark:bg-white/5 border-b border-gray-100 dark:border-white/5 text-gray-500 dark:text-gray-400 font-medium">
                        <tr>
                            <th class="py-3 px-4">User</th>
                            <th class="py-3 px-4">Status</th>
                            <th class="py-3 px-4">Role</th>
                            <th class="py-3 px-4">Credentials</th>
                            <th class="py-3 px-4">Joined</th>
                            <th class="py-3 px-4 text-right">Quickactions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @forelse($pagedUsers as $member)
                            <tr class="hover:bg-gray-50/50 dark:hover:bg-white/[0.02] transition-colors" wire:key="user-row-{{ $member->id }}">
                                <!-- User name & email -->
                                <td class="py-3 px-4">
                                    <div class="flex items-center gap-3">
                                        <div class="w-8 h-8 rounded-full bg-primary-100 dark:bg-primary-500/20 text-primary-700 dark:text-primary-300 flex items-center justify-center text-xs font-bold shrink-0">
                                            {{ strtoupper(substr($member->name, 0, 2)) }}
                                        </div>
                                        <div>
                                            <p class="font-semibold text-gray-900 dark:text-white">{{ $member->name }}</p>
                                            <p class="text-gray-500 dark:text-gray-400 text-[11px]">{{ $member->email }}</p>
                                        </div>
                                    </div>
                                </td>

                                <!-- Status Badge -->
                                <td class="py-3 px-4">
                                    @if($member->isBanned())
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-semibold bg-red-50 dark:bg-red-500/10 text-red-600 dark:text-red-400" title="{{ $member->ban_reason }}">
                                            ● Suspended
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-semibold bg-emerald-50 dark:bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
                                            ● Active
                                        </span>
                                    @endif
                                </td>

                                <!-- Role -->
                                <td class="py-3 px-4">
                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-mono uppercase bg-gray-100 dark:bg-white/10 text-gray-600 dark:text-gray-300">
                                        {{ $member->getRoleNames()->first() ?? 'user' }}
                                    </span>
                                </td>

                                <!-- Credentials / MFA / Social -->
                                <td class="py-3 px-4">
                                    <div class="flex items-center gap-1.5 text-gray-400">
                                        @if($member->socialAccounts->isNotEmpty())
                                            <span class="px-1.5 py-0.5 rounded bg-blue-50 dark:bg-blue-500/10 text-blue-600 dark:text-blue-400 text-[10px] font-medium" title="Linked: {{ $member->socialAccounts->pluck('provider')->implode(', ') }}">
                                                {{ ucfirst($member->socialAccounts->first()->provider) }}
                                            </span>
                                        @endif

                                        @if($member->passkeys->isNotEmpty())
                                            <span class="px-1.5 py-0.5 rounded bg-emerald-50 dark:bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 text-[10px] font-medium" title="{{ $member->passkeys->count() }} Passkeys">
                                                Passkey
                                            </span>
                                        @endif

                                        @if($member->app_authentication_secret || $member->has_email_authentication)
                                            <span class="px-1.5 py-0.5 rounded bg-purple-50 dark:bg-purple-500/10 text-purple-600 dark:text-purple-400 text-[10px] font-medium">
                                                MFA
                                            </span>
                                        @endif
                                    </div>
                                </td>

                                <!-- Joined Date -->
                                <td class="py-3 px-4 text-gray-500 dark:text-gray-400 text-[11px]">
                                    {{ $member->created_at?->format('M j, Y') ?? '—' }}
                                </td>

                                <!-- Action Buttons -->
                                <td class="py-3 px-4 text-right">
                                    <div class="flex items-center justify-end gap-1.5">
                                        @if($member->isBanned())
                                            <button
                                                type="button"
                                                wire:click="unbanUser({{ $member->id }})"
                                                wire:confirm="Restore account access for {{ $member->name }}?"
                                                class="px-2 py-1 rounded text-[11px] font-semibold text-emerald-600 dark:text-emerald-400 hover:bg-emerald-50 dark:hover:bg-emerald-500/10 transition-colors"
                                            >
                                                Unban
                                            </button>
                                        @else
                                            @if(!$member->hasRole('super_admin'))
                                                <button
                                                    type="button"
                                                    wire:click="banUser({{ $member->id }})"
                                                    wire:confirm="Suspend {{ $member->name }} and terminate active sessions?"
                                                    class="px-2 py-1 rounded text-[11px] font-semibold text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-500/10 transition-colors"
                                                >
                                                    Ban
                                                </button>
                                            @endif
                                        @endif

                                        <button
                                            type="button"
                                            wire:click="revokeUserSessions({{ $member->id }})"
                                            wire:confirm="Kill all active sessions and revoke tokens for {{ $member->name }}?"
                                            class="px-2 py-1 rounded text-[11px] font-medium text-amber-600 dark:text-amber-400 hover:bg-amber-50 dark:hover:bg-amber-500/10 transition-colors"
                                            title="Terminate all sessions"
                                        >
                                            Revoke
                                        </button>

                                        @if($member->email_verified_at === null)
                                            <button
                                                type="button"
                                                wire:click="verifyUserEmail({{ $member->id }})"
                                                class="px-2 py-1 rounded text-[11px] font-medium text-emerald-600 dark:text-emerald-400 hover:bg-emerald-50 dark:hover:bg-emerald-500/10 transition-colors"
                                                title="Mark email as verified"
                                            >
                                                Verify
                                            </button>
                                        @endif

                                        <a
                                            href="{{ \App\Filament\Resources\Users\UserResource::getUrl('view', ['record' => $member]) }}"
                                            class="px-2 py-1 rounded text-[11px] font-medium text-gray-500 hover:text-gray-900 dark:hover:text-white transition-colors"
                                            title="View User Details"
                                        >
                                            Inspect →
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="py-8 text-center text-xs text-gray-400">
                                    No users found matching query.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <!-- Footer Pagination -->
            @if($pagedUsers->hasPages())
                <div class="p-3.5 bg-gray-50 dark:bg-white/5 border-t border-gray-100 dark:border-white/5">
                    {{ $pagedUsers->links() }}
                </div>
            @endif
        </div>

        <!-- 4. Tenant Applications Showcase -->
        <div class="rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900 p-6 shadow-xs space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-base font-bold text-gray-950 dark:text-white tracking-tight">Organization Applications</h2>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Switch active application context or manage client keys</p>
                </div>
                <a
                    href="{{ \App\Filament\Resources\TenantApplications\TenantApplicationResource::getUrl('index') }}"
                    class="text-xs font-semibold text-primary-600 dark:text-primary-400 hover:underline inline-flex items-center gap-1"
                >
                    View all ({{ $applications->count() }}) →
                </a>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                @forelse($applications as $app)
                    <div
                        class="p-4 rounded-xl border {{ $currentApp && $currentApp->id === $app->id ? 'border-primary-500 ring-1 ring-primary-500/20 bg-primary-50/20 dark:bg-primary-500/5' : 'border-gray-200 dark:border-white/10 bg-gray-50/50 dark:bg-white/[0.02]' }} flex flex-col justify-between transition-all"
                    >
                        <div>
                            <div class="flex items-center justify-between gap-2 mb-2">
                                <span class="font-bold text-sm text-gray-950 dark:text-white truncate">
                                    {{ $app->name }}
                                </span>
                                @if($app->environment === 'production')
                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold bg-emerald-50 dark:bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
                                        PROD
                                    </span>
                                @else
                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold bg-amber-50 dark:bg-amber-500/10 text-amber-600 dark:text-amber-400">
                                        DEV
                                    </span>
                                @endif
                            </div>
                            <p class="text-xs text-gray-500 dark:text-gray-400 font-mono text-[11px] truncate">
                                {{ $app->getFrameworkLabel() }}
                            </p>
                        </div>

                        <div class="mt-4 pt-3 border-t border-gray-100 dark:border-white/5 flex items-center justify-between text-xs">
                            @if($currentApp && $currentApp->id === $app->id)
                                <span class="text-xs font-bold text-primary-600 dark:text-primary-400 inline-flex items-center gap-1">
                                    <x-filament::icon icon="heroicon-m-check-circle" class="w-3.5 h-3.5" />
                                    Active Context
                                </span>
                            @else
                                <button
                                    type="button"
                                    wire:click="switchApp({{ $app->id }})"
                                    class="text-xs font-medium text-gray-600 dark:text-gray-300 hover:text-primary-600 dark:hover:text-primary-400"
                                >
                                    Set active context
                                </button>
                            @endif

                            <a
                                href="{{ \App\Filament\Resources\TenantApplications\TenantApplicationResource::getUrl('view', ['record' => $app]) }}"
                                class="text-xs font-semibold text-primary-600 dark:text-primary-400 hover:underline"
                            >
                                Setup Guide ↗
                            </a>
                        </div>
                    </div>
                @empty
                    <div class="col-span-full p-6 text-center text-xs text-gray-400">
                        No applications created for this organization. Click "New Application" above to add one.
                    </div>
                @endforelse
            </div>
        </div>

    </div>
</x-filament-panels::page>
