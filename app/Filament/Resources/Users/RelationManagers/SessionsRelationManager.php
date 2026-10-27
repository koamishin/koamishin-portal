<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\RelationManagers;

use App\Models\Session;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SessionsRelationManager extends RelationManager
{
    protected static string $relationship = 'sessions';

    protected static ?string $title = 'Active Devices & Sessions';

    protected static string|BackedEnum|null $icon = 'heroicon-o-computer-desktop';

    #[\Override]
    public function isReadOnly(): bool
    {
        return false;
    }

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('ip_address')
            ->defaultSort('last_activity', 'desc')
            ->columns([
                TextColumn::make('device')
                    ->label('Device & Platform')
                    ->state(fn (Session $record): string => $record->getDeviceName())
                    ->icon('heroicon-o-device-phone-mobile')
                    ->weight('bold'),

                TextColumn::make('ip_address')
                    ->label('IP Address')
                    ->fontFamily('mono')
                    ->copyable()
                    ->searchable(),

                TextColumn::make('last_activity')
                    ->label('Last Active')
                    ->state(fn (Session $record): string => $record->last_active->diffForHumans())
                    ->sortable(),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->label('Terminate Session')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->successNotification(
                        Notification::make()
                            ->title('Session Terminated')
                            ->body('This device has been forced to log out.')
                            ->warning()
                    ),
            ]);
    }
}
