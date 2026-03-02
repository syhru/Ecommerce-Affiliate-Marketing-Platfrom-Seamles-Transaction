<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WithdrawalResource\Pages;
use App\Models\AffiliateWithdrawal;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class WithdrawalResource extends Resource
{
    protected static ?string $model = AffiliateWithdrawal::class;
    protected static ?string $navigationIcon  = 'heroicon-o-arrow-up-tray';
    protected static ?string $navigationLabel = 'Withdrawals';
    protected static ?string $navigationGroup = 'Affiliate System';
    protected static ?int    $navigationSort  = 3;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Withdrawal Info')->columns(2)->schema([
                Forms\Components\Select::make('affiliate_id')->label('Affiliate')
                    ->relationship('affiliate', 'name')->searchable()->preload()->required(),
                Forms\Components\TextInput::make('amount')->numeric()->prefix('Rp')->required(),
                Forms\Components\Select::make('status')->options([
                    'pending'   => 'Pending',
                    'completed' => 'Completed',
                    'rejected'  => 'Rejected',
                ])->required()->native(false),
                Forms\Components\DateTimePicker::make('processed_at')->nullable(),
                Forms\Components\Textarea::make('rejection_reason')->rows(2)->nullable(),
                Forms\Components\Textarea::make('notes')->rows(2)->nullable(),
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
                Tables\Columns\TextColumn::make('affiliate.name')->label('Affiliate')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('amount')->money('IDR', locale: 'id')->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'completed' => 'success',
                        'rejected'  => 'danger',
                        default     => 'warning',
                    }),
                Tables\Columns\TextColumn::make('bank_name')->label('Bank')->placeholder('—'),
                Tables\Columns\TextColumn::make('bank_account_number')->label('Account No')->placeholder('—'),
                Tables\Columns\TextColumn::make('processed_at')->dateTime('d M Y')->placeholder('—')->sortable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime('d M Y')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    'pending' => 'Pending', 'completed' => 'Completed', 'rejected' => 'Rejected',
                ]),
            ])
            ->actions([Tables\Actions\ViewAction::make(), Tables\Actions\EditAction::make()])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWithdrawals::route('/'),
            'view'  => Pages\ViewWithdrawal::route('/{record}'),
            'edit'  => Pages\EditWithdrawal::route('/{record}/edit'),
        ];
    }
}
