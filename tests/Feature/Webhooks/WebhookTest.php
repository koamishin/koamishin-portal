<?php

declare(strict_types=1);

use App\Jobs\DispatchWebhookJob;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

test('creating a user queues a webhook dispatch job for active endpoints', function (): void {
    Queue::fake();

    WebhookEndpoint::create([
        'name' => 'Internal Service',
        'url' => 'https://service.internal/webhook',
        'secret' => 'whsec_secret123',
        'events' => ['user.created'],
        'is_active' => true,
    ]);

    $user = User::factory()->create([
        'email' => 'newuser@koamishin.com',
    ]);

    Queue::assertPushed(DispatchWebhookJob::class, function (DispatchWebhookJob $job) use ($user): bool {
        return $job->event === 'user.created' && $job->payload['data']['email'] === $user->email;
    });
});

test('dispatch webhook job sends svix headers and logs delivery', function (): void {
    Http::fake([
        'https://service.internal/webhook' => Http::response(['received' => true], 200),
    ]);

    $endpoint = WebhookEndpoint::create([
        'name' => 'Internal Service',
        'url' => 'https://service.internal/webhook',
        'secret' => 'whsec_secret123',
        'events' => ['user.created'],
        'is_active' => true,
    ]);

    $job = new DispatchWebhookJob($endpoint, 'user.created', [
        'type' => 'user.created',
        'data' => ['id' => 1, 'email' => 'test@test.com'],
    ]);

    $job->handle();

    Http::assertSent(function ($request): bool {
        return $request->hasHeader('svix-id')
            && $request->hasHeader('svix-timestamp')
            && $request->hasHeader('svix-signature');
    });

    $delivery = WebhookDelivery::query()->where('webhook_endpoint_id', $endpoint->id)->first();
    expect($delivery)->not->toBeNull()
        ->status->toBe('delivered')
        ->response_status->toBe(200);
});
