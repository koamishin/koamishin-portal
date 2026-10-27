@php
    $app = $getRecord();
    $subdomain = $app->slug ?? (strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $app->name)) . '-' . substr($app->oauth_client_id ?? 'app', 0, 6));
    $isProd = $app->environment === 'production';
@endphp

<div class="fi-app-card group relative h-full flex flex-col justify-between rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-white/10 hover:border-primary-500/60 dark:hover:border-primary-500/60 hover:shadow-lg hover:shadow-primary-500/5 transition-all duration-200 overflow-hidden p-6 cursor-pointer">
    <!-- Top accent line themed with Filament primary color -->
    <div class="absolute inset-x-6 top-0 h-1 bg-gradient-to-r from-transparent via-primary-500 to-transparent opacity-75 group-hover:opacity-100 transition-opacity"></div>

    <div>
        <!-- Top row: Framework icon + domain -->
        <div class="flex items-center gap-3 mb-5">
            <div class="w-8 h-8 rounded-lg bg-gray-100 dark:bg-white/5 border border-gray-200 dark:border-white/10 flex items-center justify-center text-xs font-bold text-gray-700 dark:text-gray-200 shrink-0">
                @if($app->framework === 'nextjs')
                    <span class="font-mono">▲</span>
                @elseif($app->framework === 'laravel')
                    <span class="text-red-500 font-mono text-[11px]">Lv</span>
                @elseif($app->framework === 'vue')
                    <span class="text-emerald-500 font-mono text-[11px]">V</span>
                @elseif($app->framework === 'react')
                    <span class="text-cyan-500 font-mono text-[11px]">⚛</span>
                @else
                    <span class="text-primary-500 font-mono text-[11px]">JS</span>
                @endif
            </div>

            <span class="font-mono text-xs text-gray-500 dark:text-gray-400 truncate max-w-[210px] tracking-tight">
                {{ $subdomain }}.koamishin.auth
            </span>
        </div>

        <!-- Middle: Application Name -->
        <h3 class="text-base font-bold text-gray-900 dark:text-white tracking-tight group-hover:text-primary-600 dark:group-hover:text-primary-400 transition-colors mb-2.5">
            {{ $app->name }}
        </h3>

        <!-- Environment status badge -->
        <div class="mb-4">
            @if($isProd)
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-xs font-medium bg-emerald-50 dark:bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-500/30">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                    Production Ready
                </span>
            @else
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-xs font-medium bg-gray-50 dark:bg-white/5 text-gray-600 dark:text-gray-400 border border-dashed border-gray-300 dark:border-white/15">
                    <svg class="w-3.5 h-3.5 text-amber-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                    No Production Environment
                </span>
            @endif
        </div>
    </div>

    <!-- Footer: Relative update timestamp -->
    <div class="pt-4 border-t border-gray-100 dark:border-white/5 flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
        <span>Updated {{ $app->updated_at?->diffForHumans() ?? 'recently' }}</span>
        <span class="text-primary-600 dark:text-primary-400 font-semibold flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
            Manage ↗
        </span>
    </div>
</div>
