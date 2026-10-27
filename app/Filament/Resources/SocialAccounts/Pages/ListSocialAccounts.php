<?php

declare(strict_types=1);

namespace App\Filament\Resources\SocialAccounts\Pages;

use App\Filament\Resources\SocialAccounts\SocialAccountResource;
use Filament\Resources\Pages\ListRecords;

class ListSocialAccounts extends ListRecords
{
    protected static string $resource = SocialAccountResource::class;
}
