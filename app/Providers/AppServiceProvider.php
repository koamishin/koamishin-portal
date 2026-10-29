<?php

namespace App\Providers;

use App\Features\FeatureRegistry;
use App\Models\User;
use App\Observers\UserObserver;
use App\Services\OAuth\ApplicationAuthentication;
use Carbon\CarbonImmutable;
use Filament\Panel;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Passport\Passport;
use Laravel\Pennant\FeatureManager;
use Nwidart\Modules\Facades\Module;
use Symfony\Component\HttpFoundation\Response;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    #[\Override]
    public function register(): void
    {
        $this->registerFilamentPlugins();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureFeatures();
        $this->configurePassport();

        \Filament\Resources\Resource::scopeToTenant(false);

        Gate::before(fn ($user, $ability): ?true => method_exists($user, 'hasRole') && $user->hasRole('super_admin') ? true : null);

        User::observe(UserObserver::class);
    }

    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null
        );
    }

    protected function configureFeatures(): void
    {
        FeatureRegistry::initialize();

        $featureManager = app(FeatureManager::class);

        foreach (FeatureRegistry::all() as $feature) {
            $featureManager->define($feature->key, fn () => $feature->default);
        }
    }

    protected function configurePassport(): void
    {
        Passport::tokensExpireIn(now()->addDays(15));
        Passport::refreshTokensExpireIn(now()->addDays(30));
        Passport::personalAccessTokensExpireIn(now()->addMonths(6));

        Passport::tokensCan([
            'openid' => 'OpenID Connect identity',
            'profile' => 'User profile information (name, avatar)',
            'email' => 'User email address',
            'roles' => 'User roles and permissions',
            'phone' => 'User phone information',
            'wallet' => 'User wallet information',
            'metadata:public' => 'Read application-scoped public metadata',
            'users:read' => 'Read users in the application environment',
            'metadata:read' => 'Read application-scoped user metadata',
            'metadata:write' => 'Replace application-scoped user metadata',
            'sessions:read' => 'Read application-scoped OAuth grants',
            'sessions:revoke' => 'Revoke application-scoped OAuth grants',
            'memberships:write' => 'Manage application-environment membership of organization users',
            'organization:read' => 'Read the application environment organization',
        ]);

        Passport::setDefaultScope([
            'openid',
            'profile',
            'email',
        ]);

        Passport::authorizationView(
            fn (array $parameters): Response => Inertia::render('auth/oauth/Authorize', [
                'request' => $parameters['request'],
                'authToken' => $parameters['authToken'],
                'csrfToken' => csrf_token(),
                'application' => app(ApplicationAuthentication::class)->pageContext(request()),
                'joiningApplication' => (bool) request()->attributes->get('koamishin.joining_application', false),
                'client' => $parameters['client'],
                'user' => $parameters['user'],
                'scopes' => $parameters['scopes'],
            ])->toResponse(request())
        );
    }

    protected function registerFilamentPlugins(): void
    {
        Panel::configureUsing(function (Panel $panel): void {
            if ($panel->getId() !== 'admin') {
                return;
            }

            foreach (Module::allEnabled() as $module) {
                $plugin = sprintf('Modules\\%s\\%sPlugin', $module->getStudlyName(), $module->getStudlyName());

                if (class_exists($plugin) && method_exists($plugin, 'make')) {
                    $panel->plugin($plugin::make());
                }
            }
        });
    }
}
