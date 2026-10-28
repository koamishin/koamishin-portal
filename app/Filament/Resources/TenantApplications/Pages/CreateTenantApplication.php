<?php

declare(strict_types=1);

namespace App\Filament\Resources\TenantApplications\Pages;

use App\Filament\Resources\TenantApplications\TenantApplicationResource;
use App\Models\Tenant;
use App\Services\OAuth\ApplicationProvisioner;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateTenantApplication extends CreateRecord
{
    protected static string $resource = TenantApplicationResource::class;

    #[\Override]
    protected function handleRecordCreation(array $data): Model
    {
        $tenant = Filament::getTenant() ?? Tenant::query()->findOrFail($data['tenant_id'] ?? null);
        if (! $tenant instanceof Tenant) {
            abort(403);
        }

        return app(ApplicationProvisioner::class)->create($tenant, $data);
    }

    #[\Override]
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
