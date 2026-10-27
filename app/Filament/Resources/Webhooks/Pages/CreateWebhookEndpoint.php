<?php

declare(strict_types=1);

namespace App\Filament\Resources\Webhooks\Pages;

use App\Filament\Resources\Webhooks\WebhookEndpointResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

class CreateWebhookEndpoint extends CreateRecord
{
    protected static string $resource = WebhookEndpointResource::class;

    #[\Override]
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (empty($data['secret'])) {
            $data['secret'] = 'whsec_'.Str::random(32);
        }

        return $data;
    }
}
