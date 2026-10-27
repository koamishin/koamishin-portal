<?php

declare(strict_types=1);

namespace App\Filament\Resources\Webhooks;

use App\Filament\Resources\Webhooks\Pages\CreateWebhookEndpoint;
use App\Filament\Resources\Webhooks\Pages\EditWebhookEndpoint;
use App\Filament\Resources\Webhooks\Pages\ListWebhookEndpoints;
use App\Jobs\DispatchWebhookJob;
use App\Models\WebhookEndpoint;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class WebhookEndpointResource extends Resource
{
    protected static ?string $model = WebhookEndpoint::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-signal';

    protected static \UnitEnum|string|null $navigationGroup = 'Authentication & API';

    protected static ?string $navigationLabel = 'Webhook Endpoints';

    protected static ?string $modelLabel = 'Webhook Endpoint';

    protected static ?string $pluralModelLabel = 'Webhook Endpoints';

    protected static ?int $navigationSort = 3;

    protected static bool $isScopedToTenant = false;

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Endpoint Configuration')
                    ->components([
                        TextInput::make('name')
                            ->label('Name / Application')
                            ->placeholder('e.g. CRM Sync, Billing Webhook')
                            ->required()
                            ->maxLength(255),

                        TextInput::make('url')
                            ->label('Endpoint URL')
                            ->placeholder('https://crm.company.internal/api/webhooks/auth')
                            ->url()
                            ->required()
                            ->maxLength(500),

                        TextInput::make('secret')
                            ->label('Signing Secret (HMAC-SHA256)')
                            ->helperText('Used to sign the svix-signature header. Generated automatically on creation.')
                            ->password()
                            ->revealable()
                            ->copyable()
                            ->maxLength(255),

                        CheckboxList::make('events')
                            ->label('Subscribed Events')
                            ->options([
                                'user.created' => 'user.created (When a user registers or is created)',
                                'user.updated' => 'user.updated (When profile, email, or roles change)',
                                'user.deleted' => 'user.deleted (When user account is deleted)',
                            ])
                            ->default(['user.created', 'user.updated', 'user.deleted'])
                            ->required(),

                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),

                        Textarea::make('description')
                            ->label('Notes')
                            ->maxLength(500),
                    ])->columns(1),
            ]);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Endpoint')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('url')
                    ->label('Destination URL')
                    ->limit(50)
                    ->copyable()
                    ->fontFamily('mono'),

                TextColumn::make('events')
                    ->label('Events')
                    ->badge()
                    ->separator(','),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),

                TextColumn::make('deliveries_count')
                    ->label('Deliveries')
                    ->counts('deliveries')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
            ->recordActions([
                Action::make('testPing')
                    ->label('Test Ping')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('info')
                    ->action(function (WebhookEndpoint $record): void {
                        try {
                            DispatchWebhookJob::dispatchSync($record, 'test.ping', [
                                'type' => 'test.ping',
                                'timestamp' => time(),
                                'data' => [
                                    'message' => 'Koamishin webhook test ping verified.',
                                    'endpoint' => $record->name,
                                ],
                            ]);

                            Notification::make()
                                ->title('Webhook Dispatched')
                                ->body('Test payload sent to '.$record->url)
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Delivery Failed')
                                ->body('Could not reach endpoint: '.$e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
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
            'index' => ListWebhookEndpoints::route('/'),
            'create' => CreateWebhookEndpoint::route('/create'),
            'edit' => EditWebhookEndpoint::route('/{record}/edit'),
        ];
    }
}
