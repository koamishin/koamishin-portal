<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TenantApplicationEnvironment;
use App\Models\User;
use App\Services\Management\ManagementContext;
use App\Services\Webhooks\WebhookDispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;
use Symfony\Component\HttpFoundation\Response;

class ManagementMembershipController extends Controller
{
    public function __construct(private readonly ManagementContext $context) {}

    public function store(Request $request, string $userId): Response
    {
        $environment = $this->context->environment($request);

        DB::transaction(function () use ($environment, $userId): void {
            $user = $this->tenantUser($environment, $userId);
            $inserted = DB::table('tenant_application_user')->insertOrIgnore([
                'tenant_application_id' => $environment->tenant_application_id,
                'user_id' => $user->getKey(),
                'environment' => $environment->environment,
                'role' => 'member',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($inserted === 1) {
                WebhookDispatcher::dispatchForEnvironment($environment, 'user.created', $user);
            } else {
                $this->context->user($environment, $userId);
            }
        });

        return response()->noContent();
    }

    public function destroy(Request $request, string $userId): Response
    {
        $environment = $this->context->environment($request);

        DB::transaction(function () use ($environment, $userId): void {
            $user = $this->tenantUser($environment, $userId);
            $this->context->user($environment, $userId);
            $clientId = $environment->oauth_client_id;

            if ($clientId !== null) {
                $count = TenantApplicationEnvironment::query()->where('oauth_client_id', $clientId)->count();
                abort_unless($count === 1, 409, 'The application OAuth client is not uniquely bound.');

                $tokenIds = Token::query()->where('client_id', $clientId)->where('user_id', $user->getKey())->select('id');
                RefreshToken::query()->whereIn('access_token_id', $tokenIds)->update(['revoked' => true]);
                Token::query()->where('client_id', $clientId)->where('user_id', $user->getKey())->update(['revoked' => true]);
            }

            DB::table('tenant_application_user')
                ->where('tenant_application_id', $environment->tenant_application_id)
                ->where('environment', $environment->environment)
                ->where('user_id', $user->getKey())
                ->delete();
        });

        return response()->noContent();
    }

    private function tenantUser(TenantApplicationEnvironment $environment, string $userId): User
    {
        $users = $environment->application->tenant->users();
        $users->where('users.id', $userId);
        $users->lockForUpdate();

        return $users->firstOrFail();
    }
}
