<?php

declare(strict_types=1);

namespace App\Filament\Resources\TenantApplications;

use App\Filament\Resources\TenantApplications\Pages\CreateTenantApplication;
use App\Filament\Resources\TenantApplications\Pages\EditTenantApplication;
use App\Filament\Resources\TenantApplications\Pages\ListTenantApplications;
use App\Filament\Resources\TenantApplications\Pages\ViewTenantApplication;
use App\Models\TenantApplication;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Layout\View;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TenantApplicationResource extends Resource
{
    protected static ?string $model = TenantApplication::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cube';

    protected static \UnitEnum|string|null $navigationGroup = 'Tenancy & Organizations';

    protected static ?string $navigationLabel = 'Applications';

    protected static ?string $modelLabel = 'Application';

    protected static ?string $pluralModelLabel = 'Applications';

    protected static ?int $navigationSort = 2;

    protected static bool $isScopedToTenant = true;

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Organization & Environment')
                    ->components([
                        Select::make('tenant_id')
                            ->label('Organization')
                            ->relationship('tenant', 'name')
                            ->default(fn (): mixed => Filament::getTenant()?->getKey())
                            ->disabled(fn (): bool => Filament::getTenant() !== null)
                            ->hidden(fn (): bool => Filament::getTenant() !== null)
                            ->dehydrated()
                            ->searchable()
                            ->preload(),

                        ToggleButtons::make('environment')
                            ->label('Environment')
                            ->options([
                                'development' => 'Development (Localhost / Sandbox)',
                                'production' => 'Production (Live)',
                            ])
                            ->colors([
                                'development' => 'warning',
                                'production' => 'success',
                            ])
                            ->icons([
                                'development' => 'heroicon-o-wrench-screwdriver',
                                'production' => 'heroicon-o-check-badge',
                            ])
                            ->default('development')
                            ->live()
                            ->afterStateUpdated(function (?string $state, Set $set, ?TenantApplication $record): void {
                                $allowSignups = $record instanceof TenantApplication
                                    ? $record->environments()->where('environment', $state)->first()?->getSetting('allow_signups', false) === true
                                    : $state === 'development';

                                $set('allow_signups', $allowSignups);
                            })
                            ->inline()
                            ->required(),

                        Toggle::make('allow_signups')
                            ->label('Allow self-service signup')
                            ->helperText('Allow new and existing Portal users to join this organization and application environment as members during signup or consent. Turn off to require administrator enrollment.')
                            ->default(fn (Get $get): bool => $get('environment') === 'development')
                            ->rules(['boolean']),

                        TextInput::make('name')
                            ->label('Application Name')
                            ->placeholder('e.g. Acme Billing, Internal CRM')
                            ->required()
                            ->maxLength(255),

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
                            ->live()
                            ->afterStateUpdated(function ($state, callable $set, callable $get): void {
                                $env = $get('environment');
                                if ($env === 'development') {
                                    $defaultCallback = match ($state) {
                                        'laravel' => 'http://localhost:8000/koamishin/callback',
                                        'nextjs' => 'http://localhost:3000/api/auth/callback',
                                        'vue', 'react' => 'http://localhost:5173/auth/callback',
                                        default => 'http://localhost:3000/callback',
                                    };
                                    $set('redirect_uris', [$defaultCallback]);
                                }
                            })
                            ->required(),

                        TagsInput::make('redirect_uris')
                            ->label('Allowed Callback URLs (Redirect URIs)')
                            ->helperText('Whitelisted URLs that the portal will redirect back to after signing in.')
                            ->placeholder('Add callback URL and press Enter')
                            ->default(['http://localhost:8000/koamishin/callback'])
                            ->required(),

                        Textarea::make('description')
                            ->label('Description / Notes')
                            ->maxLength(500),
                    ])->columns(2),
            ]);
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Application Credentials & Details')
                    ->components([
                        TextEntry::make('tenant.name')
                            ->label('Organization')
                            ->weight('bold'),

                        TextEntry::make('name')
                            ->label('Application Name'),

                        TextEntry::make('environment')
                            ->label('Environment')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'production' => 'success',
                                default => 'warning',
                            }),

                        TextEntry::make('framework')
                            ->label('Framework')
                            ->badge()
                            ->formatStateUsing(fn (TenantApplication $record): string => $record->getFrameworkLabel())
                            ->color('info'),

                        TextEntry::make('oauth_client_id')
                            ->label('Client ID')
                            ->fontFamily('mono')
                            ->copyable(),

                        TextEntry::make('redirect_uris')
                            ->label('Redirect URIs')
                            ->badge()
                            ->separator(','),
                    ])->columns(2),

                Section::make('Setup Guide (Clerk Workflow)')
                    ->description('Follow these tailored steps to connect your application to Koamishin Portal')
                    ->components([
                        TextEntry::make('install_command')
                            ->label('1. Install SDK Package')
                            ->state(fn (TenantApplication $record): string => $record->getInstallCommand())
                            ->fontFamily('mono')
                            ->copyable(),

                        TextEntry::make('env_snippet')
                            ->label('2. Environment Variables (.env)')
                            ->state(fn (TenantApplication $record): string => $record->getEnvSnippet())
                            ->fontFamily('mono')
                            ->copyable(),

                        TextEntry::make('boilerplate_code')
                            ->label('3. Quickstart Integration Code')
                            ->state(fn (TenantApplication $record): string => $record->getSetupGuideCode())
                            ->fontFamily('mono')
                            ->copyable(),
                    ])->columns(1),
            ]);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table
            ->contentGrid([
                'default' => 1,
                'md' => 2,
                'xl' => 3,
                '2xl' => 4,
            ])
            ->recordUrl(fn (TenantApplication $record): string => static::getUrl('view', ['record' => $record]))
            ->columns([
                View::make('filament.tables.columns.application-card'),
                TextColumn::make('name')
                    ->searchable()
                    ->hidden(),
                TextColumn::make('framework')
                    ->searchable()
                    ->hidden(),
            ])
            ->filters([
                SelectFilter::make('environment')
                    ->options([
                        'development' => 'Development',
                        'production' => 'Production',
                    ]),

                SelectFilter::make('framework')
                    ->options([
                        'laravel' => 'Laravel (PHP)',
                        'nextjs' => 'Next.js',
                        'vue' => 'Vue / Nuxt',
                        'react' => 'React',
                        'nodejs' => 'Node.js',
                        'vanilla' => 'Vanilla JS',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListTenantApplications::route('/'),
            'create' => CreateTenantApplication::route('/create'),
            'view' => ViewTenantApplication::route('/{record}'),
            'edit' => EditTenantApplication::route('/{record}/edit'),
        ];
    }
}
