<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApplicationUserMetadata;
use App\Models\TenantApplicationEnvironment;
use App\Models\User;
use App\Services\Management\ManagementContext;
use App\Services\Webhooks\WebhookDispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JsonException;
use stdClass;

class ManagementMetadataController extends Controller
{
    private const MAX_BYTES = 16384;

    public function __construct(private readonly ManagementContext $context) {}

    public function show(Request $request, string $userId): JsonResponse
    {
        $environment = $this->context->environment($request);
        $user = $this->context->user($environment, $userId);
        $metadata = $this->metadataQuery($environment, $user)->first();

        return $this->response($metadata);
    }

    public function update(Request $request, string $userId): JsonResponse
    {
        abort_unless($request->isJson(), 415, 'A JSON request body is required.');
        $raw = $request->getContent();
        abort_if(strlen($raw) > self::MAX_BYTES, 413, 'Metadata payload is too large.');

        try {
            $payload = json_decode($raw, false, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ValidationException::withMessages(['metadata' => 'Provide a valid JSON object.']);
        }

        if (! $payload instanceof stdClass) {
            throw ValidationException::withMessages(['metadata' => 'Provide a JSON object.']);
        }

        $fields = get_object_vars($payload);

        if ($fields === [] || array_diff(array_keys($fields), ['public', 'private']) !== []) {
            throw ValidationException::withMessages(['metadata' => 'Provide public and/or private metadata only.']);
        }

        foreach ($fields as $name => $value) {
            if (! $value instanceof stdClass) {
                throw ValidationException::withMessages([$name => 'Metadata must be a JSON object.']);
            }
        }

        $environment = $this->context->environment($request);
        $user = $this->context->user($environment, $userId);

        $metadata = DB::transaction(function () use ($environment, $user, $fields): ApplicationUserMetadata {
            $userQuery = User::query()->whereKey($user->getKey());
            $userQuery->lockForUpdate();
            $lockedUser = $userQuery->firstOrFail();
            $this->context->user($environment, (string) $lockedUser->getKey());
            $metadataQuery = $this->metadataQuery($environment, $lockedUser);
            $metadataQuery->lockForUpdate();
            $metadata = $metadataQuery->first();
            $public = $fields['public'] ?? $metadata->public_metadata ?? (object) [];
            $private = $fields['private'] ?? $metadata->private_metadata ?? (object) [];

            if (strlen(json_encode(['public' => $public, 'private' => $private], JSON_THROW_ON_ERROR)) > self::MAX_BYTES) {
                throw ValidationException::withMessages(['metadata' => 'Combined metadata cannot exceed 16 KiB.']);
            }

            $metadata ??= new ApplicationUserMetadata([
                'tenant_application_environment_id' => $environment->getKey(),
                'user_id' => $lockedUser->getKey(),
            ]);

            $metadata->public_metadata = $public;
            $metadata->private_metadata = $private;
            $metadata->save();

            return $metadata;
        });

        WebhookDispatcher::dispatchForEnvironment($environment, 'user.updated', $user);

        return $this->response($metadata);
    }

    /** @return Builder<ApplicationUserMetadata> */
    private function metadataQuery(TenantApplicationEnvironment $environment, User $user): Builder
    {
        return ApplicationUserMetadata::query()
            ->where('tenant_application_environment_id', $environment->getKey())
            ->where('user_id', $user->getKey());
    }

    private function response(?ApplicationUserMetadata $metadata): JsonResponse
    {
        return response()->json(['data' => [
            'public' => $metadata->public_metadata ?? (object) [],
            'private' => $metadata->private_metadata ?? (object) [],
        ]]);
    }
}
