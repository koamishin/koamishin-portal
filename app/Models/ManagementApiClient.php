<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ManagementApiClientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Passport\Client;

/**
 * @property bool $is_active
 * @property array<string> $allowed_scopes
 * @property-read TenantApplicationEnvironment|null $environment
 * @property-read Client|null $oauthClient
 */
class ManagementApiClient extends Model
{
    /** @use HasFactory<ManagementApiClientFactory> */
    use HasFactory;

    public const SCOPES = ['users:read', 'metadata:read', 'metadata:write', 'sessions:read', 'sessions:revoke', 'memberships:write', 'organization:read'];

    protected $fillable = [
        'tenant_application_environment_id',
        'oauth_client_id',
        'allowed_scopes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'allowed_scopes' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<TenantApplicationEnvironment, $this> */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(TenantApplicationEnvironment::class, 'tenant_application_environment_id');
    }

    /** @return BelongsTo<Client, $this> */
    public function oauthClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'oauth_client_id');
    }
}
