<?php

declare(strict_types=1);

namespace App\Filament\Resources\SocialAccounts\Pages;

use App\Filament\Resources\SocialAccounts\SocialAccountResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\ViewRecord;

class ViewSocialAccount extends ViewRecord
{
    protected static string $resource = SocialAccountResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->label('Unlink Account'),
        ];
    }
}
