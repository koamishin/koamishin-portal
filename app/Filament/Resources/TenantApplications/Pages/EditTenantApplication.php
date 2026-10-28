<?php

declare(strict_types=1);

namespace App\Filament\Resources\TenantApplications\Pages;

use App\Filament\Resources\TenantApplications\TenantApplicationResource;
use App\Models\TenantApplication;
use App\Services\OAuth\ApplicationProvisioner;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EditTenantApplication extends EditRecord
{
    protected static string $resource = TenantApplicationResource::class;

    #[\Override]
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var TenantApplication $record */
        app(ApplicationProvisioner::class)->validateRedirects($data['environment'], $data['redirect_uris']);

        return DB::transaction(function () use ($record, $data): Model {
            $configuration = $record->environments()->where('environment', $data['environment'])->firstOrFail();
            $client = $configuration->oauthClient;
            abort_unless($client && ! $client->revoked, 409, 'The selected environment has no active OAuth client.');

            $client->forceFill(['redirect_uris' => $data['redirect_uris']])->save();
            $configuration->update(['redirect_uris' => $data['redirect_uris']]);
            $data['oauth_client_id'] = (string) $client->getKey();
            $record->update($data);

            return $record;
        });
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
