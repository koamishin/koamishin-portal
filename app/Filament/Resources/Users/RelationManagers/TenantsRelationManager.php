<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\RelationManagers;

use BackedEnum;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TenantsRelationManager extends RelationManager
{
    protected static string $relationship = 'tenants';

    protected static ?string $title = 'Assigned Organizations';

    protected static string|BackedEnum|null $icon = 'heroicon-o-building-office-2';

    #[\Override]
    public function isReadOnly(): bool
    {
        return false;
    }

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Organization')
                    ->weight('bold')
                    ->searchable(),

                TextColumn::make('slug')
                    ->label('Slug')
                    ->badge()
                    ->color('gray')
                    ->fontFamily('mono'),

                TextColumn::make('pivot.role')
                    ->label('Member Role')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'owner' => 'danger',
                        'admin' => 'warning',
                        default => 'success',
                    }),

                TextColumn::make('pivot.created_at')
                    ->label('Assigned')
                    ->dateTime(),
            ])
            ->headerActions([
                AttachAction::make()
                    ->label('Assign to Organization')
                    ->preloadRecordSelect()
                    ->form(fn (AttachAction $action): array => [
                        $action->getRecordSelect(),
                        Select::make('role')
                            ->label('Role in Organization')
                            ->options([
                                'member' => 'Member',
                                'admin' => 'Admin',
                                'owner' => 'Owner',
                            ])
                            ->default('member')
                            ->required(),
                    ]),
            ])
            ->recordActions([
                DetachAction::make()
                    ->label('Remove')
                    ->requiresConfirmation(),
            ]);
    }
}
