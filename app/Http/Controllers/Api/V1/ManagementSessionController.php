<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ManagementSessionResource;
use App\Models\TenantApplicationEnvironment;
use App\Models\User;
use App\Services\Management\ManagementContext;
use App\Services\OAuth\ApplicationAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;
use Symfony\Component\HttpFoundation\Response;

class ManagementSessionController extends Controller
{
    public function __construct(
        private readonly ManagementContext $context,
        private readonly ApplicationAccess $access,
    ) {}

    public function index(Request $request, string $userId): AnonymousResourceCollection
    {
        $input = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $environment = $this->context->environment($request);
        $user = $this->context->user($environment, $userId);
        $perPage = (int) ($input['per_page'] ?? 15);

        $tokens = $this->tokens($environment, $user)
            ->with('refreshToken')
            ->where(function (Builder $query): void {
                $query->where(function (Builder $access): void {
                    $access->where('revoked', false)->where('expires_at', '>', now());
                })->orWhereHas('refreshToken', function (Builder $refresh): void {
                    $refresh->where('revoked', false)->where('expires_at', '>', now());
                });
            })
            ->orderByDesc('created_at')
            ->orderBy('id')
            ->paginate($perPage)
            ->appends(['per_page' => $perPage]);

        return ManagementSessionResource::collection($tokens);
    }

    public function destroy(Request $request, string $userId, string $sessionId): Response
    {
        $environment = $this->context->environment($request);
        $user = $this->context->user($environment, $userId);
        $tokens = $this->tokens($environment, $user);

        (new Token)->getConnection()->transaction(function () use ($tokens, $sessionId): void {
            $tokens->whereKey($sessionId);
            $tokens->lockForUpdate();
            $token = $tokens->firstOrFail();
            RefreshToken::query()->where('access_token_id', $token->getKey())->update(['revoked' => true]);
            $token->forceFill(['revoked' => true])->save();
        });

        return response()->noContent();
    }

    /** @return Builder<Token> */
    private function tokens(TenantApplicationEnvironment $environment, User $user): Builder
    {
        $clientId = $environment->oauth_client_id;
        $clientEnvironment = is_string($clientId) ? $this->access->environmentForClient($clientId) : null;
        abort_unless($clientEnvironment?->is($environment), 409, 'The application environment has no uniquely configured active OAuth client.');

        return Token::query()->where('client_id', $clientId)->where('user_id', $user->getKey());
    }
}
