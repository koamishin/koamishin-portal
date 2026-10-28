<?php

declare(strict_types=1);

use App\Jobs\DispatchWebhookJob;
use App\Models\ApplicationUserMetadata;
use App\Models\Tenant;
use App\Models\TenantApplication;
use App\Models\TenantApplicationEnvironment;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Webhooks\WebhookDispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\travelTo;

function webhookEnvironment(string $slug, string $environment = 'development'): TenantApplicationEnvironment
{
    $tenant = Tenant::query()->create(['name' => $slug, 'slug' => $slug, 'is_active' => true]);
    $application = TenantApplication::query()->create([
        'tenant_id' => $tenant->getKey(),
        'name' => $slug.' app',
        'framework' => 'laravel',
        'environment' => $environment,
        'redirect_uris' => [],
    ]);

    return $application->environments()->create(['environment' => $environment, 'redirect_uris' => [], 'settings' => []]);
}

test('webhooks only reach the enrolled application environment and exclude global and private metadata', function (): void {
    Queue::fake();
    $environment = webhookEnvironment('alpha');
    $other = webhookEnvironment('beta');

    $endpoint = WebhookEndpoint::query()->create([
        'name' => 'Internal Service',
        'url' => 'https://service.internal/webhook',
        'secret' => 'whsec_secret123',
        'tenant_application_environment_id' => $environment->getKey(),
        'events' => ['user.created'],
        'is_active' => true,
    ]);
    WebhookEndpoint::query()->create([
        'name' => 'Other Service',
        'url' => 'https://other.internal/webhook',
        'secret' => 'whsec_secret123',
        'tenant_application_environment_id' => $other->getKey(),
        'events' => ['user.created'],
        'is_active' => true,
    ]);
    WebhookEndpoint::query()->create([
        'name' => 'Unassigned Service',
        'url' => 'https://unassigned.internal/webhook',
        'secret' => 'whsec_secret123',
        'events' => ['user.created'],
        'is_active' => true,
    ]);

    $user = User::factory()->create([
        'email' => 'newuser@koamishin.com',
        'metadata' => ['global_secret' => 'not shared'],
    ]);
    Queue::assertNothingPushed();
    $environment->application->tenant->users()->attach($user, ['role' => 'member']);
    $environment->application->users()->attach($user, ['role' => 'member', 'environment' => 'development']);
    ApplicationUserMetadata::query()->create([
        'tenant_application_environment_id' => $environment->getKey(),
        'user_id' => $user->getKey(),
        'public_metadata' => (object) ['theme' => 'dark'],
        'private_metadata' => (object) ['secret' => 'not shared'],
    ]);

    WebhookDispatcher::dispatchForEnvironment($environment, 'user.created', $user);
    WebhookDispatcher::dispatchForEnvironment($other, 'user.created', $user);

    Queue::assertPushed(DispatchWebhookJob::class, 1);
    Queue::assertPushed(DispatchWebhookJob::class, fn (DispatchWebhookJob $job): bool => $job->event === 'user.created'
        && $job->endpoint->is($endpoint)
        && $job->payload['data']['email'] === $user->email
        && $job->payload['data']['public_metadata']->theme === 'dark'
        && ! array_key_exists('metadata', $job->payload['data'])
        && ! array_key_exists('private_metadata', $job->payload['data'])
        && ! array_key_exists('roles', $job->payload['data'])
        && $job->afterCommit === true);
});

test('user updates and deletions notify only the enrolled environment', function (): void {
    Queue::fake();
    $environment = webhookEnvironment('updates');
    $other = webhookEnvironment('unrelated');
    $endpoint = WebhookEndpoint::query()->create([
        'name' => 'Updates', 'url' => 'https://service.internal/webhook', 'secret' => 'secret',
        'tenant_application_environment_id' => $environment->getKey(),
        'events' => ['user.updated', 'user.deleted'], 'is_active' => true,
    ]);
    WebhookEndpoint::query()->create([
        'name' => 'Other', 'url' => 'https://other.internal/webhook', 'secret' => 'secret',
        'tenant_application_environment_id' => $other->getKey(),
        'events' => ['user.updated', 'user.deleted'], 'is_active' => true,
    ]);
    $user = User::factory()->create();
    $environment->application->tenant->users()->attach($user, ['role' => 'member']);
    $environment->application->users()->attach($user, ['role' => 'member', 'environment' => 'development']);

    $user->update(['name' => 'Updated']);
    $user->delete();

    Queue::assertPushed(DispatchWebhookJob::class, 2);
    Queue::assertPushed(DispatchWebhookJob::class, fn (DispatchWebhookJob $job): bool => $job->endpoint->is($endpoint) && $job->event === 'user.updated' && $job->payload['data']['name'] === 'Updated');
    Queue::assertPushed(DispatchWebhookJob::class, fn (DispatchWebhookJob $job): bool => $job->endpoint->is($endpoint) && $job->event === 'user.deleted' && $job->payload['data'] === ['id' => (string) $user->id]);
});

test('dispatch webhook job sends svix headers and logs delivery', function (): void {
    Http::fake([
        'https://service.internal/webhook' => Http::response(['received' => true], 200),
    ]);

    $endpoint = WebhookEndpoint::query()->create([
        'name' => 'Internal Service',
        'url' => 'https://service.internal/webhook',
        'secret' => 'whsec_secret123',
        'tenant_application_environment_id' => webhookEnvironment('delivery')->getKey(),
        'events' => ['user.created'],
        'is_active' => true,
    ]);

    $job = new DispatchWebhookJob($endpoint, 'user.created', [
        'type' => 'user.created',
        'data' => ['id' => 1, 'email' => 'test@test.com'],
    ]);

    $job->handle();

    Http::assertSent(fn ($request): bool => $request->hasHeader('svix-id')
        && $request->hasHeader('svix-timestamp')
        && $request->hasHeader('svix-signature'));

    $delivery = WebhookDelivery::query()->where('webhook_endpoint_id', $endpoint->id)->first();
    expect($delivery)->not->toBeNull()
        ->status->toBe('delivered')
        ->response_status->toBe(200);
});

test('a queued event is not sent after its endpoint is reassigned or disabled', function (): void {
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response([], 200)]);
    $environment = webhookEnvironment('original');
    $other = webhookEnvironment('reassigned');
    $endpoint = WebhookEndpoint::query()->create([
        'name' => 'Service', 'url' => 'https://service.internal/webhook', 'secret' => 'secret',
        'tenant_application_environment_id' => $environment->getKey(),
        'events' => ['user.updated'], 'is_active' => true,
    ]);
    $reassigned = new DispatchWebhookJob($endpoint, 'user.updated', ['data' => ['id' => '1']]);
    $disabled = new DispatchWebhookJob($endpoint, 'user.updated', ['data' => ['id' => '1']]);

    $endpoint->update(['tenant_application_environment_id' => $other->getKey()]);
    $reassigned->handle();
    $endpoint->update(['tenant_application_environment_id' => $environment->getKey(), 'is_active' => false]);
    $disabled->handle();

    Http::assertNothingSent();
    expect(WebhookDelivery::query()->count())->toBe(0);
});

test('webhook retries preserve the message id across serialized attempts', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'https://service.internal/webhook' => Http::sequence()
            ->push([], 500)
            ->push([], 200),
    ]);

    $endpoint = WebhookEndpoint::query()->create([
        'name' => 'Internal Service',
        'url' => 'https://service.internal/webhook',
        'secret' => 'whsec_secret123',
        'tenant_application_environment_id' => webhookEnvironment('retry')->getKey(),
        'events' => ['user.updated'],
        'is_active' => true,
    ]);
    $queuedPayload = serialize(new DispatchWebhookJob($endpoint, 'user.updated', ['type' => 'user.updated']));

    unserialize($queuedPayload)->handle();
    unserialize($queuedPayload)->handle();

    $requests = Http::recorded();
    expect($requests)->toHaveCount(2)
        ->and($requests[0][0]->header('svix-id'))->toBe($requests[1][0]->header('svix-id'));
    expect(WebhookDelivery::query()->where('webhook_endpoint_id', $endpoint->id)->pluck('message_id')->unique())->toHaveCount(1);
});

test('standard webhook signatures use the decoded secret', function (): void {
    travelTo(Carbon::createFromTimestampUTC(1704067200));
    Http::preventStrayRequests();
    Http::fake(['https://service.internal/webhook' => Http::response([], 200)]);

    $endpoint = WebhookEndpoint::query()->create([
        'name' => 'Standard Service',
        'url' => 'https://service.internal/webhook',
        'secret' => 'whsec_AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        'tenant_application_environment_id' => webhookEnvironment('standard')->getKey(),
        'signature_format' => 'standard',
        'events' => ['user.updated'],
        'is_active' => true,
    ]);
    $job = new DispatchWebhookJob($endpoint, 'user.updated', ['type' => 'user.updated', 'data' => ['id' => 1]]);
    $job->messageId = 'msg_test';

    $job->handle();

    Http::assertSent(fn ($request): bool => $request->hasHeader('svix-id', 'msg_test')
        && $request->hasHeader('svix-timestamp', '1704067200')
        && $request->hasHeader('svix-signature', 'v1,kJubUpa7OY1UCYWr4LYfuGjMy4ykhkg50ZTMm2zckMw=')
        && $request->hasHeader('webhook-id', 'msg_test')
        && $request->hasHeader('webhook-timestamp', '1704067200')
        && $request->hasHeader('webhook-signature', 'v1,kJubUpa7OY1UCYWr4LYfuGjMy4ykhkg50ZTMm2zckMw='));
    assertDatabaseHas('webhook_deliveries', ['webhook_endpoint_id' => $endpoint->id, 'message_id' => 'msg_test', 'status' => 'delivered']);
});

test('standard webhooks reject malformed signing secrets', function (): void {
    Http::preventStrayRequests();
    $endpoint = WebhookEndpoint::query()->create([
        'name' => 'Standard Service',
        'url' => 'https://service.internal/webhook',
        'secret' => 'not-a-standard-secret',
        'tenant_application_environment_id' => webhookEnvironment('malformed')->getKey(),
        'signature_format' => 'standard',
        'events' => ['user.updated'],
        'is_active' => true,
    ]);

    expect(fn () => (new DispatchWebhookJob($endpoint, 'user.updated', []))->handle())
        ->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
});
