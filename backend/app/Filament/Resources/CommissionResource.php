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
                // The commission lifecycle is owned exclusively by
                // AffiliateService::earnCommission() and cancelCommission(),
                // which credit balance/total_earned and void the pending
                // commission atomically. A bare status edit here could drive
                // `pending → earned` with no credit, so the lifecycle fields
                // are read-only. `disabled()` also dehydrates them out of the
                // submitted data, so they cannot be persisted through this
                // form. There is no admin commission action in WS-03, so no
                // alternate update path is offered (WS-03 §3.2 / R3-F-02).
                Forms\Components\Select::make('status')->disabled()->options([
                    'pending'   => 'Pending',
                    'earned'    => 'Earned',
                    'cancelled' => 'Cancelled',
                    'withdrawn' => 'Withdrawn',
                ])->required()->native(false),
                Forms\Components\DateTimePicker::make('earned_at')->nullable()->disabled(),
                Forms\Components\DateTimePicker::make('cancelled_at')->nullable()->disabled(),
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
                        'cancelled' => 'danger',
                        'withdrawn' => 'info',
                        default     => 'warning',
                    }),
                Tables\Columns\TextColumn::make('earned_at')->dateTime('d M Y')->placeholder('—')->sortable(),
                Tables\Columns\TextColumn::make('cancelled_at')->dateTime('d M Y')->placeholder('—')->sortable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')->dateTime('d M Y')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    'pending' => 'Pending', 'earned' => 'Earned',
                    'cancelled' => 'Cancelled', 'withdrawn' => 'Withdrawn',
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
