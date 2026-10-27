<?php

declare(strict_types=1);

namespace App\Filament\Resources\Webhooks\Pages;

use App\Filament\Resources\Webhooks\WebhookEndpointResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditWebhookEndpoint extends EditRecord
{
    protected static string $resource = WebhookEndpointResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
