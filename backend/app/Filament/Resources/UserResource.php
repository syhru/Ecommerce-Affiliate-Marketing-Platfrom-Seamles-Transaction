<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class UserResource extends Resource
{
    protected static ?string $model = User::class;
    protected static ?string $navigationIcon  = 'heroicon-o-users';
    protected static ?string $navigationLabel = 'Users';
    protected static ?string $navigationGroup = 'User Management';
    protected static ?int    $navigationSort  = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Account Info')->columns(2)->schema([
                Forms\Components\TextInput::make('name')->required()->maxLength(255),
                Forms\Components\TextInput::make('email')->email()->required()->unique(User::class, 'email', ignoreRecord: true),
                Forms\Components\Select::make('role')
                    ->label('Role')
                    ->options([
                        'customer'  => 'Customer',
                        'affiliate' => 'Affiliate',
                    ])
                    ->required()
                    ->native(false),
                Forms\Components\TextInput::make('telegram_chat_id')->label('Telegram Chat ID')->nullable(),
                Forms\Components\Toggle::make('is_active')->label('Active')->default(true)
                    ->helperText('Jika nonaktif, user tidak dapat login.'),
                Forms\Components\TextInput::make('password')->password()->revealable()
                    ->dehydrateStateUsing(fn ($state) => filled($state) ? bcrypt($state) : null)
                    ->dehydrated(fn ($state) => filled($state))
                    ->required(fn (string $operation) => $operation === 'create')
                    ->label('Password'),
            ]),
        ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Account Info')->columns(2)->schema([
                Infolists\Components\TextEntry::make('name')->label('Nama'),
                Infolists\Components\TextEntry::make('email')->label('Email'),
                Infolists\Components\TextEntry::make('role')->label('Role')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'admin'     => 'danger',
                        'affiliate' => 'warning',
                        default     => 'success',
                    }),
                Infolists\Components\TextEntry::make('telegram_chat_id')->label('Telegram Chat ID')->placeholder('—'),
                Infolists\Components\IconEntry::make('is_active')->label('Active')->boolean(),
                Infolists\Components\TextEntry::make('created_at')->label('Dibuat Pada')->dateTime('d M Y H:i'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable()->label('#'),
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('email')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('role')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'admin'     => 'danger',
                        'affiliate' => 'warning',
                        default     => 'success',
                    }),
                Tables\Columns\IconColumn::make('is_active')->boolean()->label('Active'),
                Tables\Columns\TextColumn::make('telegram_chat_id')->label('Telegram ID')->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')->dateTime('d M Y')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('role')->options([
                    'admin' => 'Admin', 'affiliate' => 'Affiliate', 'customer' => 'Customer',
                ]),
                Tables\Filters\TernaryFilter::make('is_active')->label('Active Status'),
            ])
            ->actions([Tables\Actions\ViewAction::make(), Tables\Actions\DeleteAction::make()])
            ->bulkActions([Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()])])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'view'   => Pages\ViewUser::route('/{record}'),
            'edit'   => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
