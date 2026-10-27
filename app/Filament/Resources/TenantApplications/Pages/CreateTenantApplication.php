<?php

declare(strict_types=1);

namespace App\Filament\Resources\TenantApplications\Pages;

use App\Filament\Resources\TenantApplications\TenantApplicationResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;
use Laravel\Passport\Client;

class CreateTenantApplication extends CreateRecord
{
    protected static string $resource = TenantApplicationResource::class;

    #[\Override]
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (empty($data['oauth_client_id'])) {
            $secret = Str::random(40);

            $client = new Client;
            $client->name = $data['name'];
            $client->secret = $secret;
            $client->redirect_uris = $data['redirect_uris'] ?? [];
            $client->grant_types = ['authorization_code', 'refresh_token'];
            $client->revoked = false;
            $client->save();

            $data['oauth_client_id'] = $client->id;

            session()->flash('new_app_secret', $secret);
        }

        return $data;
    }

    #[\Override]
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
