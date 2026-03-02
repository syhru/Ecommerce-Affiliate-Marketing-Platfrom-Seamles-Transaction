<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TrackingLogResource\Pages;
use App\Models\TrackingLog;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class TrackingLogResource extends Resource
{
    protected static ?string $model = TrackingLog::class;
    protected static ?string $navigationIcon  = 'heroicon-o-map-pin';
    protected static ?string $navigationLabel = 'Tracking Logs';
    protected static ?string $navigationGroup = 'Logs & Monitoring';
    protected static ?int    $navigationSort  = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Tracking Info')->columns(2)->schema([
                Forms\Components\Select::make('order_id')->label('Order')
                    ->relationship('order', 'order_number')->searchable()->preload()->required(),
                Forms\Components\TextInput::make('status_title')->label('Status Title')->required(),
                Forms\Components\Textarea::make('description')->rows(3)->nullable()->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('order.order_number')->label('Order #')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('status_title')->label('Status')->searchable(),
                Tables\Columns\TextColumn::make('description')->limit(60)->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')->dateTime('d M Y H:i')->label('Time')->sortable(),
            ])
            ->filters([])
            ->actions([Tables\Actions\ViewAction::make()])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTrackingLogs::route('/'),
            'view'  => Pages\ViewTrackingLog::route('/{record}'),
        ];
    }
}
