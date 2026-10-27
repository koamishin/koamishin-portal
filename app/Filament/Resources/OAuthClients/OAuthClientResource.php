<?php

declare(strict_types=1);

namespace App\Filament\Resources\OAuthClients;

use App\Filament\Resources\OAuthClients\Pages\CreateOAuthClient;
use App\Filament\Resources\OAuthClients\Pages\EditOAuthClient;
use App\Filament\Resources\OAuthClients\Pages\ListOAuthClients;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Laravel\Passport\Client;

class OAuthClientResource extends Resource
{
    protected static ?string $model = Client::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-key';

    protected static \UnitEnum|string|null $navigationGroup = 'Authentication & API';

    protected static ?string $navigationLabel = 'OAuth Applications';

    protected static ?string $modelLabel = 'OAuth Client';

    protected static ?string $pluralModelLabel = 'OAuth Clients';

    protected static ?int $navigationSort = 2;

    protected static bool $isScopedToTenant = false;

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Client Information')
                    ->components([
                        TextInput::make('name')
                            ->label('Application Name')
                            ->placeholder('e.g. Koamishin CRM, Inventory App')
                            ->required()
                            ->maxLength(255),

                        TextInput::make('id')
                            ->label('Client ID')
                            ->disabled()
                            ->dehydrated(false)
                            ->visible(fn (string $context): bool => $context === 'edit')
                            ->copyable(),

                        TextInput::make('secret')
                            ->label('Client Secret')
                            ->helperText('Leave empty on creation to generate automatically. For public SPAs, leave secret blank.')
                            ->password()
                            ->revealable()
                            ->copyable()
                            ->dehydrated(fn ($state): bool => filled($state))
                            ->maxLength(255),

                        TagsInput::make('redirect_uris')
                            ->label('Redirect URIs (Callbacks)')
                            ->helperText('Allowed callback URLs where users are sent after logging in. (e.g. https://crm.internal/auth/callback)')
                            ->placeholder('Add callback URL and press Enter')
                            ->required(),

                        CheckboxList::make('grant_types')
                            ->label('Allowed Grants')
                            ->options([
                                'authorization_code' => 'Authorization Code (PKCE)',
                                'refresh_token' => 'Refresh Token',
                                'client_credentials' => 'Client Credentials (Machine-to-Machine)',
                                'personal_access' => 'Personal Access Token',
                            ])
                            ->default(['authorization_code', 'refresh_token'])
                            ->required(),

                        Toggle::make('revoked')
                            ->label('Revoke Access')
                            ->helperText('Turn on to immediately block this application from authenticating users.')
                            ->default(false),
                    ])->columns(1),
            ]);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Application')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('id')
                    ->label('Client ID')
                    ->copyable()
                    ->fontFamily('mono')
                    ->searchable(),

                TextColumn::make('redirect_uris')
                    ->label('Redirect URIs')
                    ->formatStateUsing(fn ($state): string => is_array($state) ? implode(', ', $state) : (string) $state)
                    ->limit(40),

                IconColumn::make('revoked')
                    ->label('Status')
                    ->boolean()
                    ->trueIcon('heroicon-o-x-circle')
                    ->falseIcon('heroicon-o-check-circle')
                    ->trueColor('danger')
                    ->falseColor('success')
                    ->tooltip(fn (Client $record): string => (bool) $record->getAttribute('revoked') ? 'Revoked' : 'Active'),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('revoked')
                    ->label('Status')
                    ->placeholder('All clients')
                    ->trueLabel('Revoked')
                    ->falseLabel('Active'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListOAuthClients::route('/'),
            'create' => CreateOAuthClient::route('/create'),
            'edit' => EditOAuthClient::route('/{record}/edit'),
        ];
    }
}
