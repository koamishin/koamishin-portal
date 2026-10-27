<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Laravel\Passport\Client;

/**
 * @property int $id
 * @property int $tenant_application_id
 * @property string $environment
 * @property string|null $oauth_client_id
 * @property array<string> $redirect_uris
 * @property array<string, mixed>|null $settings
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read TenantApplication $application
 * @property-read Client|null $oauthClient
 */
class TenantApplicationEnvironment extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_application_id',
        'environment',
        'oauth_client_id',
        'redirect_uris',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'redirect_uris' => 'array',
            'settings' => 'array',
        ];
    }

    /**
     * @return BelongsTo<TenantApplication, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(TenantApplication::class, 'tenant_application_id');
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function oauthClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'oauth_client_id');
    }

    public function isProduction(): bool
    {
        return $this->environment === 'production';
    }

    public function isDevelopment(): bool
    {
        return $this->environment === 'development';
    }

    public function getSetting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }
}
