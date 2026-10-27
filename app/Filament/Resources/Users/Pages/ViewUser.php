<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Filament\Widgets\UserActivityHeatmapWidget;
use App\Models\Tenant;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;

class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    public function getUserRecord(): User
    {
        /** @var User $record */
        $record = $this->getRecord();

        return $record;
    }

    #[\Override]
    protected function getHeaderWidgets(): array
    {
        return [
            UserActivityHeatmapWidget::class,
        ];
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),

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
                        ->label('Role in Organization')
                        ->options([
                            'member' => 'Member',
                            'admin' => 'Admin',
                            'owner' => 'Owner',
                        ])
                        ->default('member')
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $record = $this->getUserRecord();
                    $record->tenants()->syncWithoutDetaching([
                        $data['tenant_id'] => ['role' => $data['role']],
                    ]);

                    Notification::make()
                        ->title('Organization Assigned')
                        ->body("{$record->name} has been enrolled in the organization.")
                        ->success()
                        ->send();
                }),

            Action::make('configurePhone')
                ->label('Configure Phone')
                ->icon('heroicon-o-phone')
                ->color('gray')
                ->schema([
                    TextInput::make('phone_number')
                        ->label('Phone Number')
                        ->tel()
                        ->default(fn (): ?string => $this->getUserRecord()->phone_number)
                        ->required(),
                    Toggle::make('is_verified')
                        ->label('Mark as Verified')
                        ->default(fn (): bool => $this->getUserRecord()->hasVerifiedPhone()),
                ])
                ->action(function (array $data): void {
                    $record = $this->getUserRecord();
                    $record->update([
                        'phone_number' => $data['phone_number'],
                        'phone_verified_at' => $data['is_verified'] ? now() : null,
                    ]);

                    Notification::make()
                        ->title('Phone Number Updated')
                        ->success()
                        ->send();
                }),

            Action::make('configureWallet')
                ->label('Web3 Wallet')
                ->icon('heroicon-o-credit-card')
                ->color('gray')
                ->schema([
                    TextInput::make('web3_wallet')
                        ->label('Ethereum / Web3 Wallet Address (0x...)')
                        ->default(fn (): ?string => $this->getUserRecord()->web3_wallet)
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $record = $this->getUserRecord();
                    $record->update(['web3_wallet' => $data['web3_wallet']]);

                    Notification::make()
                        ->title('Web3 Wallet Linked')
                        ->success()
                        ->send();
                }),

            Action::make('toggleMfa')
                ->label(fn (): string => $this->getUserRecord()->hasMfa() ? 'Disable 2FA' : 'Enable Email 2FA')
                ->icon('heroicon-o-shield-check')
                ->color(fn (): string => $this->getUserRecord()->hasMfa() ? 'warning' : 'success')
                ->requiresConfirmation()
                ->action(function (): void {
                    $record = $this->getUserRecord();

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
                            ->title('2FA / MFA Disabled')
                            ->body('All two-factor methods have been cleared.')
                            ->warning()
                            ->send();
                    } else {
                        $record->update([
                            'has_email_authentication' => true,
                        ]);

                        Notification::make()
                            ->title('Email 2FA Enabled')
                            ->body('Email verification codes are now required on sign-in.')
                            ->success()
                            ->send();
                    }
                }),

            Action::make('impersonate')
                ->label('Impersonate')
                ->icon('heroicon-o-user-plus')
                ->color('gray')
                ->url(fn (): string => route('impersonate', $this->getUserRecord()->id))
                ->openUrlInNewTab()
                ->visible(fn (): bool => ($user = Auth::user()) instanceof User && $user->hasRole('admin') && $this->getUserRecord()->canBeImpersonated()),

            Action::make('ban')
                ->label('Ban User')
                ->icon('heroicon-o-no-symbol')
                ->color('danger')
                ->visible(fn (): bool => ! $this->getUserRecord()->isBanned() && ! $this->getUserRecord()->hasRole('super_admin'))
                ->requiresConfirmation()
                ->form([
                    TextInput::make('reason')
                        ->label('Reason')
                        ->placeholder('Suspension reason')
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $record = $this->getUserRecord();
                    $record->ban($data['reason'] ?? null);

                    Notification::make()
                        ->title('User Suspended')
                        ->danger()
                        ->send();
                }),

            Action::make('unban')
                ->label('Unban User')
                ->icon('heroicon-o-arrow-path')
                ->color('success')
                ->visible(fn (): bool => $this->getUserRecord()->isBanned())
                ->requiresConfirmation()
                ->action(function (): void {
                    $record = $this->getUserRecord();
                    $record->unban();

                    Notification::make()
                        ->title('User Restored')
                        ->success()
                        ->send();
                }),

            Action::make('revokeSessions')
                ->label('Revoke All Sessions')
                ->icon('heroicon-o-arrow-left-on-rectangle')
                ->color('warning')
                ->requiresConfirmation()
                ->action(function (): void {
                    $record = $this->getUserRecord();
                    $record->revokeAllSessions();

                    Notification::make()
                        ->title('Sessions Terminated')
                        ->warning()
                        ->send();
                }),
        ];
    }
}
