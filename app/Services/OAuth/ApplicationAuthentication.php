<?php

declare(strict_types=1);

namespace App\Services\OAuth;

use App\Models\TenantApplicationEnvironment;
use App\Models\User;
use App\Services\Webhooks\WebhookDispatcher;
use Closure;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class ApplicationAuthentication
{
    private const SESSION_KEY = 'koamishin.application_flows';

    private const MAX_AGE = 600;

    private const SSO_MAX_AGE = 28800;

    public function __construct(private readonly ApplicationAccess $access) {}

    /** @return array{id: string, client_id: string, authorize_url: string, created_at: int} */
    public function begin(Request $request, string $clientId): array
    {
        $flows = $this->flows($request);
        $id = $request->query('flow');
        abort_if($id !== null && (! is_string($id) || $id === ''), 419, 'This application sign-in has expired. Start again from the application.');

        if (is_string($id) && $id !== '') {
            $flow = $flows[$id] ?? null;
            abort_unless(is_array($flow) && $flow['client_id'] === $clientId
                && $flow['authorize_url'] === $this->authorizationUrl($request, $id), 419, 'This application sign-in has expired. Start again from the application.');
        } else {
            $id = Str::random(40);
            $flow = [
                'id' => $id,
                'client_id' => $clientId,
                'authorize_url' => $this->authorizationUrl($request, $id),
                'created_at' => now()->timestamp,
            ];
            $retained = array_slice($flows, -9, null, true);
            foreach (array_diff_key($flows, $retained) as $expiredId => $_flow) {
                $this->forgetFlowState($request, $expiredId);
            }
            $flows = $retained;
            $flows[$id] = $flow;
            $request->session()->put(self::SESSION_KEY, $flows);
        }

        $this->activate($request, $flow);

        return $flow;
    }

    public function resolve(Request $request, ?string $flowId = null): void
    {
        $id = $flowId ?? $request->input('flow');
        if ($id === null) {
            return;
        }

        $flows = $this->flows($request);
        $flow = is_string($id) ? ($flows[$id] ?? null) : null;
        abort_unless(is_array($flow), 419, 'This application sign-in has expired. Start again from the application.');
        $environment = $this->access->environmentForClient($flow['client_id']);
        abort_unless($environment instanceof TenantApplicationEnvironment, 403);

        if ($request->is('register')) {
            abort_unless($this->allowsSignups($environment), 403, 'Registration is closed for this application.');
        }

        $this->activate($request, $flow);
        $request->attributes->set('koamishin.application_environment', $environment);
    }

    /** @param array{id: string, client_id: string, authorize_url: string, created_at: int} $flow */
    private function activate(Request $request, array $flow): void
    {
        $guard = new SessionGuard(
            'application_'.$flow['id'],
            Auth::createUserProvider(config('auth.guards.'.config('fortify.guard', 'web').'.provider')),
            $request->session(),
        );
        $guard->setCookieJar(app('cookie'));
        $guard->setDispatcher(app('events'));
        $guard->setRequest($request);

        $request->attributes->set('koamishin.application_flow', $flow);
        $request->attributes->set('koamishin.application_guard', $guard);
        $request->setUserResolver(fn (?string $name = null) => $name === null ? $guard->user() : Auth::guard($name)->user());
        $request->merge(['remember' => false]);
        $request->route()->flushController();
    }

    /** @param Closure(): Response $next */
    public function withinFlow(Request $request, Closure $next): Response
    {
        $id = $this->flowId($request);
        if ($id === null) {
            return $next();
        }

        $session = $request->session();
        $keys = ['login.id', 'login.remember', 'passkey.verification_options', 'authToken', 'authRequest', 'state', 'code_verifier'];
        $original = [];
        $state = $session->get('koamishin.application_state.'.$id, []);
        $state = is_array($state) ? $state : [];

        foreach ($keys as $key) {
            if ($session->has($key)) {
                $original[$key] = $session->get($key);
            }
            $session->forget($key);
            if (array_key_exists($key, $state)) {
                $session->put($key, $state[$key]);
            }
        }

        try {
            return $next();
        } finally {
            $state = [];
            foreach ($keys as $key) {
                if ($session->has($key)) {
                    $state[$key] = $session->get($key);
                }
                $session->forget($key);
                if (array_key_exists($key, $original)) {
                    $session->put($key, $original[$key]);
                }
            }

            if (! $request->attributes->get('koamishin.application_finished', false)) {
                $session->put('koamishin.application_state.'.$id, $state);
            }
        }
    }

    public function finish(Request $request): void
    {
        $id = $this->flowId($request);
        $guard = $request->attributes->get('koamishin.application_guard');
        if ($id === null || ! $guard instanceof SessionGuard) {
            return;
        }

        $flows = $request->session()->get(self::SESSION_KEY, []);
        if (is_array($flows)) {
            unset($flows[$id]);
            $request->session()->put(self::SESSION_KEY, $flows);
        }
        $this->forgetFlowState($request, $id);
        $request->attributes->set('koamishin.application_finished', true);
    }

    /** @return array<string, mixed>|null */
    public function pageContext(Request $request): ?array
    {
        $environment = $request->attributes->get('koamishin.application_environment');
        if (! $environment instanceof TenantApplicationEnvironment) {
            return null;
        }

        return [
            'id' => (string) $environment->tenant_application_id,
            'name' => $environment->application->name,
            'organization' => $environment->application->tenant->name,
            'environment' => $environment->environment,
            'allowSignups' => $this->allowsSignups($environment),
            'passkeysEnabled' => $environment->allowsPasskeys(),
            'loginTitle' => $environment->getSetting('login_title'),
            'loginDescription' => $environment->getSetting('login_description'),
            'logoUrl' => $environment->getSetting('login_logo_url'),
        ];
    }

    public function reuseSingleSignOn(Request $request, TenantApplicationEnvironment $target): bool
    {
        if (! $target->allowsSingleSignOn()) {
            return false;
        }

        $key = 'koamishin.sso.'.$target->application->tenant_id.'.'.$target->environment;
        $session = $request->session()->get($key);
        $user = is_array($session) ? $this->validatedSsoUser($session, $target) : null;
        if (! $user instanceof User) {
            $request->session()->forget($key);

            return false;
        }

        $guard = $request->attributes->get('koamishin.application_guard');
        if (! $guard instanceof SessionGuard) {
            return false;
        }

        $guard->login($user);
        $request->session()->put('koamishin.sso_reused.'.$this->flowId($request), $session);

        return true;
    }

    public function ensureSingleSignOnStillAllowed(Request $request, TenantApplicationEnvironment $target, User $user): void
    {
        $session = $request->session()->get('koamishin.sso_reused.'.$this->flowId($request));
        if ($session === null) {
            return;
        }

        $sourceUser = is_array($session) ? $this->validatedSsoUser($session, $target) : null;
        abort_unless($sourceUser instanceof User && $sourceUser->is($user), 403, 'Single sign-on is no longer available. Start again from the application.');
    }

    /** @param array<string, mixed> $session */
    private function validatedSsoUser(array $session, TenantApplicationEnvironment $target): ?User
    {
        if (! $target->allowsSingleSignOn() || ! is_int($session['authenticated_at'] ?? null)
            || $session['authenticated_at'] < now()->timestamp - self::SSO_MAX_AGE
            || ! is_string($session['client_id'] ?? null) || ! is_string($session['fingerprint'] ?? null)) {
            return null;
        }

        $source = $this->access->environmentForClient($session['client_id']);
        $user = User::query()->find($session['user_id'] ?? null);
        if (! $source instanceof TenantApplicationEnvironment || ! $source->allowsSingleSignOn()
            || $source->application->tenant_id !== $target->application->tenant_id
            || $source->environment !== $target->environment || ! $user instanceof User
            || ! $this->access->allows($source, $user)
            || ! hash_equals($session['fingerprint'], $this->ssoFingerprint($user))) {
            return null;
        }

        return $user;
    }

    public function rememberSingleSignOn(Request $request, TenantApplicationEnvironment $environment, User $user, Response $response): void
    {
        if (! $environment->allowsSingleSignOn() || ! $response->isRedirection() || ! $this->access->allows($environment, $user)
            || ($user->hasMfa() && ! $request->session()->get('koamishin.sso_mfa_verified.'.$this->flowId($request))
                && ! $request->session()->has('koamishin.sso_reused.'.$this->flowId($request)))) {
            return;
        }

        $location = $response->headers->get('Location');
        parse_str(is_string($location) ? (string) parse_url($location, PHP_URL_QUERY) : '', $query);
        if (! is_string($query['code'] ?? null) || $query['code'] === '') {
            return;
        }

        $request->session()->put('koamishin.sso.'.$environment->application->tenant_id.'.'.$environment->environment, [
            'client_id' => $environment->oauth_client_id,
            'user_id' => $user->getKey(),
            'authenticated_at' => $request->session()->get('koamishin.sso_reused.'.$this->flowId($request).'.authenticated_at', now()->timestamp),
            'fingerprint' => $this->ssoFingerprint($user),
        ]);
    }

    private function ssoFingerprint(User $user): string
    {
        return hash_hmac('sha256', implode('|', [
            (string) $user->password,
            (string) $user->two_factor_secret,
            (string) $user->two_factor_confirmed_at,
            (string) $user->app_authentication_secret,
            (string) $user->has_email_authentication,
        ]), (string) config('app.key'));
    }

    public function flowId(Request $request): ?string
    {
        return $request->attributes->get('koamishin.application_flow')['id'] ?? null;
    }

    public function hasFlow(Request $request, string $id): bool
    {
        return isset($this->flows($request)[$id]);
    }

    public function resume(Request $request): ?Response
    {
        $flow = $request->attributes->get('koamishin.application_flow');
        if (! is_array($flow)) {
            return null;
        }

        return $request->header('X-Inertia')
            ? Inertia::location($flow['authorize_url'])
            : redirect()->to($flow['authorize_url']);
    }

    public function enrollNewUser(Request $request, User $user): void
    {
        $flow = $request->attributes->get('koamishin.application_flow');
        if (! is_array($flow)) {
            return;
        }

        $environment = $this->access->environmentForClient($flow['client_id']);
        abort_unless($environment instanceof TenantApplicationEnvironment && $this->allowsSignups($environment), 403);
        $this->enroll($environment, $user);
    }

    public function allowsSignups(TenantApplicationEnvironment $environment): bool
    {
        return $environment->getSetting('allow_signups', false) === true;
    }

    public function enroll(TenantApplicationEnvironment $environment, User $user): void
    {
        abort_unless($this->allowsSignups($environment) && ! $user->isBanned(), 403);

        DB::transaction(function () use ($environment, $user): void {
            $userQuery = User::query()->whereKey($user->getKey());
            $userQuery->lockForUpdate();
            $lockedUser = $userQuery->firstOrFail();
            abort_if($lockedUser->isBanned(), 403);
            $current = is_string($environment->oauth_client_id)
                ? $this->access->environmentForClient($environment->oauth_client_id)
                : null;
            abort_unless($current?->is($environment) && $this->allowsSignups($current), 403);
            $tenant = $current->application->tenant;
            $tenant->users()->syncWithoutDetaching([$user->getKey()]);
            $membership = $current->application->users($current->environment)->syncWithoutDetaching([
                $user->getKey() => ['environment' => $current->environment],
            ]);

            if ($membership['attached'] !== []) {
                WebhookDispatcher::dispatchForEnvironment($current, 'user.created', $user);
            }
        });
    }

    /** @return array<string, array{id: string, client_id: string, authorize_url: string, created_at: int}> */
    private function flows(Request $request): array
    {
        $flows = $request->session()->get(self::SESSION_KEY, []);
        if (! is_array($flows)) {
            return [];
        }

        $valid = [];
        foreach ($flows as $id => $flow) {
            if (! is_string($id) || ! is_array($flow)
                || ! is_string($flow['id'] ?? null)
                || ! is_string($flow['client_id'] ?? null)
                || ! is_string($flow['authorize_url'] ?? null)
                || ! is_int($flow['created_at'] ?? null)
                || $flow['created_at'] < now()->timestamp - self::MAX_AGE) {
                if (is_string($id)) {
                    $this->forgetFlowState($request, $id);
                }

                continue;
            }

            $valid[$id] = [
                'id' => $flow['id'],
                'client_id' => $flow['client_id'],
                'authorize_url' => $flow['authorize_url'],
                'created_at' => $flow['created_at'],
            ];
        }

        return $valid;
    }

    private function forgetFlowState(Request $request, string $id): void
    {
        $request->session()->forget([
            'login_application_'.$id.'_'.sha1(SessionGuard::class),
            'password_hash_application_'.$id,
            'koamishin.application_state.'.$id,
            'koamishin.verification_sent.'.$id,
            'koamishin.sso_reused.'.$id,
            'koamishin.sso_mfa_verified.'.$id,
        ]);

        $socialFlows = $request->session()->get('koamishin.social_flows', []);
        if (is_array($socialFlows)) {
            $request->session()->put('koamishin.social_flows', array_filter(
                $socialFlows,
                fn ($socialFlow): bool => is_array($socialFlow) && ($socialFlow['flow'] ?? null) !== $id,
            ));
        }
    }

    private function authorizationUrl(Request $request, string $flow): string
    {
        $query = $request->query();
        unset($query['screen_hint']);
        $query['flow'] = $flow;

        return route('passport.authorizations.authorize', $query, absolute: false);
    }
}
