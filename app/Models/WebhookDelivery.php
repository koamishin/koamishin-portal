<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $webhook_endpoint_id
 * @property string $event
 * @property array<string, mixed> $payload
 * @property int|null $response_status
 * @property string|null $response_body
 * @property string $status
 * @property int $attempts
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class WebhookDelivery extends Model
{
    use HasFactory;

    protected $fillable = [
        'webhook_endpoint_id',
        'event',
        'payload',
        'response_status',
        'response_body',
        'status',
        'attempts',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'response_status' => 'integer',
            'attempts' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<WebhookEndpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }
}
