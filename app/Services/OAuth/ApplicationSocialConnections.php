<?php

declare(strict_types=1);

namespace App\Services\OAuth;

use App\Enums\SocialLoginProvider;
use App\Models\TenantApplicationEnvironment;
use App\Settings\SocialLoginSettings;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

class ApplicationSocialConnections
{
    public function __construct(private readonly SocialLoginSettings $portalSettings) {}

    /** @return array{client_id: string, client_secret: string, redirect: string, scope: string}|null */
    public function credentials(SocialLoginProvider $provider, ?TenantApplicationEnvironment $environment): ?array
    {
        $providers = $environment?->getSetting('social_providers', []);
        $connection = is_array($providers) ? ($providers[$provider->value] ?? []) : [];
        $connection = is_array($connection) ? $connection : [];
        $mode = $connection['mode'] ?? 'inherit';

        if ($mode === 'disabled') {
            return null;
        }

        if ($mode === 'inherit') {
            if (! $this->portalSettings->isProviderEnabled($provider)) {
                return null;
            }

            $credentials = $this->portalSettings->resolveCredentials($provider);

            return $credentials === null ? null : [...$credentials, 'scope' => 'portal'];
        }

        if ($mode !== 'custom' || ! $environment instanceof TenantApplicationEnvironment
            || ! is_string($connection['client_id'] ?? null) || ! filled($connection['client_id'])
            || ! is_string($connection['client_secret'] ?? null) || ! filled($connection['client_secret'])) {
            return null;
        }

        try {
            $secret = Crypt::decryptString($connection['client_secret']);
        } catch (DecryptException) {
            return null;
        }

        return [
            'client_id' => $connection['client_id'],
            'client_secret' => $secret,
            'redirect' => rtrim((string) config('app.url'), '/').$provider->defaultRedirectPath(),
            'scope' => 'app-'.$environment->getKey().'-'.hash('sha256', $connection['client_id']),
        ];
    }
}
