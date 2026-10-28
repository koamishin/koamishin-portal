<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class DispatchWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 60, 300];

    public ?string $messageId = null;

    public ?int $expectedEnvironmentId = null;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public WebhookEndpoint $endpoint,
        public string $event,
        public array $payload,
    ) {
        $this->messageId = 'msg_'.Str::random(24);
        $this->expectedEnvironmentId = $endpoint->tenant_application_environment_id === null
            ? null
            : (int) $endpoint->tenant_application_environment_id;
    }

    public function handle(): void
    {
        $endpoint = WebhookEndpoint::query()->find($this->endpoint->getKey());

        if (! $endpoint || ! $endpoint->is_active) {
            return;
        }

        if ($this->event !== 'test.ping' && ($this->expectedEnvironmentId === null
            || $endpoint->tenant_application_environment_id === null
            || (int) $endpoint->tenant_application_environment_id !== $this->expectedEnvironmentId)) {
            return;
        }

        $this->endpoint = $endpoint;
        $rawPayload = json_encode($this->payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;
        $msgId = $this->messageId ??= 'msg_'.Str::random(24);

        $secret = $this->endpoint->secret;

        if (trim($secret) === '') {
            throw new \InvalidArgumentException('Webhook signing secret must be configured.');
        }

        $format = $this->endpoint->signature_format ?? 'legacy';
        if ($format === 'standard') {
            $decodedSecret = str_starts_with($secret, 'whsec_')
                ? base64_decode(substr($secret, 6), true)
                : false;
            if ($decodedSecret === false || strlen($decodedSecret) < 16) {
                throw new \InvalidArgumentException('Standard webhook secrets must use the whsec_ base64 format and contain at least 16 bytes.');
            }
            $secret = $decodedSecret;
        } elseif ($format !== 'legacy') {
            throw new \InvalidArgumentException('Unknown webhook signature format.');
        }

        $toSign = "{$msgId}.{$timestamp}.{$rawPayload}";
        $signature = 'v1,'.base64_encode(hash_hmac('sha256', $toSign, $secret, true));
        $headers = [
            'content-type' => 'application/json',
            'svix-id' => $msgId,
            'svix-timestamp' => $timestamp,
            'svix-signature' => $signature,
            'user-agent' => 'Koamishin-Webhook-Dispatcher/1.0',
        ];

        if ($format === 'standard') {
            $headers += [
                'webhook-id' => $msgId,
                'webhook-timestamp' => $timestamp,
                'webhook-signature' => $signature,
            ];
        }

        /** @var WebhookDelivery $delivery */
        $delivery = WebhookDelivery::query()->create([
            'webhook_endpoint_id' => $this->endpoint->id,
            'message_id' => $msgId,
            'event' => $this->event,
            'payload' => $this->payload,
            'status' => 'pending',
            'attempts' => $this->attempts(),
        ]);

        try {
            $response = Http::timeout(10)
                ->withHeaders($headers)
                ->withBody($rawPayload, 'application/json')
                ->post($this->endpoint->url);

            $delivery->update([
                'response_status' => $response->status(),
                'response_body' => Str::limit($response->body(), 1000),
                'status' => $response->successful() ? 'delivered' : 'failed',
                'attempts' => $this->attempts(),
            ]);

            if ($response->failed()) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 60);
            }
        } catch (\Throwable $e) {
            $delivery->update([
                'response_status' => 0,
                'response_body' => $e->getMessage(),
                'status' => 'failed',
                'attempts' => $this->attempts(),
            ]);

            throw $e;
        }
    }
}
