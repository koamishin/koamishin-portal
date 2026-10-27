<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\User;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class UserSignupsChartWidget extends ChartWidget
{
    protected ?string $heading = 'User Registrations (Last 14 Days)';

    protected static ?int $sort = 2;

    #[\Override]
    protected function getData(): array
    {
        $days = collect(range(13, 0))->map(fn ($daysAgo) => Carbon::today()->subDays($daysAgo));

        $counts = $days->map(function (Carbon $date): int {
            return User::query()
                ->whereDate('created_at', $date->toDateString())
                ->count();
        });

        return [
            'datasets' => [
                [
                    'label' => 'New Users',
                    'data' => $counts->toArray(),
                    'fill' => 'start',
                    'borderColor' => '#f59e0b',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.15)',
                    'tension' => 0.3,
                ],
            ],
            'labels' => $days->map(fn (Carbon $date): string => $date->format('M j'))->toArray(),
        ];
    }

    #[\Override]
    protected function getType(): string
    {
        return 'line';
    }
}
