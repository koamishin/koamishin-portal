@php
    use Filament\Facades\Filament;
    use App\Models\Tenant;
    use App\Models\TenantApplication;
    use App\Filament\Resources\TenantApplications\TenantApplicationResource;
    use App\Filament\Resources\Tenants\TenantResource;

    $currentTenant = Filament::getTenant();

    if (! $currentTenant) {
        return;
    }

    /** @var \App\Models\User|null $user */
    $user = auth()->user();
    $tenants = ($user && method_exists($user, 'getTenants'))
        ? $user->getTenants(Filament::getCurrentPanel())
        : Tenant::query()->where('is_active', true)->get();

    $applications = $currentTenant->applications()->latest()->get();

    $routeRecord = request()->route('record');
    $currentApp = null;

    if ($routeRecord instanceof TenantApplication) {
        $currentApp = $routeRecord;
        session(['current_application_id' => $currentApp->id]);
    } else {
        $currentApp = TenantApplication::current($currentTenant);
    }

    $currentEnv = $currentApp?->environment ?? 'development';
@endphp

<div class="fi-topbar-breadcrumb-nav flex items-center gap-1.5 sm:gap-2.5 text-xs ml-1 sm:ml-3">
    <!-- Slash separator after logo -->
    <span class="text-gray-300 dark:text-white/20 font-light select-none text-sm hidden sm:inline">/</span>

    <!-- 1. Organization Dropdown (Workspace) -->
    <x-filament::dropdown placement="bottom-start" teleport size="md">
        <x-slot name="trigger">
            <button
                type="button"
                class="flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg border border-gray-200 bg-white/70 hover:bg-gray-50 dark:border-white/10 dark:bg-white/5 dark:hover:bg-white/10 text-gray-800 dark:text-gray-200 transition-colors shadow-xs"
            >
                <x-filament::icon icon="heroicon-m-building-office-2" class="w-3.5 h-3.5 text-primary-500 shrink-0" />
                <span class="font-bold truncate max-w-[120px] sm:max-w-[160px]">{{ $currentTenant->name }}</span>
                <span class="hidden md:inline-block px-1 py-0.2 rounded text-[10px] uppercase font-mono text-gray-500 dark:text-gray-400 bg-gray-100 dark:bg-white/10">Workspace</span>
                <x-filament::icon icon="heroicon-m-chevron-down" class="w-3 h-3 text-gray-400 shrink-0" />
            </button>
        </x-slot>

        <div class="p-2 text-[11px] text-gray-500 dark:text-gray-400 font-semibold uppercase tracking-wider border-b border-gray-100 dark:border-white/5">
            Switch Organization
        </div>

        <x-filament::dropdown.list>
            @foreach($tenants as $tenantItem)
                <x-filament::dropdown.list.item
                    :href="url('/admin/' . $tenantItem->slug)"
                    tag="a"
                    :icon="$currentTenant->id === $tenantItem->id ? 'heroicon-m-check-circle' : 'heroicon-o-building-office-2'"
                    :color="$currentTenant->id === $tenantItem->id ? 'primary' : 'gray'"
                >
                    <span class="{{ $currentTenant->id === $tenantItem->id ? 'font-bold text-primary-600 dark:text-primary-400' : '' }}">
                        {{ $tenantItem->name }}
                    </span>
                </x-filament::dropdown.list.item>
            @endforeach
        </x-filament::dropdown.list>

        <div class="border-t border-gray-100 dark:border-white/5">
            <x-filament::dropdown.list>
                <x-filament::dropdown.list.item
                    :href="url('/admin/new')"
                    tag="a"
                    icon="heroicon-m-plus"
                    color="primary"
                >
                    Register new organization
                </x-filament::dropdown.list.item>

                <x-filament::dropdown.list.item
                    :href="url('/admin/' . $currentTenant->slug . '/profile')"
                    tag="a"
                    icon="heroicon-m-cog-6-tooth"
                >
                    Organization settings
                </x-filament::dropdown.list.item>
            </x-filament::dropdown.list>
        </div>
    </x-filament::dropdown>

    <!-- Slash separator -->
    <span class="text-gray-300 dark:text-white/20 font-light select-none text-sm">/</span>

    <!-- 2. Application Dropdown Selector -->
    <x-filament::dropdown placement="bottom-start" teleport size="md">
        <x-slot name="trigger">
            <button
                type="button"
                class="flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg border border-gray-200 bg-white/70 hover:bg-gray-50 dark:border-white/10 dark:bg-white/5 dark:hover:bg-white/10 text-gray-800 dark:text-gray-200 transition-colors shadow-xs"
            >
                <x-filament::icon icon="heroicon-m-cube" class="w-3.5 h-3.5 text-primary-500 shrink-0" />
                <span class="font-semibold truncate max-w-[110px] sm:max-w-[150px]">
                    {{ $currentApp ? $currentApp->name : 'Select App' }}
                </span>
                <x-filament::icon icon="heroicon-m-chevron-down" class="w-3 h-3 text-gray-400 shrink-0" />
            </button>
        </x-slot>

        <div class="p-2 text-[11px] text-gray-500 dark:text-gray-400 font-semibold uppercase tracking-wider border-b border-gray-100 dark:border-white/5 flex items-center justify-between">
            <span>Applications</span>
            <span class="text-[10px] font-mono lowercase">({{ $applications->count() }})</span>
        </div>

        <x-filament::dropdown.list>
            @forelse($applications as $app)
                <x-filament::dropdown.list.item
                    :href="route('filament.admin.switch-application', ['tenant' => $currentTenant, 'application' => $app])"
                    tag="a"
                    :icon="$currentApp && $currentApp->id === $app->id ? 'heroicon-m-check-circle' : 'heroicon-o-cube'"
                    :color="$currentApp && $currentApp->id === $app->id ? 'primary' : 'gray'"
                >
                    <div class="flex items-center justify-between w-full gap-2">
                        <span class="{{ $currentApp && $currentApp->id === $app->id ? 'font-bold text-primary-600 dark:text-primary-400' : '' }}">
                            {{ $app->name }}
                        </span>
                        <span class="text-[10px] uppercase font-mono px-1.5 py-0.5 rounded {{ $app->environment === 'production' ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' : 'bg-gray-100 dark:bg-white/10 text-gray-500 dark:text-gray-400' }}">
                            {{ $app->environment === 'production' ? 'prod' : 'dev' }}
                        </span>
                    </div>
                </x-filament::dropdown.list.item>
            @empty
                <div class="px-3 py-2 text-xs text-gray-400 text-center">
                    No applications created yet.
                </div>
            @endforelse
        </x-filament::dropdown.list>

        <div class="border-t border-gray-100 dark:border-white/5">
            <x-filament::dropdown.list>
                <x-filament::dropdown.list.item
                    :href="TenantApplicationResource::getUrl('create')"
                    tag="a"
                    icon="heroicon-m-plus"
                    color="primary"
                >
                    Create new application
                </x-filament::dropdown.list.item>

                <x-filament::dropdown.list.item
                    :href="TenantApplicationResource::getUrl('index')"
                    tag="a"
                    icon="heroicon-m-squares-2x2"
                >
                    View all applications
                </x-filament::dropdown.list.item>
            </x-filament::dropdown.list>
        </div>
    </x-filament::dropdown>

    <!-- Slash separator -->
    <span class="text-gray-300 dark:text-white/20 font-light select-none text-sm">/</span>

    <!-- 3. Environment Dropdown Selector (Clerk style) -->
    <x-filament::dropdown placement="bottom-start" teleport size="sm">
        <x-slot name="trigger">
            <button
                type="button"
                class="flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg border border-gray-200 bg-white/70 hover:bg-gray-50 dark:border-white/10 dark:bg-white/5 dark:hover:bg-white/10 text-gray-800 dark:text-gray-200 transition-colors shadow-xs"
            >
                @if($currentEnv === 'production')
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse shrink-0"></span>
                    <span class="font-bold text-emerald-600 dark:text-emerald-400">Production</span>
                @else
                    <span class="w-2 h-2 rounded-full bg-amber-500 shrink-0"></span>
                    <span class="font-bold text-amber-600 dark:text-amber-400">Development</span>
                @endif
                <x-filament::icon icon="heroicon-m-chevron-down" class="w-3 h-3 text-gray-400 shrink-0" />
            </button>
        </x-slot>

        <div class="p-2 text-[11px] text-gray-500 dark:text-gray-400 font-semibold uppercase tracking-wider border-b border-gray-100 dark:border-white/5">
            Application Environment
        </div>

        @if($currentApp)
            <x-filament::dropdown.list>
                <x-filament::dropdown.list.item
                    :href="route('filament.admin.toggle-environment', ['tenant' => $currentTenant, 'application' => $currentApp, 'environment' => 'development'])"
                    tag="a"
                    :icon="$currentEnv === 'development' ? 'heroicon-m-check-circle' : 'heroicon-o-wrench-screwdriver'"
                    :color="$currentEnv === 'development' ? 'warning' : 'gray'"
                >
                    <div class="flex items-center justify-between w-full">
                        <span class="flex items-center gap-1.5 {{ $currentEnv === 'development' ? 'font-bold text-amber-600 dark:text-amber-400' : '' }}">
                            <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                            Development
                        </span>
                        <span class="text-[10px] text-gray-400 font-mono">Sandbox</span>
                    </div>
                </x-filament::dropdown.list.item>

                <x-filament::dropdown.list.item
                    :href="route('filament.admin.toggle-environment', ['tenant' => $currentTenant, 'application' => $currentApp, 'environment' => 'production'])"
                    tag="a"
                    :icon="$currentEnv === 'production' ? 'heroicon-m-check-circle' : 'heroicon-o-check-badge'"
                    :color="$currentEnv === 'production' ? 'success' : 'gray'"
                >
                    <div class="flex items-center justify-between w-full">
                        <span class="flex items-center gap-1.5 {{ $currentEnv === 'production' ? 'font-bold text-emerald-600 dark:text-emerald-400' : '' }}">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                            Production
                        </span>
                        <span class="text-[10px] text-gray-400 font-mono">Live</span>
                    </div>
                </x-filament::dropdown.list.item>
            </x-filament::dropdown.list>
        @else
            <div class="p-3 text-xs text-gray-400 text-center">
                Select an application first to configure environments.
            </div>
        @endif
    </x-filament::dropdown>
</div>
