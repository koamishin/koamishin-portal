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

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public WebhookEndpoint $endpoint,
        public string $event,
        public array $payload,
    ) {}

    public function handle(): void
    {
        $rawPayload = json_encode($this->payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $msgId = 'msg_'.Str::random(24);

        $toSign = "{$msgId}.{$timestamp}.{$rawPayload}";
        $signature = 'v1,'.base64_encode(hash_hmac('sha256', $toSign, $this->endpoint->secret, true));

        $delivery = WebhookDelivery::create([
            'webhook_endpoint_id' => $this->endpoint->id,
            'event' => $this->event,
            'payload' => $this->payload,
            'status' => 'pending',
            'attempts' => $this->attempts(),
        ]);

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'content-type' => 'application/json',
                    'svix-id' => $msgId,
                    'svix-timestamp' => $timestamp,
                    'svix-signature' => $signature,
                    'user-agent' => 'Koamishin-Webhook-Dispatcher/1.0',
                ])
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
