<?php

declare(strict_types=1);

namespace App\Services\Management;

use App\Models\TenantApplication;
use App\Models\TenantApplicationEnvironment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\Request;

class ManagementContext
{
    public function environment(Request $request): TenantApplicationEnvironment
    {
        $environment = $request->attributes->get('koamishin_management_environment');
        abort_unless($environment instanceof TenantApplicationEnvironment, 403);

        return $environment;
    }

    /** @return BelongsToMany<User, TenantApplication> */
    public function users(TenantApplicationEnvironment $environment): BelongsToMany
    {
        $application = $environment->application;

        $users = $application->users($environment->environment);
        $users->whereHas('tenants', fn (Builder $query): Builder => $query->whereKey($application->tenant_id));

        return $users;
    }

    public function user(TenantApplicationEnvironment $environment, string $userId): User
    {
        return $this->users($environment)->where('users.id', $userId)->firstOrFail();
    }
}
