<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\User;
use App\Models\WebhookDelivery;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Client;

class AuthStatsOverviewWidget extends BaseWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 1;

    #[\Override]
    protected function getStats(): array
    {
        $totalUsers = User::query()->count();
        $bannedUsers = User::query()->whereNotNull('banned_at')->count();
        $unverifiedUsers = User::query()->whereNull('email_verified_at')->count();

        $activeSessions = DB::table('sessions')
            ->whereNotNull('user_id')
            ->count();

        $oauthApps = Client::query()->where('revoked', false)->count();

        $deliveriesTotal = WebhookDelivery::query()->count();
        $deliveriesSuccess = WebhookDelivery::query()->where('status', 'delivered')->count();
        $deliveryRate = $deliveriesTotal > 0
            ? round(($deliveriesSuccess / $deliveriesTotal) * 100).'%'
            : '100%';

        return [
            Stat::make('Total Users', (string) $totalUsers)
                ->description("{$unverifiedUsers} unverified, {$bannedUsers} suspended")
                ->descriptionIcon($bannedUsers > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-user-group')
                ->chart([max(1, $totalUsers - 5), max(2, $totalUsers - 3), max(3, $totalUsers - 2), max(4, $totalUsers - 1), $totalUsers])
                ->color($bannedUsers > 0 ? 'warning' : 'primary'),

            Stat::make('Active Sessions', (string) $activeSessions)
                ->description('Logged in client devices')
                ->descriptionIcon('heroicon-m-device-phone-mobile')
                ->chart([max(1, $activeSessions - 2), $activeSessions + 1, max(1, $activeSessions - 1), $activeSessions, $activeSessions])
                ->color('success'),

            Stat::make('OAuth Applications', (string) $oauthApps)
                ->description('Connected client systems')
                ->descriptionIcon('heroicon-m-key')
                ->chart([1, max(1, $oauthApps - 2), max(1, $oauthApps - 1), $oauthApps, $oauthApps])
                ->color('info'),

            Stat::make('Webhook Sync Rate', $deliveryRate)
                ->description("{$deliveriesSuccess}/{$deliveriesTotal} delivered events")
                ->descriptionIcon('heroicon-m-signal')
                ->chart([92, 95, 98, 99, (int) rtrim($deliveryRate, '%')])
                ->color($deliveryRate === '100%' ? 'success' : 'warning'),
        ];
    }
}
