<?php

declare(strict_types=1);

namespace App\Services\Webhooks;

use App\Jobs\DispatchWebhookJob;
use App\Models\ApplicationUserMetadata;
use App\Models\Tenant;
use App\Models\TenantApplication;
use App\Models\TenantApplicationEnvironment;
use App\Models\User;
use App\Models\WebhookEndpoint;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

class WebhookDispatcher
{
    public static function dispatchForEnvironment(TenantApplicationEnvironment $environment, string $event, User $user): void
    {
        if (! self::isMember($environment, $user)) {
            return;
        }

        self::dispatchPending(self::deliveriesForEnvironment($environment, $event, $user));
    }

    public static function dispatchForUser(string $event, User $user): void
    {
        foreach (self::environmentsForUser($user) as $environment) {
            self::dispatchForEnvironment($environment, $event, $user);
        }
    }

    /**
     * Capture the eligible recipients before deleting a user and its memberships.
     *
     * @return list<array{endpoint: WebhookEndpoint, event: string, payload: array<string, mixed>}>
     */
    public static function pendingDeletion(User $user): array
    {
        $deliveries = [];

        foreach (self::environmentsForUser($user) as $environment) {
            if (self::isMember($environment, $user)) {
                array_push($deliveries, ...self::deliveriesForEnvironment($environment, 'user.deleted', $user));
            }
        }

        return $deliveries;
    }

    /**
     * @param  list<array{endpoint: WebhookEndpoint, event: string, payload: array<string, mixed>}>  $deliveries
     */
    public static function dispatchPending(array $deliveries): void
    {
        foreach ($deliveries as $delivery) {
            DispatchWebhookJob::dispatch($delivery['endpoint'], $delivery['event'], $delivery['payload'])->afterCommit();
        }
    }

    /** @return Collection<int, TenantApplicationEnvironment> */
    private static function environmentsForUser(User $user): Collection
    {
        $environments = TenantApplicationEnvironment::query();
        $environments->with('application.tenant');
        $environments->whereExists(function (Builder $query) use ($user): void {
            $query->selectRaw('1')
                ->from('tenant_application_user')
                ->whereColumn('tenant_application_user.tenant_application_id', 'tenant_application_environments.tenant_application_id')
                ->whereColumn('tenant_application_user.environment', 'tenant_application_environments.environment')
                ->where('tenant_application_user.user_id', $user->getKey());
        });

        return $environments->get();
    }

    private static function isMember(TenantApplicationEnvironment $environment, User $user): bool
    {
        $application = $environment->getRelationValue('application');
        $tenant = $application instanceof TenantApplication ? $application->getRelationValue('tenant') : null;

        return $application instanceof TenantApplication
            && $tenant instanceof Tenant
            && $tenant->is_active
            && in_array($environment->environment, ['development', 'production'], true)
            && $tenant->users()->whereKey($user->getKey())->exists()
            && $application->users($environment->environment)->whereKey($user->getKey())->exists();
    }

    /**
     * @return list<array{endpoint: WebhookEndpoint, event: string, payload: array<string, mixed>}>
     */
    private static function deliveriesForEnvironment(TenantApplicationEnvironment $environment, string $event, User $user): array
    {
        $data = ['id' => (string) $user->getKey()];

        if ($event !== 'user.deleted') {
            $metadata = ApplicationUserMetadata::query()
                ->where('tenant_application_environment_id', $environment->getKey())
                ->where('user_id', $user->getKey())
                ->first();

            $data += [
                'name' => $user->name,
                'email' => $user->email,
                'email_verified' => $user->hasVerifiedEmail(),
                'banned' => $user->isBanned(),
                'public_metadata' => $metadata->public_metadata ?? new \stdClass,
                'created_at' => $user->created_at?->toIso8601String(),
                'updated_at' => $user->updated_at?->toIso8601String(),
            ];
        }

        $payload = [
            'type' => $event,
            'timestamp' => now()->timestamp,
            'tenant_id' => $environment->application->tenant_id,
            'application_id' => $environment->tenant_application_id,
            'environment' => $environment->environment,
            'data' => $data,
        ];

        return WebhookEndpoint::query()
            ->where('tenant_application_environment_id', $environment->getKey())
            ->where('is_active', true)
            ->get()
            ->filter(fn (WebhookEndpoint $endpoint): bool => in_array($event, $endpoint->events ?? [], true) || in_array('*', $endpoint->events ?? [], true))
            ->map(fn (WebhookEndpoint $endpoint): array => ['endpoint' => $endpoint, 'event' => $event, 'payload' => $payload])
            ->values()
            ->all();
    }
}
