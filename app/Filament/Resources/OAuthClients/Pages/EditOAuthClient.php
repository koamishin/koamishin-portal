<?php

declare(strict_types=1);

namespace App\Filament\Resources\OAuthClients\Pages;

use App\Filament\Resources\OAuthClients\OAuthClientResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditOAuthClient extends EditRecord
{
    protected static string $resource = OAuthClientResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
