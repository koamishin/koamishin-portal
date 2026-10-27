@php
    $app = $this->getRecord();
    $tenant = $app->tenant;
    $isProd = $app->environment === 'production';
    $users = \App\Models\User::query()->latest()->limit(5)->get();
@endphp

<x-filament-panels::page>
    <div class="space-y-8 max-w-7xl mx-auto -mt-2">
        <!-- Top Application Sub-Header (from Image 2) -->
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 pb-6 border-b border-[#232530]">
            <div class="flex items-center gap-3">
                <div class="w-12 h-12 rounded-xl bg-[#181922] border border-[#2a2b3a] flex items-center justify-center text-white text-lg font-bold shadow-md">
                    @if($app->framework === 'nextjs')
                        <span class="text-white font-mono">▲</span>
                    @elseif($app->framework === 'laravel')
                        <span class="text-red-400 font-mono">Lv</span>
                    @elseif($app->framework === 'vue')
                        <span class="text-emerald-400 font-mono">V</span>
                    @elseif($app->framework === 'react')
                        <span class="text-cyan-400 font-mono">⚛</span>
                    @else
                        <span class="text-purple-400 font-mono">JS</span>
                    @endif
                </div>
                <div>
                    <div class="flex items-center gap-2.5">
                        <h1 class="text-2xl font-bold text-white tracking-tight">{{ $app->name }}</h1>
                        @if($isProd)
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-500/10 text-emerald-400 border border-emerald-500/30">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                                Production
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-500/10 text-amber-400 border border-amber-500/30">
                                <span class="w-1.5 h-1.5 rounded-full bg-amber-400"></span>
                                Development
                            </span>
                        @endif
                    </div>
                    <p class="text-xs text-gray-400 font-mono mt-0.5">
                        Client ID: <span class="text-gray-300">{{ $app->oauth_client_id ?? 'None' }}</span>
                    </p>
                </div>
            </div>

            <!-- Action buttons (from Image 2) -->
            <div class="flex items-center gap-3" x-data="{ copied: false }">
                <button
                    type="button"
                    @click="navigator.clipboard.writeText('{{ $app->oauth_client_id }}'); copied = true; setTimeout(() => copied = false, 2000)"
                    class="inline-flex items-center gap-2 px-3.5 py-2 rounded-lg text-xs font-semibold bg-[#1a1b26] text-gray-300 hover:text-white border border-[#2d2e3e] hover:border-gray-500 transition-all shadow-sm"
                >
                    <svg class="w-3.5 h-3.5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                    </svg>
                    <span x-text="copied ? 'Copied ID!' : 'Copy Client ID'"></span>
                </button>

                @if(! $isProd)
                    <form action="{{ request()->url() }}" method="POST" class="inline">
                        @csrf
                        <a
                            href="{{ \App\Filament\Resources\TenantApplications\TenantApplicationResource::getUrl('edit', ['record' => $app]) }}"
                            class="inline-flex items-center gap-2 px-4 py-2 rounded-lg text-xs font-semibold bg-gradient-to-r from-[#6c47ff] to-[#7c3aed] text-white hover:brightness-110 shadow-md shadow-[#6c47ff]/20 transition-all"
                        >
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                            Go to prod
                        </a>
                    </form>
                @endif
            </div>
        </div>

        <!-- 4 Quick-Action Insight Cards (exact layout from Image 2) -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4" x-data="{ copiedCmd: false, copiedSkills: false }">
            <!-- Card 1: Build faster with agents -->
            <div class="flex flex-col justify-between p-5 rounded-2xl bg-[#14151c] border border-[#232530] hover:border-[#383a4c] transition-all shadow-sm">
                <div>
                    <div class="w-9 h-9 rounded-xl bg-[#1b1c26] border border-[#2d2e3d] flex items-center justify-center text-[#a855f7] mb-3.5">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/>
                        </svg>
                    </div>
                    <h3 class="text-sm font-bold text-white mb-1.5">Build faster with agents</h3>
                    <p class="text-xs text-gray-400 leading-relaxed mb-4">
                        Install the Koamishin CLI & SDK to configure and deploy authentication with your agent.
                    </p>
                </div>
                <button
                    type="button"
                    @click="navigator.clipboard.writeText('{{ $app->getInstallCommand() }}'); copiedCmd = true; setTimeout(() => copiedCmd = false, 2000)"
                    class="w-full inline-flex items-center justify-center gap-2 py-2 px-3 rounded-lg text-xs font-semibold bg-[#1a1b24] text-gray-300 hover:text-white border border-[#2b2c3a] hover:border-gray-500 transition-all"
                >
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                    </svg>
                    <span x-text="copiedCmd ? 'Copied Command!' : 'Copy install command'"></span>
                </button>
            </div>

            <!-- Card 2: Set up Organizations -->
            <div class="flex flex-col justify-between p-5 rounded-2xl bg-[#14151c] border border-[#232530] hover:border-[#383a4c] transition-all shadow-sm">
                <div>
                    <div class="w-9 h-9 rounded-xl bg-[#1b1c26] border border-[#2d2e3d] flex items-center justify-center text-blue-400 mb-3.5">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                        </svg>
                    </div>
                    <h3 class="text-sm font-bold text-white mb-1.5">Set up Organizations</h3>
                    <p class="text-xs text-gray-400 leading-relaxed mb-4">
                        Build multi-tenant applications with B2B Authentication and isolated directories.
                    </p>
                </div>
                <a
                    href="{{ \App\Filament\Resources\Tenants\TenantResource::getUrl('index') }}"
                    class="w-full inline-flex items-center justify-center gap-1.5 py-2 px-3 rounded-lg text-xs font-semibold bg-[#1a1b24] text-gray-300 hover:text-white border border-[#2b2c3a] hover:border-gray-500 transition-all text-center"
                >
                    Explore Orgs →
                </a>
            </div>

            <!-- Card 3: Go to production -->
            <div class="flex flex-col justify-between p-5 rounded-2xl bg-[#14151c] border border-[#232530] hover:border-[#383a4c] transition-all shadow-sm">
                <div>
                    <div class="w-9 h-9 rounded-xl bg-[#1b1c26] border border-[#2d2e3d] flex items-center justify-center text-emerald-400 mb-3.5">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                        </svg>
                    </div>
                    <h3 class="text-sm font-bold text-white mb-1.5">Go to production</h3>
                    <p class="text-xs text-gray-400 leading-relaxed mb-4">
                        Take your application live, acquire real users, and configure SSL custom domains.
                    </p>
                </div>
                <a
                    href="{{ \App\Filament\Resources\TenantApplications\TenantApplicationResource::getUrl('edit', ['record' => $app]) }}"
                    class="w-full inline-flex items-center justify-center gap-1.5 py-2 px-3 rounded-lg text-xs font-semibold bg-[#1a1b24] text-gray-300 hover:text-white border border-[#2b2c3a] hover:border-gray-500 transition-all text-center"
                >
                    Deploy 🚀
                </a>
            </div>

            <!-- Card 4: Install Koamishin Skills -->
            <div class="flex flex-col justify-between p-5 rounded-2xl bg-[#14151c] border border-[#232530] hover:border-[#383a4c] transition-all shadow-sm">
                <div>
                    <div class="w-9 h-9 rounded-xl bg-[#1b1c26] border border-[#2d2e3d] flex items-center justify-center text-pink-400 mb-3.5">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                        </svg>
                    </div>
                    <h3 class="text-sm font-bold text-white mb-1.5">Install Agent Skills</h3>
                    <p class="text-xs text-gray-400 leading-relaxed mb-4">
                        Equip your AI coding agents with specialized Koamishin authentication knowledge.
                    </p>
                </div>
                <button
                    type="button"
                    @click="navigator.clipboard.writeText('npx @koamishin/skills add auth'); copiedSkills = true; setTimeout(() => copiedSkills = false, 2000)"
                    class="w-full inline-flex items-center justify-center gap-2 py-2 px-3 rounded-lg text-xs font-semibold bg-[#1a1b24] text-gray-300 hover:text-white border border-[#2b2c3a] hover:border-gray-500 transition-all"
                >
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                    </svg>
                    <span x-text="copiedSkills ? 'Copied Command!' : 'Copy install command'"></span>
                </button>
            </div>
        </div>

        <!-- Development Info Banner (from Image 2) -->
        <div class="rounded-xl border border-[#2a2c3a] bg-[#14151c]/80 p-4 flex items-center gap-3 text-xs text-gray-300 shadow-sm">
            <svg class="w-4 h-4 text-gray-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <span>
                @if($isProd)
                    <strong>Production Instance:</strong> Live authentication requests are processed with strict SSL and active JWKS key verification.
                @else
                    <strong>Development Instance:</strong> Use this instance for internal testing and local sandboxes. When you're ready to invite real users, create or switch to your production instance.
                @endif
            </span>
        </div>

        <!-- Setup Guide & Code Snippets Tabs -->
        <div class="rounded-2xl border border-[#232530] bg-[#14151c] p-6 space-y-6 shadow-sm" x-data="{ tab: 'env', copiedEnv: false, copiedCode: false }">
            <div class="flex items-center justify-between border-b border-[#232530] pb-4">
                <div>
                    <h2 class="text-base font-bold text-white tracking-tight">Quickstart Integration Guide</h2>
                    <p class="text-xs text-gray-400 mt-0.5">Configured for {{ $app->getFrameworkLabel() }}</p>
                </div>
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        @click="tab = 'env'"
                        :class="tab === 'env' ? 'bg-[#6c47ff] text-white shadow-sm' : 'text-gray-400 hover:text-white bg-[#1b1c26]'"
                        class="px-3 py-1.5 rounded-lg text-xs font-semibold transition-all"
                    >
                        .env Settings
                    </button>
                    <button
                        type="button"
                        @click="tab = 'code'"
                        :class="tab === 'code' ? 'bg-[#6c47ff] text-white shadow-sm' : 'text-gray-400 hover:text-white bg-[#1b1c26]'"
                        class="px-3 py-1.5 rounded-lg text-xs font-semibold transition-all"
                    >
                        Integration Code
                    </button>
                </div>
            </div>

            <!-- Tab 1: Environment Variables -->
            <div x-show="tab === 'env'" class="space-y-3">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-mono text-gray-400">Add to your project's .env file:</span>
                    <button
                        type="button"
                        @click="navigator.clipboard.writeText('{{ addslashes($app->getEnvSnippet()) }}'); copiedEnv = true; setTimeout(() => copiedEnv = false, 2000)"
                        class="inline-flex items-center gap-1.5 text-xs text-[#a855f7] hover:text-[#c084fc] font-semibold"
                    >
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                        </svg>
                        <span x-text="copiedEnv ? 'Copied!' : 'Copy .env'"></span>
                    </button>
                </div>
                <pre class="p-4 rounded-xl bg-[#0e0f14] border border-[#232530] text-xs font-mono text-emerald-400 overflow-x-auto leading-relaxed">{{ $app->getEnvSnippet() }}</pre>
            </div>

            <!-- Tab 2: Integration Code -->
            <div x-show="tab === 'code'" class="space-y-3" style="display: none;">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-mono text-gray-400">Boilerplate code for {{ $app->getFrameworkLabel() }}:</span>
                    <button
                        type="button"
                        @click="navigator.clipboard.writeText('{{ addslashes($app->getSetupGuideCode()) }}'); copiedCode = true; setTimeout(() => copiedCode = false, 2000)"
                        class="inline-flex items-center gap-1.5 text-xs text-[#a855f7] hover:text-[#c084fc] font-semibold"
                    >
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                        </svg>
                        <span x-text="copiedCode ? 'Copied Code!' : 'Copy Snippet'"></span>
                    </button>
                </div>
                <pre class="p-4 rounded-xl bg-[#0e0f14] border border-[#232530] text-xs font-mono text-purple-300 overflow-x-auto leading-relaxed">{{ $app->getSetupGuideCode() }}</pre>
            </div>
        </div>

        <!-- Test Users / Recent Users Table (from Image 2) -->
        <div class="rounded-2xl border border-[#232530] bg-[#14151c] overflow-hidden shadow-sm">
            <div class="p-5 border-b border-[#232530] flex items-center justify-between">
                <div>
                    <h2 class="text-base font-bold text-white tracking-tight">Active Users</h2>
                    <p class="text-xs text-gray-400 mt-0.5">Users authenticated across this system</p>
                </div>
                <a
                    href="{{ \App\Filament\Resources\Users\UserResource::getUrl('index') }}"
                    class="text-xs font-semibold text-[#a855f7] hover:text-[#c084fc] inline-flex items-center gap-1"
                >
                    View all users →
                </a>
            </div>

            <div class="divide-y divide-[#1f202b]">
                @forelse($users as $user)
                    <div class="p-4 flex items-center justify-between hover:bg-[#191a24] transition-colors">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-full bg-gradient-to-tr from-[#6c47ff] to-[#a855f7] flex items-center justify-center text-xs font-bold text-white shadow-inner">
                                {{ strtoupper(substr($user->name, 0, 2)) }}
                            </div>
                            <div>
                                <p class="text-sm font-semibold text-white">{{ $user->name }}</p>
                                <p class="text-xs text-gray-400">{{ $user->email }}</p>
                            </div>
                        </div>

                        <div class="flex items-center gap-8 text-xs text-gray-400">
                            <div>
                                <span class="text-gray-500 block text-[10px] uppercase font-mono tracking-wider">Status</span>
                                @if($user->isBanned())
                                    <span class="text-red-400 font-medium">Banned</span>
                                @else
                                    <span class="text-emerald-400 font-medium">Active</span>
                                @endif
                            </div>
                            <div>
                                <span class="text-gray-500 block text-[10px] uppercase font-mono tracking-wider">Joined</span>
                                <span class="text-gray-300">{{ $user->created_at?->format('M j, Y') ?? '—' }}</span>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="p-8 text-center text-xs text-gray-500">
                        No users registered yet.
                    </div>
                @endforelse
            </div>

            <div class="p-3.5 bg-[#101117] border-t border-[#1f202b] text-[11px] text-gray-500 flex items-center justify-between">
                <span>Updated just now</span>
                <span class="text-gray-400">{{ $users->count() }} active accounts</span>
            </div>
        </div>
    </div>
</x-filament-panels::page>
