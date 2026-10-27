<?php

namespace App\Providers\Filament;

use AlizHarb\ActivityLog\ActivityLogPlugin;
use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Pages\Tenancy\EditTenantProfile;
use App\Filament\Pages\Tenancy\RegisterTenant;
use App\Filament\Widgets\AuthMethodsChartWidget;
use App\Filament\Widgets\AuthStatsOverviewWidget;
use App\Filament\Widgets\RecentUsersWidget;
use App\Filament\Widgets\UserSignupsChartWidget;
use App\Models\Tenant;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\Email\EmailAuthentication;
use Filament\Enums\ThemeMode;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use RobertBoes\FilamentPasskeys\FilamentPasskeysPlugin;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->renderHook(
                PanelsRenderHook::BODY_START,
                fn (): string => Blade::render('@include("impersonate-banner")'),
            )
            ->databaseNotifications()
            ->login()
            ->colors([
                'primary' => [
                    50 => '#f5f3ff',
                    100 => '#ede9fe',
                    200 => '#ddd6fe',
                    300 => '#c4b5fd',
                    400 => '#a78bfa',
                    500 => '#8b5cf6',
                    600 => '#6c47ff',
                    700 => '#5b21b6',
                    800 => '#4c1d95',
                    900 => '#3b0764',
                    950 => '#2e1065',
                ],
                'gray' => Color::Zinc,
            ])
            ->defaultThemeMode(ThemeMode::Dark)
            ->profile(EditProfile::class, isSimple: false)
            ->userMenuItems([
                'profile' => fn (Action $action): Action => $action
                    ->label('My profile')
                    ->icon('heroicon-m-user-circle')
                    ->url(fn (): string => EditProfile::getUrl()),
            ])
            ->multiFactorAuthentication([
                AppAuthentication::make()
                    ->recoverable(),
                EmailAuthentication::make(),
            ])
            ->tenant(Tenant::class, slugAttribute: 'slug')
            ->tenantRegistration(RegisterTenant::class)
            ->tenantProfile(EditTenantProfile::class)
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverClusters(in: app_path('Filament/Clusters'), for: 'App\Filament\Clusters')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AuthStatsOverviewWidget::class,
                UserSignupsChartWidget::class,
                AuthMethodsChartWidget::class,
                RecentUsersWidget::class,
                AccountWidget::class,
            ])
            ->plugins([
                FilamentShieldPlugin::make()
                    ->gridColumns([
                        'default' => 1,
                        'sm' => 2,
                        'lg' => 3,
                    ])
                    ->sectionColumnSpan(1)
                    ->checkboxListColumns([
                        'default' => 1,
                        'sm' => 2,
                        'lg' => 4,
                    ])
                    ->resourceCheckboxListColumns([
                        'default' => 1,
                        'sm' => 2,
                    ]),
                ActivityLogPlugin::make()
                    ->label('Log')
                    ->pluralLabel('Logs')
                    ->navigationGroup('System'),
                FilamentPasskeysPlugin::make(),
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
