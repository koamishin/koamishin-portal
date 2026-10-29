<?php

declare(strict_types=1);

namespace App\Filament\Resources\TenantApplications\Pages;

use App\Enums\SocialLoginProvider;
use App\Filament\Resources\TenantApplications\TenantApplicationResource;
use App\Models\TenantApplication;
use App\Services\OAuth\ApplicationProvisioner;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EditTenantApplication extends EditRecord
{
    protected static string $resource = TenantApplicationResource::class;

    #[\Override]
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();
        if ($record instanceof TenantApplication) {
            $data = [...$data, ...TenantApplicationResource::environmentFormValues($record, $record->environment)];
        }

        return $data;
    }

    #[\Override]
    protected function mutateFormDataBeforeSave(array $data): array
    {
        foreach (SocialLoginProvider::cases() as $provider) {
            $key = 'social_'.$provider->value.'_client_secret';
            if (is_string($data[$key] ?? null) && $data[$key] !== '') {
                $data[$key] = Crypt::encryptString($data[$key]);
            }
        }

        return $data;
    }

    #[\Override]
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var TenantApplication $record */
        app(ApplicationProvisioner::class)->validateRedirects($data['environment'], $data['redirect_uris']);
        if (! is_bool($data['allow_signups'] ?? null)) {
            throw ValidationException::withMessages(['allow_signups' => 'Choose whether self-service signup is allowed.']);
        }
        $verificationPolicy = $data['email_verification_policy'] ?? null;
        if (! in_array($verificationPolicy, ['inherit', 'required', 'disabled'], true)) {
            throw ValidationException::withMessages(['email_verification_policy' => 'Choose a valid email verification policy.']);
        }
        foreach (['passkeys_enabled', 'sso_enabled'] as $feature) {
            if (! is_bool($data[$feature] ?? null)) {
                throw ValidationException::withMessages([$feature => 'Choose whether this feature is enabled.']);
            }
        }

        return DB::transaction(function () use ($record, $data, $verificationPolicy): Model {
            $configuration = $record->environments()->where('environment', $data['environment'])->firstOrFail();
            $client = $configuration->oauthClient;
            abort_unless($client && ! $client->getAttribute('revoked'), 409, 'The selected environment has no active OAuth client.');

            $client->forceFill(['redirect_uris' => $data['redirect_uris']])->save();
            $settings = $configuration->settings ?? [];
            $settings['allow_signups'] = $data['allow_signups'];
            if ($verificationPolicy === 'inherit') {
                unset($settings['email_verification_required']);
            } else {
                $settings['email_verification_required'] = $verificationPolicy === 'required';
            }
            unset($data['email_verification_policy']);
            foreach ([
                'passkeys_enabled' => true,
                'sso_enabled' => false,
                'login_title' => null,
                'login_description' => null,
                'login_logo_url' => null,
                'auth_layout' => 'inherit',
            ] as $key => $default) {
                $value = $data[$key] ?? $default;
                if ($value === $default || $value === '') {
                    unset($settings[$key]);
                } else {
                    $settings[$key] = $value;
                }
                unset($data[$key]);
            }

            $connections = is_array($settings['social_providers'] ?? null) ? $settings['social_providers'] : [];
            foreach (SocialLoginProvider::cases() as $provider) {
                $prefix = 'social_'.$provider->value;
                $mode = $data[$prefix.'_mode'] ?? 'inherit';
                if (! in_array($mode, ['inherit', 'disabled', 'custom'], true)) {
                    throw ValidationException::withMessages([$prefix.'_mode' => 'Select a valid social connection.']);
                }

                if ($mode === 'inherit') {
                    unset($connections[$provider->value]);
                } elseif ($mode === 'disabled') {
                    $connections[$provider->value] = ['mode' => 'disabled'];
                } else {
                    $old = $connections[$provider->value] ?? [];
                    $clientId = $data[$prefix.'_client_id'] ?? null;
                    $secret = $data[$prefix.'_client_secret'] ?? null;
                    if (! is_string($clientId) || trim($clientId) === '') {
                        throw ValidationException::withMessages([$prefix.'_client_id' => 'Enter the provider client ID.']);
                    }
                    if (! is_string($secret) || $secret === '') {
                        $secret = is_array($old) && ($old['client_id'] ?? null) === $clientId ? ($old['client_secret'] ?? null) : null;
                    }
                    if (! is_string($secret) || $secret === '') {
                        throw ValidationException::withMessages([$prefix.'_client_secret' => 'Enter the provider client secret.']);
                    }
                    $connections[$provider->value] = ['mode' => 'custom', 'client_id' => $clientId, 'client_secret' => $secret];
                }
                unset($data[$prefix.'_mode'], $data[$prefix.'_client_id'], $data[$prefix.'_client_secret']);
            }
            if ($connections === []) {
                unset($settings['social_providers']);
            } else {
                $settings['social_providers'] = $connections;
            }
            $configuration->update(['redirect_uris' => $data['redirect_uris'], 'settings' => $settings]);
            unset($data['allow_signups']);
            $data['oauth_client_id'] = (string) $client->getKey();
            $record->update($data);

            return $record;
        });
    }

    protected function afterSave(): void
    {
        foreach (SocialLoginProvider::cases() as $provider) {
            $this->data['social_'.$provider->value.'_client_secret'] = null;
        }
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
