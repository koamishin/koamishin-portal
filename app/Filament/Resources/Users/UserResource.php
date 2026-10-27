<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::User;

    protected static ?string $recordTitleAttribute = 'name';

    protected static bool $isScopedToTenant = false;

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('User Details')
                    ->components([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        TextInput::make('password')
                            ->password()
                            ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                            ->dehydrated(fn ($state): bool => filled($state))
                            ->required(fn (string $context): bool => $context === 'create')
                            ->confirmed()
                            ->maxLength(255),
                        TextInput::make('password_confirmation')
                            ->password()
                            ->required(fn (string $context): bool => $context === 'create')
                            ->visible(fn (string $context): bool => $context === 'create' || $context === 'edit'),
                        Select::make('roles')
                            ->relationship('roles', 'name')
                            ->multiple()
                            ->preload()
                            ->searchable(),
                    ])->columns(2),

                Section::make('Security')
                    ->components([
                        DateTimePicker::make('email_verified_at')
                            ->label('Email Verified At')
                            ->helperText('Leave empty to keep unverified.'),
                    ])->columns(2)
                    ->collapsible(),
            ]);
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('User Information')
                    ->components([
                        TextEntry::make('name'),
                        TextEntry::make('email')
                            ->label('Email Address')
                            ->copyable(),
                        TextEntry::make('status')
                            ->badge()
                            ->state(fn (User $record): string => $record->isBanned() ? 'Banned' : 'Active')
                            ->color(fn (string $state): string => match ($state) {
                                'Active' => 'success',
                                'Banned' => 'danger',
                                default => 'gray',
                            }),
                        TextEntry::make('ban_reason')
                            ->label('Ban Reason')
                            ->visible(fn (User $record): bool => $record->isBanned()),
                        TextEntry::make('roles.name')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'admin' => 'danger',
                                'user' => 'success',
                                default => 'gray',
                            }),
                        TextEntry::make('created_at')
                            ->dateTime(),
                        TextEntry::make('updated_at')
                            ->dateTime(),
                    ])->columns(2),

                Section::make('Authentication & Security')
                    ->components([
                        TextEntry::make('email_verified_at')
                            ->label('Email Verified')
                            ->dateTime()
                            ->placeholder('Unverified')
                            ->badge()
                            ->color(fn ($state): string => $state ? 'success' : 'danger'),
                        TextEntry::make('password')
                            ->label('Password Authenticator')
                            ->state(fn (User $record): string => filled($record->password) ? 'Configured' : 'Passwordless')
                            ->badge()
                            ->color(fn (string $state): string => $state === 'Configured' ? 'success' : 'info'),
                        TextEntry::make('app_authentication_secret')
                            ->label('App MFA (TOTP)')
                            ->formatStateUsing(fn ($state): string => filled($state) ? 'Enabled' : 'Disabled')
                            ->badge()
                            ->color(fn ($state): string => filled($state) ? 'success' : 'warning'),
                        TextEntry::make('has_email_authentication')
                            ->label('Email MFA (OTP)')
                            ->formatStateUsing(fn (bool $state): string => $state ? 'Enabled' : 'Disabled')
                            ->badge()
                            ->color(fn (bool $state): string => $state ? 'success' : 'warning'),
                        TextEntry::make('passkeys_count')
                            ->label('Passkeys Registered')
                            ->state(fn (User $record): int => $record->passkeys()->count())
                            ->badge()
                            ->color(fn (int $state): string => $state > 0 ? 'success' : 'gray'),
                        TextEntry::make('active_sessions_count')
                            ->label('Active Browser Sessions')
                            ->state(fn (User $record): int => DB::table('sessions')->where('user_id', $record->id)->count())
                            ->badge()
                            ->color(fn (int $state): string => $state > 0 ? 'info' : 'gray'),
                    ])->columns(3),

                Section::make('Connected Social Accounts')
                    ->components([
                        TextEntry::make('social_summary')
                            ->label('Linked Providers')
                            ->state(function (User $record): string {
                                $accounts = $record->socialAccounts;
                                if ($accounts->isEmpty()) {
                                    return 'No social accounts linked.';
                                }

                                return $accounts->map(fn ($a): string => ucfirst($a->provider).($a->nickname ? " (@{$a->nickname})" : ''))->implode(', ');
                            }),
                    ])
                    ->collapsible(),
            ]);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('email')
                    ->searchable()
                    ->copyable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (User $record): string => $record->isBanned() ? 'Banned' : 'Active')
                    ->color(fn (string $state): string => match ($state) {
                        'Active' => 'success',
                        'Banned' => 'danger',
                        default => 'gray',
                    })
                    ->tooltip(fn (User $record): ?string => $record->isBanned() ? ($record->ban_reason ? 'Reason: '.$record->ban_reason : 'Suspended by admin') : null),
                TextColumn::make('roles.name')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'admin' => 'danger',
                        'user' => 'success',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('socialAccounts.provider')
                    ->label('Social')
                    ->badge()
                    ->separator(',')
                    ->color('info')
                    ->placeholder('None'),
                TextColumn::make('passkeys_count')
                    ->counts('passkeys')
                    ->label('Passkeys')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'gray'),
                IconColumn::make('email_verified_at')
                    ->label('Verified')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->colors([
                        'success' => fn ($state): bool => $state !== null,
                        'danger' => fn ($state): bool => $state === null,
                    ])
                    ->sortable(),
                IconColumn::make('app_authentication_secret')
                    ->label('MFA')
                    ->boolean()
                    ->trueIcon('heroicon-o-lock-closed')
                    ->falseIcon('heroicon-o-lock-open')
                    ->colors([
                        'success' => filled(...),
                        'warning' => blank(...),
                    ])
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('banned')
                    ->label('Account Status')
                    ->placeholder('All Accounts')
                    ->trueLabel('Banned / Suspended')
                    ->falseLabel('Active')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('banned_at'),
                        false: fn ($query) => $query->whereNull('banned_at'),
                    ),
                TernaryFilter::make('email_verified_at')
                    ->label('Email Verified')
                    ->nullable(),
                TernaryFilter::make('has_social')
                    ->label('Social Login')
                    ->placeholder('All Accounts')
                    ->trueLabel('With Social Accounts')
                    ->falseLabel('Without Social Accounts')
                    ->queries(
                        true: fn ($query) => $query->has('socialAccounts'),
                        false: fn ($query) => $query->doesntHave('socialAccounts'),
                    ),
            ])
            ->recordActions([
                Action::make('impersonate')
                    ->label('Impersonate')
                    ->icon('heroicon-o-user-plus')
                    ->url(fn (User $user): string => route('impersonate', $user->id))
                    ->openUrlInNewTab()
                    ->visible(fn (User $record): bool => ($user = Auth::user()) instanceof User && $user->hasRole('admin') && $record->canBeImpersonated()),

                Action::make('ban')
                    ->label('Ban User')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn (User $record): bool => ! $record->isBanned() && ! $record->hasRole('super_admin'))
                    ->requiresConfirmation()
                    ->form([
                        TextInput::make('reason')
                            ->label('Suspension Reason')
                            ->placeholder('e.g. Violation of terms, suspicious activity')
                            ->required(),
                    ])
                    ->action(function (User $record, array $data): void {
                        $record->ban($data['reason'] ?? null);
                        Notification::make()
                            ->title('User Suspended')
                            ->body("{$record->name} has been banned and sessions revoked.")
                            ->danger()
                            ->send();
                    }),

                Action::make('unban')
                    ->label('Unban')
                    ->icon('heroicon-o-arrow-path')
                    ->color('success')
                    ->visible(fn (User $record): bool => $record->isBanned())
                    ->requiresConfirmation()
                    ->action(function (User $record): void {
                        $record->unban();
                        Notification::make()
                            ->title('User Restored')
                            ->body("{$record->name} account has been restored.")
                            ->success()
                            ->send();
                    }),

                Action::make('revokeSessions')
                    ->label('Revoke Sessions')
                    ->icon('heroicon-o-arrow-left-on-rectangle')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->action(function (User $record): void {
                        $record->revokeAllSessions();
                        Notification::make()
                            ->title('Sessions Terminated')
                            ->body("Active sessions and OAuth tokens for {$record->name} have been cleared.")
                            ->warning()
                            ->send();
                    }),

                ViewAction::make(),
                EditAction::make(),
                Action::make('verify_email')
                    ->icon('heroicon-o-check')
                    ->label('Verify Email')
                    ->action(fn (User $user) => $user->forceFill(['email_verified_at' => now()])->save())
                    ->visible(fn (User $user): bool => $user->email_verified_at === null)
                    ->requiresConfirmation()
                    ->color('success'),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    BulkAction::make('verify_selected')
                        ->label('Verify Selected')
                        ->icon('heroicon-o-check')
                        ->action(fn ($records) => $records->each->forceFill(['email_verified_at' => now()])->each->save())
                        ->requiresConfirmation()
                        ->color('success'),
                    BulkAction::make('unverify_selected')
                        ->label('Unverify Selected')
                        ->icon('heroicon-o-x-mark')
                        ->action(fn ($records) => $records->each->forceFill(['email_verified_at' => null])->each->save())
                        ->requiresConfirmation()
                        ->color('danger'),
                ]),
            ]);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
            'view' => ViewUser::route('/{record}'),
        ];
    }
}
