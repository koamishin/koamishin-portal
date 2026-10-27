<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\SocialAccount;
use App\Models\User;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

class AuthMethodsChartWidget extends ChartWidget
{
    protected ?string $heading = 'Authentication Methods Breakdown';

    protected static ?int $sort = 3;

    #[\Override]
    protected function getData(): array
    {
        $passwords = User::whereNotNull('password')->count();
        $passkeys = DB::table('passkeys')->count();
        $github = SocialAccount::where('provider', 'github')->count();
        $google = SocialAccount::where('provider', 'google')->count();
        $facebook = SocialAccount::where('provider', 'facebook')->count();
        $mfa = User::whereNotNull('app_authentication_secret')
            ->orWhere('has_email_authentication', true)
            ->count();

        return [
            'datasets' => [
                [
                    'label' => 'Authentication Credentials',
                    'data' => [$passwords, $passkeys, $google, $github, $facebook, $mfa],
                    'backgroundColor' => [
                        '#3b82f6', // Passwords (blue)
                        '#10b981', // Passkeys (emerald)
                        '#ea4335', // Google (red)
                        '#24292f', // GitHub (dark)
                        '#1877f2', // Facebook (blue)
                        '#8b5cf6', // MFA (purple)
                    ],
                ],
            ],
            'labels' => ['Passwords', 'Passkeys', 'Google SSO', 'GitHub SSO', 'Facebook SSO', 'MFA Enabled'],
        ];
    }

    #[\Override]
    protected function getType(): string
    {
        return 'doughnut';
    }
}
