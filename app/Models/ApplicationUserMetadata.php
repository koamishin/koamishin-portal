<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ApplicationUserMetadataFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use stdClass;

/**
 * @property stdClass|null $public_metadata
 * @property stdClass|null $private_metadata
 */
class ApplicationUserMetadata extends Model
{
    /** @use HasFactory<ApplicationUserMetadataFactory> */
    use HasFactory;

    protected $table = 'application_user_metadata';

    protected $fillable = [
        'tenant_application_environment_id',
        'user_id',
        'public_metadata',
        'private_metadata',
    ];

    protected function casts(): array
    {
        return [
            'public_metadata' => 'object',
            'private_metadata' => 'object',
        ];
    }

    /** @return BelongsTo<TenantApplicationEnvironment, $this> */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(TenantApplicationEnvironment::class, 'tenant_application_environment_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
