<?php

declare(strict_types=1);

namespace App\Filament\Resources\SocialAccounts;

use App\Filament\Resources\SocialAccounts\Pages\ListSocialAccounts;
use App\Filament\Resources\SocialAccounts\Pages\ViewSocialAccount;
use App\Filament\Resources\Users\UserResource;
use App\Models\SocialAccount;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SocialAccountResource extends Resource
{
    protected static ?string $model = SocialAccount::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-link';

    protected static \UnitEnum|string|null $navigationGroup = 'Authentication & API';

    protected static ?string $navigationLabel = 'Social Connections (SSO)';

    protected static ?string $modelLabel = 'Social Connection';

    protected static ?string $pluralModelLabel = 'Social Connections';

    protected static ?int $navigationSort = 4;

    #[\Override]
    public static function canCreate(): bool
    {
        return false;
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Connection Details')
                    ->components([
                        TextEntry::make('user.name')
                            ->label('Connected User')
                            ->url(fn (SocialAccount $record): string => UserResource::getUrl('view', ['record' => $record->user_id]))
                            ->weight('bold'),

                        TextEntry::make('user.email')
                            ->label('Account Email')
                            ->copyable(),

                        TextEntry::make('provider')
                            ->label('Identity Provider')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'github' => 'gray',
                                'google' => 'danger',
                                'facebook' => 'info',
                                default => 'primary',
                            }),

                        TextEntry::make('provider_id')
                            ->label('Provider UID')
                            ->fontFamily('mono')
                            ->copyable(),

                        TextEntry::make('nickname')
                            ->label('Username / Nickname')
                            ->placeholder('None'),

                        ImageEntry::make('avatar_url')
                            ->label('Provider Avatar')
                            ->circular(),

                        TextEntry::make('created_at')
                            ->label('First Linked')
                            ->dateTime(),

                        TextEntry::make('updated_at')
                            ->label('Last Synced')
                            ->dateTime(),
                    ])->columns(2),
            ]);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('avatar_url')
                    ->label('')
                    ->circular()
                    ->defaultImageUrl(fn (SocialAccount $record): string => 'https://ui-avatars.com/api/?name='.urlencode($record->user->name)),

                TextColumn::make('user.name')
                    ->label('User')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->url(fn (SocialAccount $record): string => UserResource::getUrl('view', ['record' => $record->user_id])),

                TextColumn::make('user.email')
                    ->label('Email')
                    ->searchable()
                    ->copyable(),

                TextColumn::make('provider')
                    ->label('Provider')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'github' => 'gray',
                        'google' => 'danger',
                        'facebook' => 'info',
                        default => 'primary',
                    })
                    ->sortable(),

                TextColumn::make('nickname')
                    ->label('Handle')
                    ->placeholder('—')
                    ->searchable(),

                TextColumn::make('provider_id')
                    ->label('Provider ID')
                    ->fontFamily('mono')
                    ->limit(15)
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Linked At')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('provider')
                    ->options([
                        'github' => 'GitHub',
                        'google' => 'Google',
                        'facebook' => 'Facebook',
                    ]),
            ])
            ->recordActions([
                Action::make('viewUser')
                    ->label('View User')
                    ->icon('heroicon-o-user')
                    ->url(fn (SocialAccount $record): string => UserResource::getUrl('view', ['record' => $record->user_id])),
                DeleteAction::make()
                    ->label('Unlink')
                    ->requiresConfirmation(),
            ]);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListSocialAccounts::route('/'),
            'view' => ViewSocialAccount::route('/{record}'),
        ];
    }
}
