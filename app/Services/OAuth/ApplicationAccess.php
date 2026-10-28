<?php

declare(strict_types=1);

namespace App\Services\OAuth;

use App\Models\Tenant;
use App\Models\TenantApplication;
use App\Models\TenantApplicationEnvironment;
use App\Models\User;

class ApplicationAccess
{
    public function environmentForClient(string $clientId): ?TenantApplicationEnvironment
    {
        $query = TenantApplicationEnvironment::query();
        $query->with(['application.tenant', 'oauthClient']);
        $query->where('oauth_client_id', $clientId);
        $query->limit(2);
        $environments = $query->get();

        if ($environments->count() !== 1) {
            return null;
        }

        $environment = $environments->first();
        if (! $environment instanceof TenantApplicationEnvironment) {
            return null;
        }

        $application = $environment->getRelationValue('application');
        $tenant = $application instanceof TenantApplication ? $application->getRelationValue('tenant') : null;
        $client = $environment->oauthClient;

        if (! $tenant instanceof Tenant || ! $tenant->is_active
            || ! in_array($environment->environment, ['development', 'production'], true)
            || ! $client || $client->getAttribute('revoked')) {
            return null;
        }

        return $environment;
    }

    public function allows(TenantApplicationEnvironment $environment, User $user): bool
    {
        $application = $environment->getRelationValue('application');
        $tenant = $application instanceof TenantApplication ? $application->getRelationValue('tenant') : null;

        return $application instanceof TenantApplication
            && $tenant instanceof Tenant
            && ! $user->isBanned()
            && $tenant->users()->whereKey($user->getKey())->exists()
            && $application->users($environment->environment)->whereKey($user->getKey())->exists();
    }
}
