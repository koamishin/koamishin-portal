<?php

declare(strict_types=1);

namespace App\Services\Webhooks;

use App\Jobs\DispatchWebhookJob;
use App\Models\User;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Carbon;

class WebhookDispatcher
{
    /**
     * Dispatch an event to all subscribed active webhook endpoints.
     *
     * @param  array<string, mixed>  $data
     */
    public static function dispatch(string $event, array $data): void
    {
        $endpoints = WebhookEndpoint::query()
            ->where('is_active', true)
            ->get();

        $payload = [
            'type' => $event,
            'timestamp' => time(),
            'data' => $data,
        ];

        foreach ($endpoints as $endpoint) {
            if (in_array($event, $endpoint->events, true) || in_array('*', $endpoint->events, true)) {
                DispatchWebhookJob::dispatch($endpoint, $event, $payload);
            }
        }
    }

    /**
     * Helper to format User model for webhook payload.
     *
     * @return array<string, mixed>
     */
    public static function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'username' => $user->username,
            'phone_number' => $user->phone_number,
            'phone_verified' => $user->hasVerifiedPhone(),
            'web3_wallet' => $user->web3_wallet,
            'metadata' => $user->metadata,
            'profile_photo_url' => $user->profile_photo_path ? url('storage/'.$user->profile_photo_path) : null,
            'email_verified' => $user->hasVerifiedEmail(),
            'banned' => $user->isBanned(),
            'ban_reason' => $user->ban_reason,
            'roles' => $user->getRoleNames()->toArray(),
            'permissions' => $user->getAllPermissions()->pluck('name')->toArray(),
            'created_at' => $user->created_at ? Carbon::parse($user->created_at)->toIso8601String() : null,
            'updated_at' => $user->updated_at ? Carbon::parse($user->updated_at)->toIso8601String() : null,
        ];
    }
}
