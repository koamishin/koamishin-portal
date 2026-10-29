<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Http\Controllers\Auth\ApplicationPasskeyLoginController;
use App\Http\Responses\ApplicationLoginResponse;
use App\Http\Responses\ApplicationPasskeyLoginResponse;
use App\Http\Responses\ApplicationRegisterResponse;
use App\Http\Responses\ApplicationTwoFactorLoginResponse;
use App\Http\Responses\ApplicationVerifyEmailResponse;
use App\Models\User;
use App\Services\OAuth\ApplicationAuthentication;
use App\Settings\ApplicationFeaturesSettings;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Contracts\RegisterResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;
use Laravel\Fortify\Contracts\VerifyEmailResponse;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse;
use Laravel\Passkeys\Http\Controllers\PasskeyLoginController;
use Laravel\Passport\Http\Controllers\AuthorizationController;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    #[\Override]
    public function register(): void
    {
        $this->app->bind(LoginResponse::class, ApplicationLoginResponse::class);
        $this->app->bind(RegisterResponse::class, ApplicationRegisterResponse::class);
        $this->app->bind(TwoFactorLoginResponse::class, ApplicationTwoFactorLoginResponse::class);
        $this->app->bind(VerifyEmailResponse::class, ApplicationVerifyEmailResponse::class);
        $this->app->bind(PasskeyLoginResponse::class, ApplicationPasskeyLoginResponse::class);
        $this->app->bind(PasskeyLoginController::class, ApplicationPasskeyLoginController::class);
        $this->app->bind(StatefulGuard::class, fn () => request()->attributes->get('koamishin.application_guard')
            ?? Auth::guard(config('fortify.guard')));
        $this->app->when(AuthorizationController::class)->needs(StatefulGuard::class)->give(
            fn () => request()->attributes->get('koamishin.application_guard') ?? Auth::guard(config('passport.guard')),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
        $this->configureAuthentication();
        VerifyEmail::createUrlUsing(function (User $user): string {
            $authentication = app(ApplicationAuthentication::class);
            $flow = $authentication->flowId(request());
            $applicationFlow = request()->attributes->get('koamishin.application_flow');
            $hosted = $authentication->isHosted(request()) && is_array($applicationFlow);

            return URL::temporarySignedRoute('verification.verify', now()->addMinutes(config('auth.verification.expire', 60)), array_filter([
                'id' => $user->getKey(),
                'hash' => sha1($user->getEmailForVerification()),
                'flow' => $hosted ? null : $flow,
                'client_id' => $hosted ? $applicationFlow['client_id'] : null,
            ], fn ($value): bool => $value !== null));
        });
    }

    /**
     * Configure authentication and account status checks.
     */
    private function configureAuthentication(): void
    {
        Fortify::authenticateUsing(function (Request $request): ?User {
            $user = User::query()->where('email', $request->input(Fortify::username()))->first();

            if ($user && Hash::check($request->input('password'), $user->password)) {
                if ($user->isBanned()) {
                    throw ValidationException::withMessages([
                        Fortify::username() => $user->ban_reason
                            ? __('Your account has been suspended: :reason', ['reason' => $user->ban_reason])
                            : __('Your account has been suspended. Please contact support.'),
                    ]);
                }

                return $user;
            }

            return null;
        });
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()) && app(ApplicationFeaturesSettings::class)->password_reset_enabled,
            'canRegister' => Features::enabled(Features::registration()) && app(ApplicationFeaturesSettings::class)->registration_enabled,
            'status' => $request->session()->get('status'),
            'application' => app(ApplicationAuthentication::class)->pageContext($request),
            'flow' => app(ApplicationAuthentication::class)->flowId($request),
            'hosted' => app(ApplicationAuthentication::class)->isHosted($request),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/VerifyEmail', [
            'status' => $request->session()->get('status'),
            'flow' => app(ApplicationAuthentication::class)->flowId($request),
            'hosted' => app(ApplicationAuthentication::class)->isHosted($request),
            'application' => app(ApplicationAuthentication::class)->pageContext($request),
        ]));

        Fortify::registerView(fn (Request $request) => Inertia::render('auth/Register', [
            'application' => app(ApplicationAuthentication::class)->pageContext($request),
            'flow' => app(ApplicationAuthentication::class)->flowId($request),
            'hosted' => app(ApplicationAuthentication::class)->isHosted($request),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::twoFactorChallengeView(fn (Request $request) => Inertia::render('auth/TwoFactorChallenge', [
            'flow' => app(ApplicationAuthentication::class)->flowId($request),
            'hosted' => app(ApplicationAuthentication::class)->isHosted($request),
            'application' => app(ApplicationAuthentication::class)->pageContext($request),
        ]));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/ConfirmPassword'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute(5)->by($request->session()->get('login.id')));

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('passkeys', fn (Request $request) => Limit::perMinute(10)->by(
            ($request->input('credential.id') ?: $request->session()->getId()).'|'.$request->ip(),
        ));
    }
}
