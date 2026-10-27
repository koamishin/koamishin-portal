<x-filament-widgets::widget>
    @php
        $heatmap = $this->getData();
        $data = $heatmap['data'];
        $max = $heatmap['max'];
        $total = $heatmap['total'];
        $startDate = now()->subDays(365)->startOfWeek();
        $endDate = now();

        $months = [];
        $currentMonth = $startDate->format('M');
        $months[] = ['name' => $currentMonth, 'week_index' => 0];
        $lastLabelWeek = 0;

        $dt = $startDate->copy();
        for ($weekIndex = 0; $weekIndex < 52; $weekIndex++) {
            $month = $dt->addWeek()->format('M');
            if ($month !== $currentMonth) {
                if (($weekIndex - $lastLabelWeek) >= 4) {
                    $months[] = ['name' => $month, 'week_index' => $weekIndex];
                    $lastLabelWeek = $weekIndex;
                    $currentMonth = $month;
                }
            }
        }

        $cellSize = 11;
        $gap = 3;
    @endphp

    <div class="rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900 p-5 shadow-xs">
        <div class="flex items-center justify-between pb-3 border-b border-gray-100 dark:border-white/5 mb-3">
            <div class="flex items-center gap-2">
                <x-filament::icon icon="heroicon-m-calendar-days" class="w-4 h-4 text-primary-500" />
                <h3 class="text-sm font-bold text-gray-950 dark:text-white">Annual Activity Heatmap</h3>
            </div>
            <span class="text-xs text-gray-500 dark:text-gray-400 font-mono">
                {{ $total }} {{ \Illuminate\Support\Str::plural('event', $total) }} in last 365 days
            </span>
        </div>

        <div class="overflow-x-auto py-2">
            <!-- Month labels -->
            <div style="position: relative; height: 16px; margin-bottom: 6px; font-size: 10px; font-weight: 600; color: #9ca3af;">
                @foreach ($months as $month)
                    <span style="position: absolute; left: {{ $month['week_index'] * ($cellSize + $gap) }}px;">
                        {{ $month['name'] }}
                    </span>
                @endforeach
            </div>

            <!-- Heatmap grid -->
            <div style="
                display: grid;
                grid-template-rows: repeat(7, {{ $cellSize }}px);
                grid-auto-flow: column;
                gap: {{ $gap }}px;
                width: max-content;
            ">
                @foreach (range(0, 52) as $week)
                    @foreach (range(0, 6) as $day)
                        @php
                            $currentDate = $startDate->copy()->addWeeks($week)->addDays($day);
                            $dateString = $currentDate->toDateString();
                            $count = $data[$dateString] ?? 0;
                            $intensity = $count > 0 ? (int) ceil(($count / $max) * 4) : 0;

                            $bg = match ($intensity) {
                                0 => 'rgba(156, 163, 175, 0.15)',
                                1 => 'rgba(245, 158, 11, 0.35)',
                                2 => 'rgba(245, 158, 11, 0.60)',
                                3 => 'rgba(245, 158, 11, 0.85)',
                                4 => '#f59e0b',
                                default => 'rgba(156, 163, 175, 0.15)',
                            };

                            $tooltip = $count . ' ' . \Illuminate\Support\Str::plural('event', $count) . ' on ' . $currentDate->format('M j, Y');
                        @endphp

                        @if ($currentDate <= $endDate)
                            <div
                                title="{{ $tooltip }}"
                                style="
                                    width: {{ $cellSize }}px;
                                    height: {{ $cellSize }}px;
                                    background-color: {{ $bg }};
                                    border-radius: 2px;
                                    cursor: pointer;
                                    transition: transform 0.1s ease;
                                "
                                class="hover:scale-125"
                            ></div>
                        @endif
                    @endforeach
                @endforeach
            </div>

            <!-- Heatmap Legend -->
            <div class="flex items-center justify-end gap-1.5 mt-3 pt-2 text-[10px] text-gray-400">
                <span>Less</span>
                <span style="width: 10px; height: 10px; background: rgba(156, 163, 175, 0.15); border-radius: 2px;"></span>
                <span style="width: 10px; height: 10px; background: rgba(245, 158, 11, 0.35); border-radius: 2px;"></span>
                <span style="width: 10px; height: 10px; background: rgba(245, 158, 11, 0.60); border-radius: 2px;"></span>
                <span style="width: 10px; height: 10px; background: rgba(245, 158, 11, 0.85); border-radius: 2px;"></span>
                <span style="width: 10px; height: 10px; background: #f59e0b; border-radius: 2px;"></span>
                <span>More</span>
            </div>
        </div>
    </div>
</x-filament-widgets::widget>
