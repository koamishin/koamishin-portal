<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tenants;

use App\Filament\Resources\Tenants\Pages\CreateTenant;
use App\Filament\Resources\Tenants\Pages\EditTenant;
use App\Filament\Resources\Tenants\Pages\ListTenants;
use App\Models\Tenant;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class TenantResource extends Resource
{
    protected static ?string $model = Tenant::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static \UnitEnum|string|null $navigationGroup = 'Tenancy & Organizations';

    protected static ?string $navigationLabel = 'Organizations (Tenants)';

    protected static ?string $modelLabel = 'Organization';

    protected static ?string $pluralModelLabel = 'Organizations';

    protected static ?int $navigationSort = 1;

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Organization Details')
                    ->components([
                        TextInput::make('name')
                            ->label('Organization Name')
                            ->placeholder('e.g. Acme Corporation, Koamishin Global')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function ($state, callable $set, string $context): void {
                                if ($context === 'create') {
                                    $set('slug', Str::slug((string) $state));
                                }
                            }),

                        TextInput::make('slug')
                            ->label('Identifier / Slug')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(100),

                        TextInput::make('domain')
                            ->label('Custom Domain (Optional)')
                            ->placeholder('e.g. auth.acme.com')
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),

                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                    ])->columns(2),
            ]);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Organization')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('slug')
                    ->label('Slug')
                    ->fontFamily('mono')
                    ->badge()
                    ->color('gray')
                    ->searchable(),

                TextColumn::make('domain')
                    ->label('Custom Domain')
                    ->placeholder('Default Portal')
                    ->searchable(),

                TextColumn::make('applications_count')
                    ->counts('applications')
                    ->label('Apps')
                    ->badge()
                    ->color('info'),

                TextColumn::make('users_count')
                    ->counts('users')
                    ->label('Members')
                    ->badge()
                    ->color('primary'),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Status')
                    ->placeholder('All Organizations')
                    ->trueLabel('Active')
                    ->falseLabel('Inactive'),
            ])
            ->recordActions([
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
            'index' => ListTenants::route('/'),
            'create' => CreateTenant::route('/create'),
            'edit' => EditTenant::route('/{record}/edit'),
        ];
    }
}
