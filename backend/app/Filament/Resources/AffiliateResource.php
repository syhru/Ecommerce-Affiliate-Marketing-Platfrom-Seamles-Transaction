<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AffiliateResource\Pages;
use App\Models\AffiliateProfile;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AffiliateResource extends Resource
{
    protected static ?string $model = AffiliateProfile::class;
    protected static ?string $navigationIcon  = 'heroicon-o-user-group';
    protected static ?string $navigationLabel = 'Affiliates';
    protected static ?string $navigationGroup = 'Affiliate System';
    protected static ?int    $navigationSort  = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Affiliate Info')->columns(2)->schema([
                Forms\Components\Select::make('user_id')->label('User')
                    ->relationship('user', 'name')->searchable()->preload()->required(),
                Forms\Components\TextInput::make('referral_code')->required()->maxLength(50),
                Forms\Components\TextInput::make('commission_rate')->label('Commission Rate (%)')->numeric()->required()->default(5),
                Forms\Components\Select::make('status')->options([
                    'pending'  => 'Pending',
                    'active'   => 'Active',
                    'rejected' => 'Rejected',
                    'inactive' => 'Inactive',
                ])->required()->native(false),
                Forms\Components\TextInput::make('balance')->numeric()->prefix('Rp')->default(0),
                Forms\Components\TextInput::make('total_earned')->numeric()->prefix('Rp')->default(0),
            ]),
            Forms\Components\Section::make('Bank Info')->columns(2)->schema([
                Forms\Components\TextInput::make('bank_name')->nullable(),
                Forms\Components\TextInput::make('bank_account_number')->nullable(),
                Forms\Components\TextInput::make('bank_account_holder')->nullable(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')->label('Name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('user.email')->label('Email')->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('referral_code')->label('Code')->searchable(),
                Tables\Columns\TextColumn::make('commission_rate')->label('Rate')->suffix('%'),
                Tables\Columns\TextColumn::make('balance')->money('IDR', locale: 'id')->label('Balance'),
                Tables\Columns\TextColumn::make('total_earned')->money('IDR', locale: 'id')->label('Total Earned')->toggleable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active'   => 'success',
                        'rejected' => 'danger',
                        'inactive' => 'gray',
                        default    => 'warning',
                    }),
                Tables\Columns\TextColumn::make('approved_at')->dateTime('d M Y')->label('Approved')->placeholder('—')->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    'pending' => 'Pending', 'active' => 'Active',
                    'rejected' => 'Rejected', 'inactive' => 'Inactive',
                ]),
            ])
            ->actions([Tables\Actions\ViewAction::make(), Tables\Actions\EditAction::make()])
            ->bulkActions([Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()])])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListAffiliates::route('/'),
            'edit'   => Pages\EditAffiliate::route('/{record}/edit'),
            'view'   => Pages\ViewAffiliate::route('/{record}'),
        ];
    }
}
