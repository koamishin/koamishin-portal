<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Tenant;
use App\Models\TenantApplication;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

class DashboardHeroWidget extends Widget
{
    protected static bool $isLazy = false;

    protected string $view = 'filament.widgets.dashboard-hero-widget';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 0;

    public function getTenant(): ?Tenant
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Tenant ? $tenant : null;
    }

    public function getCurrentApplication(): ?TenantApplication
    {
        return TenantApplication::current($this->getTenant());
    }
}
