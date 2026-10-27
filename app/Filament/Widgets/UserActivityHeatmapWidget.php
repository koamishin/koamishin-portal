<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\User;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Spatie\Activitylog\Models\Activity;

class UserActivityHeatmapWidget extends Widget
{
    protected string $view = 'filament.widgets.user-activity-heatmap-widget';

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    #[Locked]
    public ?Model $record = null;

    protected int $days = 365;

    /**
     * @return array{data: Collection<string, int>, max: int, total: int}
     */
    public function getData(): array
    {
        if (! $this->record instanceof User) {
            return [
                'data' => collect(),
                'max' => 1,
                'total' => 0,
            ];
        }

        $userId = $this->record->id;
        $driver = DB::getDriverName();
        $dateExpression = match ($driver) {
            'oracle' => 'TRUNC(created_at)',
            default => 'DATE(created_at)',
        };

        $activityModel = config('activitylog.activity_model') ?? Activity::class;

        $results = $activityModel::query()
            ->select(
                DB::raw("$dateExpression as date"),
                DB::raw('COUNT(*) as count')
            )
            ->where(function ($q) use ($userId): void {
                $q->where(function ($sub) use ($userId): void {
                    $sub->where('subject_type', User::class)
                        ->where('subject_id', $userId);
                })->orWhere(function ($sub) use ($userId): void {
                    $sub->where('causer_type', User::class)
                        ->where('causer_id', $userId);
                });
            })
            ->where('created_at', '>=', now()->subDays($this->days))
            ->groupBy(DB::raw($dateExpression))
            ->get()
            ->pluck('count', 'date');

        return [
            'data' => $results,
            'max' => (int) ($results->max() ?: 1),
            'total' => (int) $results->sum(),
        ];
    }
}
