<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Widgets\AuthMethodsChartWidget;
use App\Filament\Widgets\UserSignupsChartWidget;
use App\Models\Tenant;
use App\Models\TenantApplication;
use App\Models\User;
use App\Models\WebhookDelivery;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Spatie\Permission\Models\Role;

/**
 * @property-read Collection<int, TenantApplication> $tenantApplications
 * @property-read array<string, mixed> $stats
 */
class Dashboard extends BaseDashboard
{
    protected string $view = 'filament.pages.dashboard';

    public string $search = '';

    public string $statusFilter = 'all'; // all, active, banned, unverified

    #[\Override]
    public function getWidgets(): array
    {
        return [
            UserSignupsChartWidget::class,
            AuthMethodsChartWidget::class,
        ];
    }

    /**
     * @return array<Action>
     */
    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('createUser')
                ->label('Quick Add User')
                ->icon('heroicon-m-user-plus')
                ->color('primary')
                ->schema([
                    TextInput::make('name')
                        ->label('Full Name')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('email')
                        ->label('Email Address')
                        ->email()
                        ->required()
                        ->unique('users', 'email'),

                    TextInput::make('password')
                        ->label('Password')
                        ->password()
                        ->required()
                        ->confirmed()
                        ->minLength(8),

                    TextInput::make('password_confirmation')
                        ->label('Confirm Password')
                        ->password()
                        ->required(),

                    Select::make('role')
                        ->label('Initial Role')
                        ->options([
                            'user' => 'User (Standard Member)',
                            'admin' => 'Admin (Panel Access)',
                        ])
                        ->default('user')
                        ->required(),
                ])
                ->action(function (array $data): void {
                    /** @var User $newUser */
                    $newUser = User::query()->create([
                        'name' => $data['name'],
                        'email' => $data['email'],
                        'password' => Hash::make($data['password']),
                        'email_verified_at' => now(),
                    ]);

                    $role = Role::query()->firstOrCreate([
                        'name' => $data['role'],
                        'guard_name' => 'web',
                    ]);

                    $newUser->assignRole($role);

                    $tenant = Filament::getTenant();
                    if ($tenant instanceof Tenant) {
                        $newUser->tenants()->syncWithoutDetaching([$tenant->id => ['role' => 'member']]);
                    }

                    $currentApp = $this->getActiveApplication();
                    if ($currentApp) {
                        $currentApp->users()->syncWithoutDetaching([
                            $newUser->id => [
                                'role' => $data['role'],
                                'last_sign_in_at' => now(),
                            ],
                        ]);
                    }

                    Notification::make()
                        ->title('User Created')
                        ->body("{$newUser->name} ({$newUser->email}) added successfully.")
                        ->success()
                        ->send();
                }),

            Action::make('createApplication')
                ->label('New Application')
                ->icon('heroicon-m-plus')
                ->color('gray')
                ->schema([
                    TextInput::make('name')
                        ->label('Application Name')
                        ->placeholder('e.g. Acme Billing, Internal CRM')
                        ->required()
                        ->maxLength(255),

                    ToggleButtons::make('environment')
                        ->label('Environment')
                        ->options([
                            'development' => 'Development',
                            'production' => 'Production',
                        ])
                        ->colors([
                            'development' => 'warning',
                            'production' => 'success',
                        ])
                        ->default('development')
                        ->inline()
                        ->required(),

                    Select::make('framework')
                        ->label('Target Framework')
                        ->options([
                            'laravel' => 'Laravel (PHP / Blade / Inertia)',
                            'nextjs' => 'Next.js (React / SSR)',
                            'vue' => 'Vue 3 / Nuxt',
                            'react' => 'React (Vite / SPA)',
                            'nodejs' => 'Node.js (Express / Fastify)',
                            'vanilla' => 'Vanilla JavaScript',
                        ])
                        ->default('laravel')
                        ->required(),

                    TagsInput::make('redirect_uris')
                        ->label('Callback URLs (Redirect URIs)')
                        ->default(['http://localhost:8000/koamishin/callback'])
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $tenant = Filament::getTenant();

                    if (! $tenant instanceof Tenant) {
                        return;
                    }

                    $secret = Str::random(40);

                    $client = new Client;
                    $client->forceFill([
                        'name' => $data['name'],
                        'secret' => $secret,
                        'redirect_uris' => $data['redirect_uris'] ?? [],
                        'grant_types' => ['authorization_code', 'refresh_token'],
                        'revoked' => false,
                    ])->save();

                    /** @var TenantApplication $app */
                    $app = TenantApplication::query()->create([
                        'tenant_id' => $tenant->id,
                        'oauth_client_id' => (string) $client->getKey(),
                        'name' => $data['name'],
                        'framework' => $data['framework'],
                        'environment' => $data['environment'],
                        'redirect_uris' => $data['redirect_uris'],
                    ]);

                    session(['current_application_id' => $app->id]);

                    Notification::make()
                        ->title('Application Provisioned')
                        ->body("{$app->name} created and active.")
                        ->success()
                        ->send();
                }),
        ];
    }

    public function getActiveTenant(): ?Tenant
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Tenant ? $tenant : null;
    }

    public function getActiveApplication(): ?TenantApplication
    {
        return TenantApplication::current($this->getActiveTenant());
    }

    /**
     * @return Collection<int, TenantApplication>
     */
    public function getTenantApplicationsProperty(): Collection
    {
        $tenant = $this->getActiveTenant();

        if (! $tenant instanceof Tenant) {
            return collect();
        }

        return $tenant->applications()->latest()->get();
    }

    /**
     * @return array{total_users: int, active_users: int, banned_users: int, unverified_users: int, active_sessions: int, total_apps: int, webhook_rate: string}
     */
    public function getStatsProperty(): array
    {
        $app = $this->getActiveApplication();
        $userQuery = $app
            ? $app->users()
            : ($this->getActiveTenant() ? $this->getActiveTenant()->users() : User::query());

        $totalUsers = (clone $userQuery)->count();
        $bannedUsers = (clone $userQuery)->whereNotNull('users.banned_at')->count();
        $unverifiedUsers = (clone $userQuery)->whereNull('users.email_verified_at')->count();
        $activeUsers = max(0, $totalUsers - $bannedUsers);

        $userIds = (clone $userQuery)->pluck('users.id');
        $activeSessions = DB::table('sessions')->whereIn('user_id', $userIds)->count();

        $tenant = $this->getActiveTenant();
        $totalApps = $tenant instanceof Tenant ? $tenant->applications()->count() : 0;

        $deliveriesTotal = WebhookDelivery::query()->count();
        $deliveriesSuccess = WebhookDelivery::query()->where('status', 'delivered')->count();
        $rate = $deliveriesTotal > 0
            ? round(($deliveriesSuccess / $deliveriesTotal) * 100).'%'
            : '100%';

        return [
            'total_users' => $totalUsers,
            'active_users' => $activeUsers,
            'banned_users' => $bannedUsers,
            'unverified_users' => $unverifiedUsers,
            'active_sessions' => $activeSessions,
            'total_apps' => $totalApps,
            'webhook_rate' => $rate,
        ];
    }

    /**
     * @return LengthAwarePaginator<User>
     */
    public function getPagedUsers(): LengthAwarePaginator
    {
        $app = $this->getActiveApplication();
        $query = $app
            ? $app->users()
            : ($this->getActiveTenant() ? $this->getActiveTenant()->users() : User::query());

        return $query
            ->when($this->search !== '', function (Builder $query): void {
                $query->where(function (Builder $sub): void {
                    $sub->where('users.name', 'like', "%{$this->search}%")
                        ->orWhere('users.email', 'like', "%{$this->search}%");
                });
            })
            ->when($this->statusFilter === 'active', fn (Builder $q) => $q->whereNull('users.banned_at'))
            ->when($this->statusFilter === 'banned', fn (Builder $q) => $q->whereNotNull('users.banned_at'))
            ->when($this->statusFilter === 'unverified', fn (Builder $q) => $q->whereNull('users.email_verified_at'))
            ->with(['roles', 'socialAccounts', 'passkeys'])
            ->latest('users.created_at')
            ->paginate(8);
    }

    public function banUser(int $userId, string $reason = 'Suspended by admin'): void
    {
        /** @var User|null $user */
        $user = User::query()->find($userId);

        if (! $user || $user->hasRole('super_admin')) {
            return;
        }

        $user->ban($reason);

        Notification::make()
            ->title('User Suspended')
            ->body("{$user->name} has been banned and all active sessions revoked.")
            ->danger()
            ->send();
    }

    public function unbanUser(int $userId): void
    {
        /** @var User|null $user */
        $user = User::query()->find($userId);

        if (! $user) {
            return;
        }

        $user->unban();

        Notification::make()
            ->title('User Restored')
            ->body("{$user->name} account is now active.")
            ->success()
            ->send();
    }

    public function verifyUserEmail(int $userId): void
    {
        /** @var User|null $user */
        $user = User::query()->find($userId);

        if (! $user) {
            return;
        }

        $user->forceFill(['email_verified_at' => now()])->save();

        Notification::make()
            ->title('Email Verified')
            ->body("{$user->email} marked as verified.")
            ->success()
            ->send();
    }

    public function unverifyUserEmail(int $userId): void
    {
        /** @var User|null $user */
        $user = User::query()->find($userId);

        if (! $user) {
            return;
        }

        $user->forceFill(['email_verified_at' => null])->save();

        Notification::make()
            ->title('Email Unverified')
            ->body("{$user->email} marked as unverified.")
            ->warning()
            ->send();
    }

    public function revokeUserSessions(int $userId): void
    {
        /** @var User|null $user */
        $user = User::query()->find($userId);

        if (! $user) {
            return;
        }

        $user->revokeAllSessions();

        Notification::make()
            ->title('Sessions Terminated')
            ->body("All devices and tokens for {$user->name} have been logged out.")
            ->warning()
            ->send();
    }

    public function switchApp(int $appId): void
    {
        session(['current_application_id' => $appId]);

        Notification::make()
            ->title('Active Context Switched')
            ->success()
            ->send();
    }
}
