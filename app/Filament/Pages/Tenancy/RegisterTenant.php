<?php

declare(strict_types=1);

namespace App\Filament\Pages\Tenancy;

use App\Models\Tenant;
use App\Models\User;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\RegisterTenant as BaseRegisterTenant;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class RegisterTenant extends BaseRegisterTenant
{
    public static function getLabel(): string
    {
        return 'Register Organization';
    }

    #[\Override]
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Organization Name')
                    ->placeholder('e.g. Acme Corporation, Koamishin Labs')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn ($state, callable $set) => $set('slug', Str::slug((string) $state))),

                TextInput::make('slug')
                    ->label('Organization Slug')
                    ->required()
                    ->unique(Tenant::class, 'slug')
                    ->maxLength(100),

                TextInput::make('domain')
                    ->label('Custom Domain (Optional)')
                    ->placeholder('e.g. auth.acme.com')
                    ->unique(Tenant::class, 'domain')
                    ->maxLength(255),
            ]);
    }

    #[\Override]
    protected function handleRegistration(array $data): Tenant
    {
        /** @var Tenant $tenant */
        $tenant = Tenant::query()->create([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'domain' => $data['domain'] ?? null,
            'is_active' => true,
        ]);

        /** @var User $user */
        $user = Auth::user();
        $tenant->users()->attach($user, ['role' => 'owner']);

        return $tenant;
    }
}
