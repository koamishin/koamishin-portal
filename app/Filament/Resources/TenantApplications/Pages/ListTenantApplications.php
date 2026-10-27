<?php

declare(strict_types=1);

namespace App\Filament\Resources\TenantApplications\Pages;

use App\Filament\Resources\TenantApplications\TenantApplicationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTenantApplications extends ListRecords
{
    protected static string $resource = TenantApplicationResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('New Application'),
        ];
    }
}
