<?php

declare(strict_types=1);

namespace App\Services\Webhooks;

use App\Jobs\DispatchWebhookJob;
use App\Models\User;
use App\Models\WebhookEndpoint;

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
            'profile_photo_url' => $user->profile_photo_path ? url('storage/'.$user->profile_photo_path) : null,
            'email_verified' => $user->hasVerifiedEmail(),
            'banned' => $user->isBanned(),
            'ban_reason' => $user->ban_reason,
            'roles' => $user->getRoleNames()->toArray(),
            'permissions' => $user->getAllPermissions()->pluck('name')->toArray(),
            'created_at' => $user->created_at instanceof \DateTimeInterface ? $user->created_at->format(\DateTimeInterface::ATOM) : (is_string($user->created_at) ? $user->created_at : null),
            'updated_at' => $user->updated_at instanceof \DateTimeInterface ? $user->updated_at->format(\DateTimeInterface::ATOM) : (is_string($user->updated_at) ? $user->updated_at : null),
        ];
    }
}
