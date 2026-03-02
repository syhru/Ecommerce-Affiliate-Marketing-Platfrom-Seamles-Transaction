<?php

namespace App\Filament\Resources;

use App\Filament\Resources\NotificationLogResource\Pages;
use App\Models\NotificationLog;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class NotificationLogResource extends Resource
{
    protected static ?string $model = NotificationLog::class;
    protected static ?string $navigationIcon  = 'heroicon-o-bell';
    protected static ?string $navigationLabel = 'Notification Logs';
    protected static ?string $navigationGroup = 'Logs & Monitoring';
    protected static ?int    $navigationSort  = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Notification Info')->columns(2)->schema([
                Forms\Components\Select::make('user_id')->label('User')
                    ->relationship('user', 'name')->searchable()->preload()->nullable(),
                Forms\Components\Select::make('order_id')->label('Order')
                    ->relationship('order', 'order_number')->searchable()->preload()->nullable(),
                Forms\Components\TextInput::make('channel')->disabled(),
                Forms\Components\TextInput::make('recipient')->label('Recipient (Chat ID)')->disabled(),
                Forms\Components\TextInput::make('message_type')->disabled(),
                Forms\Components\TextInput::make('status')->disabled(),
                Forms\Components\Textarea::make('message_content')->rows(5)->disabled()->columnSpanFull(),
                Forms\Components\Textarea::make('error_message')->rows(2)->disabled()->nullable()->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')->label('User')->searchable()->placeholder('—'),
                Tables\Columns\TextColumn::make('order.order_number')->label('Order #')->placeholder('—'),
                Tables\Columns\TextColumn::make('channel')->badge()->color('info'),
                Tables\Columns\TextColumn::make('message_type')->label('Type')->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'sent'   => 'success',
                        'failed' => 'danger',
                        default  => 'warning',
                    }),
                Tables\Columns\TextColumn::make('sent_at')->dateTime('d M Y H:i')->placeholder('—')->sortable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime('d M Y H:i')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    'queued' => 'Queued', 'sent' => 'Sent', 'failed' => 'Failed',
                ]),
                Tables\Filters\SelectFilter::make('channel')->options(['telegram' => 'Telegram']),
            ])
            ->actions([Tables\Actions\ViewAction::make()])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNotificationLogs::route('/'),
            'view'  => Pages\ViewNotificationLog::route('/{record}'),
        ];
    }
}
