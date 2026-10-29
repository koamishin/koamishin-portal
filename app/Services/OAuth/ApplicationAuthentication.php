<?php

declare(strict_types=1);

namespace App\Services\OAuth;

use App\Models\TenantApplicationEnvironment;
use App\Models\User;
use App\Services\Webhooks\WebhookDispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class ApplicationAuthentication
{
    private const SESSION_KEY = 'koamishin.application_flows';

    private const MAX_AGE = 600;

    public function __construct(private readonly ApplicationAccess $access) {}

    /** @return array{id: string, client_id: string, authorize_url: string, created_at: int} */
    public function begin(Request $request, string $clientId): array
    {
        $flows = $this->flows($request);
        $id = $request->query('flow');

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
            $flows = array_slice($flows, -9, null, true);
            $flows[$id] = $flow;
            $request->session()->put(self::SESSION_KEY, $flows);
        }

        $request->attributes->set('koamishin.application_flow', $flow);
        if (! $request->user()) {
            $request->session()->put('url.intended', $flow['authorize_url']);
        }

        return $flow;
    }

    public function resolve(Request $request): void
    {
        $id = $request->input('flow');
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

        $request->attributes->set('koamishin.application_flow', $flow);
        if (! $request->user()) {
            $request->session()->put('url.intended', $flow['authorize_url']);
        }
        $request->attributes->set('koamishin.application_environment', $environment);
    }

    /** @return array{id: string, name: string, organization: string, environment: string, allowSignups: bool}|null */
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
        ];
    }

    public function flowId(Request $request): ?string
    {
        return $request->attributes->get('koamishin.application_flow')['id'] ?? null;
    }

    public function resume(Request $request): ?Response
    {
        $flow = $request->attributes->get('koamishin.application_flow');
        if (! is_array($flow)) {
            return null;
        }

        $request->session()->forget('url.intended');

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

    private function authorizationUrl(Request $request, string $flow): string
    {
        $query = $request->query();
        unset($query['screen_hint']);
        $query['flow'] = $flow;

        return route('passport.authorizations.authorize', $query, absolute: false);
    }
}
