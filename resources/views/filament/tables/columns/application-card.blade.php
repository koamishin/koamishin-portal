@php
    $app = $getRecord();
    $subdomain = $app->slug ?? (strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $app->name)) . '-' . substr($app->oauth_client_id ?? 'app', 0, 6));
    $isProd = $app->environment === 'production';
@endphp

<div class="relative group h-full flex flex-col justify-between rounded-2xl bg-[#14151c] border border-[#232530] hover:border-[#6c47ff]/60 hover:shadow-xl hover:shadow-[#6c47ff]/10 hover:-translate-y-0.5 transition-all duration-200 overflow-hidden p-6 cursor-pointer">
    <!-- Top purple neon glow accent (from Image 1) -->
    <div class="absolute inset-x-8 top-0 h-[2px] bg-gradient-to-r from-transparent via-[#7c3aed] to-transparent group-hover:via-[#a855f7] transition-all"></div>

    <div>
        <!-- Top row: circular logo + subdomain -->
        <div class="flex items-center gap-3 mb-5">
            <div class="w-8 h-8 rounded-full bg-[#1b1c26] border border-[#2d2e3d] flex items-center justify-center text-xs font-bold text-gray-200 shrink-0 shadow-inner">
                @if($app->framework === 'nextjs')
                    <span class="text-white font-mono">▲</span>
                @elseif($app->framework === 'laravel')
                    <span class="text-red-400 font-mono text-[10px]">Lv</span>
                @elseif($app->framework === 'vue')
                    <span class="text-emerald-400 font-mono text-[10px]">V</span>
                @elseif($app->framework === 'react')
                    <span class="text-cyan-400 font-mono text-[10px]">⚛</span>
                @else
                    <span class="text-purple-400 font-mono text-[10px]">JS</span>
                @endif
            </div>
            <span class="font-mono text-[11px] text-gray-400 truncate max-w-[210px] tracking-tight">
                {{ $subdomain }}.koamishin.auth
            </span>
        </div>

        <!-- Middle: Application Name -->
        <h3 class="text-lg font-bold text-white tracking-tight group-hover:text-[#a78bfa] transition-colors mb-3">
            {{ $app->name }}
        </h3>

        <!-- Environment status badge (from Image 1) -->
        <div class="mb-4">
            @if($isProd)
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-[11px] font-medium bg-emerald-500/10 text-emerald-400 border border-emerald-500/30">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                    Production Environment
                </span>
            @else
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-[11px] font-medium bg-[#1a1b24] text-gray-400 border border-dashed border-[#343545]">
                    <svg class="w-3.5 h-3.5 text-amber-500/90 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                    No Production Environment
                </span>
            @endif
        </div>
    </div>

    <!-- Footer: Updated timestamp -->
    <div class="pt-4 border-t border-[#1f202b] flex items-center justify-between text-[11px] text-gray-500">
        <span>Updated {{ $app->updated_at?->diffForHumans() ?? 'recently' }}</span>
        <span class="text-[#7c3aed] font-semibold flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
            Manage ↗
        </span>
    </div>
</div>
