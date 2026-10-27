<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\User;
use App\Services\Webhooks\WebhookDispatcher;

class UserObserver
{
    public function created(User $user): void
    {
        WebhookDispatcher::dispatch('user.created', WebhookDispatcher::userPayload($user));
    }

    public function updated(User $user): void
    {
        WebhookDispatcher::dispatch('user.updated', WebhookDispatcher::userPayload($user));
    }

    public function deleted(User $user): void
    {
        WebhookDispatcher::dispatch('user.deleted', [
            'id' => $user->id,
            'email' => $user->email,
        ]);
    }
}
