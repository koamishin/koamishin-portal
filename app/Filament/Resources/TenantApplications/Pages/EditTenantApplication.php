<?php

declare(strict_types=1);

namespace App\Filament\Resources\TenantApplications\Pages;

use App\Filament\Resources\TenantApplications\TenantApplicationResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditTenantApplication extends EditRecord
{
    protected static string $resource = TenantApplicationResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
