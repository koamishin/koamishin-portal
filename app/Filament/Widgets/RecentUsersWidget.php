<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class RecentUsersWidget extends BaseWidget
{
    protected static ?string $heading = 'Recent Registrations';

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(User::query()->latest())
            ->defaultPaginationPageOption(8)
            ->paginated(false)
            ->columns([
                TextColumn::make('name')
                    ->label('User')
                    ->weight('bold')
                    ->searchable(),

                TextColumn::make('email')
                    ->copyable()
                    ->searchable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (User $record): string => $record->isBanned() ? 'Banned' : 'Active')
                    ->color(fn (string $state): string => match ($state) {
                        'Active' => 'success',
                        'Banned' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('roles.name')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('socialAccounts.provider')
                    ->label('Social Provider')
                    ->badge()
                    ->color('info')
                    ->placeholder('Email/Password'),

                IconColumn::make('email_verified_at')
                    ->label('Verified')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle'),

                TextColumn::make('created_at')
                    ->label('Registered')
                    ->since(),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('Inspect')
                    ->icon('heroicon-o-eye')
                    ->url(fn (User $record): string => UserResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
