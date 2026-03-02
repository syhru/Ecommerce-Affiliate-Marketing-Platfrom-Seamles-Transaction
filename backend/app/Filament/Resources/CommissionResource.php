<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CommissionResource\Pages;
use App\Models\AffiliateCommission;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CommissionResource extends Resource
{
    protected static ?string $model = AffiliateCommission::class;
    protected static ?string $navigationIcon  = 'heroicon-o-banknotes';
    protected static ?string $navigationLabel = 'Commissions';
    protected static ?string $navigationGroup = 'Affiliate System';
    protected static ?int    $navigationSort  = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Commission Info')->columns(2)->schema([
                Forms\Components\Select::make('order_id')->label('Order')
                    ->relationship('order', 'order_number')->searchable()->preload()->required(),
                Forms\Components\Select::make('affiliate_id')->label('Affiliate')
                    ->relationship('affiliate', 'name')->searchable()->preload()->required(),
                Forms\Components\TextInput::make('amount')->numeric()->prefix('Rp')->required(),
                Forms\Components\TextInput::make('commission_rate')->label('Rate (%)')->numeric()->required(),
                Forms\Components\Select::make('status')->options([
                    'pending'   => 'Pending',
                    'earned'    => 'Earned',
                    'withdrawn' => 'Withdrawn',
                ])->required()->native(false),
                Forms\Components\DateTimePicker::make('earned_at')->nullable(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('order.order_number')->label('Order #')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('affiliate.name')->label('Affiliate')->searchable(),
                Tables\Columns\TextColumn::make('amount')->money('IDR', locale: 'id'),
                Tables\Columns\TextColumn::make('commission_rate')->label('Rate')->suffix('%'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'earned'    => 'success',
                        'withdrawn' => 'info',
                        default     => 'warning',
                    }),
                Tables\Columns\TextColumn::make('earned_at')->dateTime('d M Y')->placeholder('—')->sortable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime('d M Y')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    'pending' => 'Pending', 'earned' => 'Earned', 'withdrawn' => 'Withdrawn',
                ]),
            ])
            ->actions([Tables\Actions\ViewAction::make()])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCommissions::route('/'),
            'view'  => Pages\ViewCommission::route('/{record}'),
        ];
    }
}
