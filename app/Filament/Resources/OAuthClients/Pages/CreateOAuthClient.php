<?php

declare(strict_types=1);

namespace App\Filament\Resources\OAuthClients\Pages;

use App\Filament\Resources\OAuthClients\OAuthClientResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

class CreateOAuthClient extends CreateRecord
{
    protected static string $resource = OAuthClientResource::class;

    #[\Override]
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (empty($data['secret'])) {
            $data['secret'] = Str::random(40);
        }

        if (empty($data['grant_types'])) {
            $data['grant_types'] = ['authorization_code', 'refresh_token'];
        }

        $data['revoked'] = false;

        return $data;
    }
}
