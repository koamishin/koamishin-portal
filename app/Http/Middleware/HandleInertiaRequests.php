<?php

namespace App\Http\Middleware;

use App\Enums\SocialLoginProvider;
use App\Features\FeatureRegistry;
use App\Models\TenantApplicationEnvironment;
use App\Services\OAuth\ApplicationSocialConnections;
use App\Settings\ApplicationFeaturesSettings;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    #[\Override]
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function share(Request $request): array
    {
        FeatureRegistry::initialize();

        $user = $request->user();
        $settingsFeatures = [];

        if ($user) {
            $settingsFeatures = [
                'profile' => FeatureRegistry::isFeatureAvailableForUser($user, 'settings_profile'),
                'security' => FeatureRegistry::isFeatureAvailableForUser($user, 'settings_mfa_app')
                    || FeatureRegistry::isFeatureAvailableForUser($user, 'settings_mfa_email')
                    || FeatureRegistry::isFeatureAvailableForUser($user, 'settings_password')
                    || FeatureRegistry::isFeatureAvailableForUser($user, 'settings_passkeys'),
                'password' => FeatureRegistry::isFeatureAvailableForUser($user, 'settings_password'),
                'appearance' => FeatureRegistry::isFeatureAvailableForUser($user, 'settings_appearance'),
                'passkeys' => FeatureRegistry::isFeatureAvailableForUser($user, 'settings_passkeys'),
            ];
        }

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
                'impersonating' => app('impersonate')->isImpersonating(),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'authLayout' => function () use ($request): string {
                $environment = $request->attributes->get('koamishin.application_environment');
                $layout = $environment instanceof TenantApplicationEnvironment ? $environment->getSetting('auth_layout') : null;

                return in_array($layout, ['simple', 'card', 'split'], true)
                    ? $layout
                    : app(ApplicationFeaturesSettings::class)->auth_layout;
            },
            'notificationsEnabled' => app(ApplicationFeaturesSettings::class)->notifications_enabled,
            'settingsFeatures' => $settingsFeatures,
            'socialProviders' => function () use ($request): array {
                $environment = $request->attributes->get('koamishin.application_environment');
                $environment = $environment instanceof TenantApplicationEnvironment ? $environment : null;
                $connections = app(ApplicationSocialConnections::class);

                return collect(SocialLoginProvider::cases())
                    ->filter(fn (SocialLoginProvider $provider): bool => $connections->credentials($provider, $environment) !== null)
                    ->map(fn (SocialLoginProvider $provider): array => [
                        'slug' => $provider->value,
                        'label' => $provider->label(),
                        'url' => route('auth.social.redirect', ['provider' => $provider->value]),
                    ])->values()->all();
            },
        ];
    }
}
