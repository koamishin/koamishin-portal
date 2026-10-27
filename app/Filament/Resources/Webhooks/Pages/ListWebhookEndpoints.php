<?php

declare(strict_types=1);

namespace App\Filament\Resources\Webhooks\Pages;

use App\Filament\Resources\Webhooks\WebhookEndpointResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWebhookEndpoints extends ListRecords
{
    protected static string $resource = WebhookEndpointResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
