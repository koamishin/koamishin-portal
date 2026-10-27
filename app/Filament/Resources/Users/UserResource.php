<?php

namespace App\Filament\Resources\Users;

use AlizHarb\ActivityLog\RelationManagers\ActivitiesRelationManager;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Models\Tenant;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\KeyValueEntry;
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
                Section::make('Profile Information')
                    ->components([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        TextInput::make('username')
                            ->label('Username (@)')
                            ->prefix('@')
                            ->maxLength(50)
                            ->unique(ignoreRecord: true),
                        TextInput::make('phone_number')
                            ->label('Phone Number')
                            ->tel()
                            ->maxLength(30)
                            ->unique(ignoreRecord: true),
                        TextInput::make('web3_wallet')
                            ->label('Web3 Wallet Address (0x...)')
                            ->extraInputAttributes(['class' => 'font-mono'])
                            ->maxLength(60),
                        Select::make('roles')
                            ->relationship('roles', 'name')
                            ->multiple()
                            ->preload()
                            ->searchable(),
                    ])->columns(2),

                Section::make('Custom Metadata (Clerk Meta)')
                    ->description('Arbitrary JSON key-value metadata attributes associated with this user profile.')
                    ->components([
                        KeyValue::make('metadata')
                            ->label('User Metadata')
                            ->keyLabel('Attribute')
                            ->valueLabel('Value')
                            ->reorderable(),
                    ])
                    ->collapsible(),

                Section::make('Security & Password')
                    ->components([
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
                        DateTimePicker::make('email_verified_at')
                            ->label('Email Verified At')
                            ->helperText('Leave empty to keep unverified.'),
                        DateTimePicker::make('phone_verified_at')
                            ->label('Phone Verified At')
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
                        TextEntry::make('username')
                            ->label('Username (@)')
                            ->state(fn (User $record): string => $record->username ? '@'.$record->username : '—')
                            ->copyable(),
                        TextEntry::make('phone_number')
                            ->label('Phone Number')
                            ->state(fn (User $record): string => $record->phone_number ?? '—')
                            ->copyable(),
                        TextEntry::make('web3_wallet')
                            ->label('Web3 Wallet')
                            ->fontFamily('mono')
                            ->state(fn (User $record): string => $record->web3_wallet ?? '—')
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
                    ])->columns(3),

                Section::make('Custom Metadata')
                    ->description('User custom profile key-value properties')
                    ->components([
                        KeyValueEntry::make('metadata')
                            ->label('')
                            ->keyLabel('Key')
                            ->valueLabel('Value'),
                    ])
                    ->collapsible(),

                Section::make('Authentication & Security')
                    ->components([
                        TextEntry::make('email_verified_at')
                            ->label('Email Verified')
                            ->dateTime()
                            ->placeholder('Unverified')
                            ->badge()
                            ->color(fn ($state): string => $state ? 'success' : 'danger')
                            ->suffixAction(
                                Action::make('toggleEmailVerified')
                                    ->label(fn (User $record): string => $record->email_verified_at ? 'Mark Unverified' : 'Mark Verified')
                                    ->icon(fn (User $record): string => $record->email_verified_at ? 'heroicon-o-x-mark' : 'heroicon-o-check')
                                    ->color(fn (User $record): string => $record->email_verified_at ? 'danger' : 'success')
                                    ->action(function (User $record): void {
                                        $record->forceFill([
                                            'email_verified_at' => $record->email_verified_at ? null : now(),
                                        ])->save();

                                        Notification::make()
                                            ->title($record->email_verified_at ? 'Email Marked Verified' : 'Email Marked Unverified')
                                            ->success()
                                            ->send();
                                    })
                            ),

                        TextEntry::make('phone_verified_at')
                            ->label('Phone Verified')
                            ->dateTime()
                            ->placeholder('Unverified')
                            ->badge()
                            ->color(fn ($state): string => $state ? 'success' : 'warning')
                            ->suffixAction(
                                Action::make('togglePhoneVerified')
                                    ->label(fn (User $record): string => $record->hasVerifiedPhone() ? 'Unverify' : 'Verify')
                                    ->icon(fn (User $record): string => $record->hasVerifiedPhone() ? 'heroicon-o-x-mark' : 'heroicon-o-check')
                                    ->color(fn (User $record): string => $record->hasVerifiedPhone() ? 'danger' : 'success')
                                    ->action(function (User $record): void {
                                        $record->forceFill([
                                            'phone_verified_at' => $record->hasVerifiedPhone() ? null : now(),
                                        ])->save();

                                        Notification::make()
                                            ->title($record->hasVerifiedPhone() ? 'Phone Verified' : 'Phone Unverified')
                                            ->success()
                                            ->send();
                                    })
                            ),

                        TextEntry::make('password')
                            ->label('Password Authenticator')
                            ->state(fn (User $record): string => filled($record->password) ? 'Configured' : 'Passwordless')
                            ->badge()
                            ->color(fn (string $state): string => $state === 'Configured' ? 'success' : 'info'),

                        TextEntry::make('mfa_status')
                            ->label('Two-Factor / MFA')
                            ->state(fn (User $record): string => $record->hasMfa() ? 'Enabled' : 'Disabled')
                            ->badge()
                            ->color(fn (User $record): string => $record->hasMfa() ? 'success' : 'warning')
                            ->suffixAction(
                                Action::make('toggleMfaStatus')
                                    ->label(fn (User $record): string => $record->hasMfa() ? 'Disable' : 'Enable Email 2FA')
                                    ->icon(fn (User $record): string => $record->hasMfa() ? 'heroicon-o-shield-exclamation' : 'heroicon-o-shield-check')
                                    ->color(fn (User $record): string => $record->hasMfa() ? 'warning' : 'success')
                                    ->requiresConfirmation()
                                    ->action(function (User $record): void {
                                        if ($record->hasMfa()) {
                                            $record->update([
                                                'two_factor_secret' => null,
                                                'two_factor_recovery_codes' => null,
                                                'two_factor_confirmed_at' => null,
                                                'app_authentication_secret' => null,
                                                'app_authentication_recovery_codes' => null,
                                                'has_email_authentication' => false,
                                            ]);
                                            Notification::make()->title('2FA Disabled')->warning()->send();
                                        } else {
                                            $record->update(['has_email_authentication' => true]);
                                            Notification::make()->title('Email 2FA Enabled')->success()->send();
                                        }
                                    })
                            ),

                        TextEntry::make('passkeys_count')
                            ->label('Passkeys Registered')
                            ->state(fn (User $record): int => $record->passkeys()->count())
                            ->badge()
                            ->color(fn (int $state): string => $state > 0 ? 'success' : 'gray'),

                        TextEntry::make('active_sessions_count')
                            ->label('Active Browser Sessions')
                            ->state(fn (User $record): int => DB::table('sessions')->where('user_id', $record->id)->count())
                            ->badge()
                            ->color(fn (int $state): string => $state > 0 ? 'info' : 'gray')
                            ->suffixAction(
                                Action::make('terminateSessions')
                                    ->label('Revoke All')
                                    ->icon('heroicon-o-arrow-left-on-rectangle')
                                    ->color('danger')
                                    ->requiresConfirmation()
                                    ->action(function (User $record): void {
                                        $record->revokeAllSessions();
                                        Notification::make()->title('Sessions Revoked')->warning()->send();
                                    })
                            ),
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
                TernaryFilter::make('phone_verified_at')
                    ->label('Phone Verified')
                    ->nullable(),
                TernaryFilter::make('has_social')
                    ->label('Social SSO')
                    ->placeholder('All Accounts')
                    ->trueLabel('With Social Accounts')
                    ->falseLabel('Without Social Accounts')
                    ->queries(
                        true: fn ($query) => $query->has('socialAccounts'),
                        false: fn ($query) => $query->doesntHave('socialAccounts'),
                    ),
                TernaryFilter::make('has_passkeys')
                    ->label('Passkeys')
                    ->placeholder('All Accounts')
                    ->trueLabel('Has Registered Passkey')
                    ->falseLabel('No Passkeys')
                    ->queries(
                        true: fn ($query) => $query->has('passkeys'),
                        false: fn ($query) => $query->doesntHave('passkeys'),
                    ),
                TernaryFilter::make('has_mfa')
                    ->label('2FA / MFA')
                    ->placeholder('All Accounts')
                    ->trueLabel('MFA Enabled')
                    ->falseLabel('MFA Disabled')
                    ->queries(
                        true: fn ($query) => $query->where(fn ($q) => $q->whereNotNull('app_authentication_secret')->orWhere('has_email_authentication', true)->orWhereNotNull('two_factor_confirmed_at')),
                        false: fn ($query) => $query->whereNull('app_authentication_secret')->where('has_email_authentication', false)->whereNull('two_factor_confirmed_at'),
                    ),
                TernaryFilter::make('has_wallet')
                    ->label('Web3 Wallet')
                    ->placeholder('All Accounts')
                    ->trueLabel('Connected Wallet')
                    ->falseLabel('No Wallet')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('web3_wallet'),
                        false: fn ($query) => $query->whereNull('web3_wallet'),
                    ),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),

                ActionGroup::make([
                    Action::make('assignOrganization')
                        ->label('Assign Organization')
                        ->icon('heroicon-o-building-office-2')
                        ->color('info')
                        ->schema([
                            Select::make('tenant_id')
                                ->label('Organization')
                                ->options(Tenant::query()->pluck('name', 'id'))
                                ->searchable()
                                ->required(),
                            Select::make('role')
                                ->label('Organization Role')
                                ->options([
                                    'member' => 'Member',
                                    'admin' => 'Admin',
                                    'owner' => 'Owner',
                                ])
                                ->default('member')
                                ->required(),
                        ])
                        ->action(function (User $record, array $data): void {
                            $record->tenants()->syncWithoutDetaching([
                                $data['tenant_id'] => ['role' => $data['role']],
                            ]);

                            Notification::make()
                                ->title('Organization Assigned')
                                ->body("{$record->name} added to organization.")
                                ->success()
                                ->send();
                        }),

                    Action::make('configurePhone')
                        ->label('Configure Phone')
                        ->icon('heroicon-o-phone')
                        ->schema([
                            TextInput::make('phone_number')
                                ->label('Phone Number')
                                ->tel()
                                ->default(fn (User $record): ?string => $record->phone_number)
                                ->required(),
                            Toggle::make('is_verified')
                                ->label('Mark Phone as Verified')
                                ->default(fn (User $record): bool => $record->hasVerifiedPhone()),
                        ])
                        ->action(function (User $record, array $data): void {
                            $record->update([
                                'phone_number' => $data['phone_number'],
                                'phone_verified_at' => $data['is_verified'] ? now() : null,
                            ]);

                            Notification::make()
                                ->title('Phone Updated')
                                ->success()
                                ->send();
                        }),

                    Action::make('configureWallet')
                        ->label('Connect Web3 Wallet')
                        ->icon('heroicon-o-credit-card')
                        ->schema([
                            TextInput::make('web3_wallet')
                                ->label('Ethereum / Web3 Address (0x...)')
                                ->default(fn (User $record): ?string => $record->web3_wallet)
                                ->required(),
                        ])
                        ->action(function (User $record, array $data): void {
                            $record->update(['web3_wallet' => $data['web3_wallet']]);

                            Notification::make()
                                ->title('Web3 Wallet Linked')
                                ->success()
                                ->send();
                        }),

                    Action::make('toggleMfa')
                        ->label(fn (User $record): string => $record->hasMfa() ? 'Disable 2FA / MFA' : 'Enable Email MFA')
                        ->icon('heroicon-o-shield-check')
                        ->color(fn (User $record): string => $record->hasMfa() ? 'warning' : 'success')
                        ->requiresConfirmation()
                        ->action(function (User $record): void {
                            if ($record->hasMfa()) {
                                $record->update([
                                    'two_factor_secret' => null,
                                    'two_factor_recovery_codes' => null,
                                    'two_factor_confirmed_at' => null,
                                    'app_authentication_secret' => null,
                                    'app_authentication_recovery_codes' => null,
                                    'has_email_authentication' => false,
                                ]);

                                Notification::make()
                                    ->title('MFA Disabled')
                                    ->body("All 2FA and MFA methods have been turned off for {$record->name}.")
                                    ->warning()
                                    ->send();
                            } else {
                                $record->update([
                                    'has_email_authentication' => true,
                                ]);

                                Notification::make()
                                    ->title('Email MFA Enabled')
                                    ->body("Email OTP authentication enabled for {$record->name}.")
                                    ->success()
                                    ->send();
                            }
                        }),

                    Action::make('impersonate')
                        ->label('Impersonate')
                        ->icon('heroicon-o-user-plus')
                        ->url(fn (User $user): string => route('impersonate', $user->id))
                        ->openUrlInNewTab()
                        ->visible(fn (User $record): bool => ($user = Auth::user()) instanceof User && $user->hasRole('admin') && $record->canBeImpersonated()),

                    Action::make('ban')
                        ->label('Ban / Suspend')
                        ->icon('heroicon-o-no-symbol')
                        ->color('danger')
                        ->visible(fn (User $record): bool => ! $record->isBanned() && ! $record->hasRole('super_admin'))
                        ->requiresConfirmation()
                        ->form([
                            TextInput::make('reason')
                                ->label('Suspension Reason')
                                ->placeholder('Violation of terms, suspicious activity')
                                ->required(),
                        ])
                        ->action(function (User $record, array $data): void {
                            $record->ban($data['reason'] ?? null);
                            Notification::make()
                                ->title('User Suspended')
                                ->body("{$record->name} has been banned and all active sessions terminated.")
                                ->danger()
                                ->send();
                        }),

                    Action::make('unban')
                        ->label('Unban User')
                        ->icon('heroicon-o-arrow-path')
                        ->color('success')
                        ->visible(fn (User $record): bool => $record->isBanned())
                        ->requiresConfirmation()
                        ->action(function (User $record): void {
                            $record->unban();
                            Notification::make()
                                ->title('User Restored')
                                ->body("{$record->name} account is now active.")
                                ->success()
                                ->send();
                        }),

                    Action::make('revokeSessions')
                        ->label('Revoke All Sessions')
                        ->icon('heroicon-o-arrow-left-on-rectangle')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->action(function (User $record): void {
                            $record->revokeAllSessions();
                            Notification::make()
                                ->title('Sessions Terminated')
                                ->body("All active sessions and tokens for {$record->name} have been cleared.")
                                ->warning()
                                ->send();
                        }),

                    Action::make('verify_email')
                        ->icon('heroicon-o-check')
                        ->label('Verify Email')
                        ->action(fn (User $user) => $user->forceFill(['email_verified_at' => now()])->save())
                        ->visible(fn (User $user): bool => $user->email_verified_at === null)
                        ->requiresConfirmation()
                        ->color('success'),

                    DeleteAction::make(),
                ])
                    ->label('Actions')
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->size('sm')
                    ->color('gray'),
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
    public static function getRelations(): array
    {
        return [
            RelationManagers\SessionsRelationManager::class,
            RelationManagers\TenantsRelationManager::class,
            ActivitiesRelationManager::class,
        ];
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
