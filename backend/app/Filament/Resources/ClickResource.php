<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ClickResource\Pages;
use App\Models\AffiliateClick;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ClickResource extends Resource
{
    protected static ?string $model = AffiliateClick::class;
    protected static ?string $navigationIcon  = 'heroicon-o-cursor-arrow-rays';
    protected static ?string $navigationLabel = 'Referral Clicks';
    protected static ?string $navigationGroup = 'Affiliate System';
    protected static ?int    $navigationSort  = 4;

    // Read-only resource — no create/edit needed
    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Click Info')->columns(2)->schema([
                Forms\Components\Select::make('affiliate_id')->label('Affiliate')
                    ->relationship('affiliate', 'name')->searchable()->preload()->disabled(),
                Forms\Components\TextInput::make('ip_address')->label('IP Address')->disabled(),
                Forms\Components\TextInput::make('referrer_url')->label('Referrer URL')->disabled(),
                Forms\Components\DateTimePicker::make('clicked_at')->disabled(),
                Forms\Components\Textarea::make('user_agent')->label('User Agent')->rows(3)->disabled(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('affiliate.name')->label('Affiliate')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('ip_address')->label('IP Address'),
                Tables\Columns\TextColumn::make('referrer_url')->label('Referrer')->limit(40)->placeholder('—'),
                Tables\Columns\TextColumn::make('clicked_at')->dateTime('d M Y H:i')->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('affiliate_id')->label('Affiliate')
                    ->relationship('affiliate', 'name'),
            ])
            ->actions([Tables\Actions\ViewAction::make()])
            ->defaultSort('clicked_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListClicks::route('/'),
            'view'  => Pages\ViewClick::route('/{record}'),
        ];
    }
}
