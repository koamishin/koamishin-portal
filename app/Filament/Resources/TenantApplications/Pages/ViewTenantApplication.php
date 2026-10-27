<?php

declare(strict_types=1);

namespace App\Filament\Resources\TenantApplications\Pages;

use App\Filament\Resources\TenantApplications\TenantApplicationResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewTenantApplication extends ViewRecord
{
    protected static string $resource = TenantApplicationResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            DeleteAction::make(),
        ];
    }
}
