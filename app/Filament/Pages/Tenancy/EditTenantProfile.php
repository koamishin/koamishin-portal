<?php

declare(strict_types=1);

namespace App\Filament\Pages\Tenancy;

use App\Models\Tenant;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\EditTenantProfile as BaseEditTenantProfile;
use Filament\Schemas\Schema;

class EditTenantProfile extends BaseEditTenantProfile
{
    public static function getLabel(): string
    {
        return 'Organization Settings';
    }

    #[\Override]
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Organization Name')
                    ->required()
                    ->maxLength(255),

                TextInput::make('slug')
                    ->label('Organization Slug')
                    ->required()
                    ->unique(Tenant::class, 'slug', ignoreRecord: true)
                    ->maxLength(100),

                TextInput::make('domain')
                    ->label('Custom Domain')
                    ->placeholder('e.g. auth.acme.com')
                    ->unique(Tenant::class, 'domain', ignoreRecord: true)
                    ->maxLength(255),
            ]);
    }
}
