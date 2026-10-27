<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int|null $user_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string $payload
 * @property int $last_activity
 */
class Session extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'user_id',
        'ip_address',
        'user_agent',
        'payload',
        'last_activity',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getLastActiveAttribute(): Carbon
    {
        return Carbon::createFromTimestamp($this->last_activity);
    }

    /**
     * Parse human-friendly device name from User Agent string.
     */
    public function getDeviceName(): string
    {
        $agent = (string) $this->user_agent;

        if (empty($agent)) {
            return 'Unknown Device';
        }

        $platform = 'Desktop';
        if (str_contains($agent, 'iPhone') || str_contains($agent, 'iPad')) {
            $platform = 'Apple iOS';
        } elseif (str_contains($agent, 'Android')) {
            $platform = 'Android';
        } elseif (str_contains($agent, 'Macintosh') || str_contains($agent, 'Mac OS X')) {
            $platform = 'macOS';
        } elseif (str_contains($agent, 'Windows')) {
            $platform = 'Windows';
        } elseif (str_contains($agent, 'Linux')) {
            $platform = 'Linux';
        }

        $browser = 'Browser';
        if (str_contains($agent, 'Firefox')) {
            $browser = 'Firefox';
        } elseif (str_contains($agent, 'Edg')) {
            $browser = 'Edge';
        } elseif (str_contains($agent, 'Chrome')) {
            $browser = 'Chrome';
        } elseif (str_contains($agent, 'Safari')) {
            $browser = 'Safari';
        }

        return "{$browser} on {$platform}";
    }
}
