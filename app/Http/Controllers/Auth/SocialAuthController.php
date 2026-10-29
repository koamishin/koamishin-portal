<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\RoleEnums;
use App\Enums\SocialLoginProvider;
use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\TenantApplicationEnvironment;
use App\Models\User;
use App\Services\OAuth\ApplicationAuthentication;
use App\Services\OAuth\ApplicationSocialConnections;
use App\Settings\ApplicationFeaturesSettings;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Laravel\Fortify\Features;
use Laravel\Socialite\Contracts\Provider as ProviderContract;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\SocialiteManager;
use Laravel\Socialite\Two\FacebookProvider;
use Laravel\Socialite\Two\GithubProvider;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class SocialAuthController extends Controller
{
    public function __construct(
        private readonly ApplicationSocialConnections $socialConnections,
        private readonly ApplicationFeaturesSettings $applicationFeaturesSettings,
    ) {}

    public function redirect(Request $request, string $provider): RedirectResponse|Response
    {
        $providerEnum = SocialLoginProvider::tryFromSlug($provider);

        if (! $providerEnum instanceof SocialLoginProvider) {
            abort(404);
        }

        $environment = $request->attributes->get('koamishin.application_environment');
        $credentials = $this->socialConnections->credentials($providerEnum, $environment instanceof TenantApplicationEnvironment ? $environment : null);
        if ($credentials === null) {
            return redirect()
                ->route('login', array_filter(['flow' => app(ApplicationAuthentication::class)->flowId($request)]))
                ->withErrors(['social' => 'This social login provider is not configured.']);
        }

        $driver = $this->buildDriver($providerEnum, $credentials);

        return $driver->redirect();
    }

    public function callback(Request $request, string $provider): RedirectResponse|Response
    {
        $providerEnum = SocialLoginProvider::tryFromSlug($provider);

        if (! $providerEnum instanceof SocialLoginProvider) {
            abort(404);
        }

        $environment = $request->attributes->get('koamishin.application_environment');
        $credentials = $this->socialConnections->credentials($providerEnum, $environment instanceof TenantApplicationEnvironment ? $environment : null);
        if ($credentials === null) {
            return redirect()
                ->route('login', array_filter(['flow' => app(ApplicationAuthentication::class)->flowId($request)]))
                ->withErrors(['social' => 'This social login provider is not configured.']);
        }

        try {
            $socialUser = $this->buildDriver($providerEnum, $credentials)->user();
        } catch (Throwable $e) {
            Log::warning('Social login callback failed', [
                'provider' => $providerEnum->value,
                'error' => $e->getMessage(),
            ]);

            return redirect()
                ->route('login', array_filter(['flow' => app(ApplicationAuthentication::class)->flowId($request)]))
                ->withErrors(['social' => 'Unable to authenticate with '.($providerEnum->label()).'. Please try again.']);
        }

        abort_unless(filled($socialUser->getId()) && filter_var($socialUser->getEmail(), FILTER_VALIDATE_EMAIL), 422, 'The provider did not return an email address.');
        $verifiedEmail = $providerEnum === SocialLoginProvider::Github
            || ($providerEnum === SocialLoginProvider::Google && $socialUser instanceof SocialiteUser && ($socialUser->getRaw()['email_verified'] ?? false) === true);

        $user = DB::transaction(function () use ($providerEnum, $socialUser, $credentials, $verifiedEmail): User {
            $existingAccount = SocialAccount::query()
                ->where('provider', $providerEnum->value)
                ->where('provider_id', $socialUser->getId())
                ->where('connection_scope', $credentials['scope'])
                ->first();

            if ($existingAccount instanceof SocialAccount) {
                $user = $existingAccount->user;
            } else {
                $user = User::query()->where('email', $socialUser->getEmail())->first();

                if ($user instanceof User) {
                    if (! $verifiedEmail) {
                        abort(409, 'This provider did not confirm ownership of the existing account email. Sign in with another method.');
                    }
                    if ($user->email_verified_at === null) {
                        abort(409, 'A user with that email exists but is not yet verified. Please verify your email first.');
                    }
                } else {
                    abort_unless($this->applicationFeaturesSettings->registration_enabled, 403, 'Registration is disabled.');
                    $environment = request()->attributes->get('koamishin.application_environment');
                    if ($environment instanceof TenantApplicationEnvironment) {
                        abort_unless(app(ApplicationAuthentication::class)->allowsSignups($environment), 403, 'Registration is closed for this application.');
                    }
                    $user = $this->createUserFromSocial($socialUser, $verifiedEmail);
                }
            }

            $this->upsertSocialAccount($user, $providerEnum, $socialUser, $credentials['scope']);

            $this->refreshProfilePhoto($user, $socialUser);

            return $user;
        });

        if ($verifiedEmail && $user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        if ($user->isBanned()) {
            return redirect()
                ->route('login', array_filter(['flow' => app(ApplicationAuthentication::class)->flowId($request)]))
                ->withErrors([
                    'social' => $user->ban_reason
                        ? 'Your account has been suspended: '.$user->ban_reason
                        : 'Your account has been suspended. Please contact support.',
                ]);
        }

        if (Features::enabled(Features::twoFactorAuthentication()) && $user->two_factor_secret && $user->two_factor_confirmed_at) {
            $request->session()->put(['login.id' => $user->getKey(), 'login.remember' => false]);
            TwoFactorAuthenticationChallenged::dispatch($user);

            return redirect()->route('two-factor.login', array_filter(['flow' => app(ApplicationAuthentication::class)->flowId($request)]));
        }

        $guard = $request->attributes->get('koamishin.application_guard');
        if ($guard instanceof StatefulGuard) {
            $guard->login($user);
            $request->session()->regenerate();

            return app(ApplicationAuthentication::class)->resume($request);
        }

        Auth::login($user, true);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /** @param array{client_id: string, client_secret: string, redirect: string, scope: string} $credentials */
    private function buildDriver(SocialLoginProvider $socialLoginProvider, array $credentials): ProviderContract
    {
        $manager = Socialite::getFacadeRoot();
        if (! $manager instanceof SocialiteManager) {
            return Socialite::driver($socialLoginProvider->value);
        }

        $provider = match ($socialLoginProvider) {
            SocialLoginProvider::Github => GithubProvider::class,
            SocialLoginProvider::Google => GoogleProvider::class,
            SocialLoginProvider::Facebook => FacebookProvider::class,
        };

        return $manager->buildProvider($provider, $credentials);
    }

    private function createUserFromSocial(\Laravel\Socialite\Contracts\User $socialUser, bool $verifiedEmail): User
    {
        $defaultRole = $this->applicationFeaturesSettings->default_user_role;

        $user = User::query()->create([
            'name' => $socialUser->getName() ?? $socialUser->getNickname() ?? 'User',
            'email' => $socialUser->getEmail(),
            'password' => null,
            'email_verified_at' => $verifiedEmail ? now() : null,
        ]);

        $roleName = filled($defaultRole) ? $defaultRole : RoleEnums::USER->value;
        $user->assignRole(Role::query()->firstOrCreate([
            'name' => $roleName,
            'guard_name' => 'web',
        ]));

        return $user;
    }

    private function upsertSocialAccount(User $user, SocialLoginProvider $socialLoginProvider, \Laravel\Socialite\Contracts\User $socialUser, string $connectionScope): SocialAccount
    {
        return SocialAccount::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'provider' => $socialLoginProvider->value,
                'connection_scope' => $connectionScope,
            ],
            [
                'provider_id' => $socialUser->getId(),
                'nickname' => $socialUser->getNickname(),
                'avatar_url' => $socialUser->getAvatar(),
                'access_token' => $connectionScope === 'portal' ? $socialUser->token : null,
                'refresh_token' => $connectionScope === 'portal' ? $socialUser->refreshToken : null,
                'expires_at' => $socialUser->expiresIn ? now()->addSeconds((int) $socialUser->expiresIn) : null,
            ],
        );
    }

    private function refreshProfilePhoto(User $user, \Laravel\Socialite\Contracts\User $socialUser): void
    {
        $avatar = $socialUser->getAvatar();

        if (! filled($avatar)) {
            return;
        }

        $user->forceFill(['profile_photo_path' => $avatar])->save();
    }
}
