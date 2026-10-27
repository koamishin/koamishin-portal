@php
    use Filament\Facades\Filament;
    use App\Models\TenantApplication;
    use App\Filament\Resources\TenantApplications\TenantApplicationResource;

    $currentTenant = Filament::getTenant();

    if (! $currentTenant) {
        return;
    }

    $applications = $currentTenant->applications()->latest()->get();

    // Check if the current route has a TenantApplication record
    $routeRecord = request()->route('record');
    $currentApp = null;

    if ($routeRecord instanceof TenantApplication) {
        $currentApp = $routeRecord;
        session(['current_application_id' => $currentApp->id]);
    } else {
        $currentApp = TenantApplication::current($currentTenant);
    }
@endphp

<div class="fi-app-menu-wrapper mt-2 mb-2 px-1">
    <x-filament::dropdown placement="bottom-start" teleport size="lg">
        <x-slot name="trigger">
            <button
                type="button"
                class="flex items-center justify-between w-full px-3 py-2 text-xs font-medium rounded-lg border border-gray-200 bg-white/60 hover:bg-gray-50 dark:border-white/10 dark:bg-white/5 dark:hover:bg-white/10 text-gray-700 dark:text-gray-200 transition-colors shadow-xs"
            >
                <div class="flex items-center gap-2 truncate">
                    <x-filament::icon
                        icon="heroicon-m-cube"
                        class="w-4 h-4 text-primary-500 shrink-0"
                    />
                    <span class="truncate font-semibold">
                        {{ $currentApp ? $currentApp->name : 'Select Application' }}
                    </span>
                </div>

                <div class="flex items-center gap-1.5 shrink-0 ml-2">
                    @if($currentApp)
                        @if($currentApp->environment === 'production')
                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
                                PROD
                            </span>
                        @else
                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold bg-amber-500/10 text-amber-600 dark:text-amber-400">
                                DEV
                            </span>
                        @endif
                    @endif

                    <x-filament::icon
                        icon="heroicon-m-chevron-up-down"
                        class="w-3.5 h-3.5 text-gray-400"
                    />
                </div>
            </button>
        </x-slot>

        <!-- Dropdown content -->
        <div class="p-2 text-xs text-gray-500 dark:text-gray-400 font-medium uppercase tracking-wider border-b border-gray-100 dark:border-white/5 flex items-center justify-between">
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
                        <span class="font-medium {{ $currentApp && $currentApp->id === $app->id ? 'font-bold text-primary-600 dark:text-primary-400' : '' }}">
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
</div>
