<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductResource\Pages;
use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?string $navigationLabel = 'Products';

    protected static ?string $navigationGroup = 'Products';

    protected static ?int $navigationSort = 1;

    // -------------------------------------------------------------------------
    // FORM
    // -------------------------------------------------------------------------

    public static function form(Form $form): Form
    {
        return $form->schema([

            Forms\Components\Section::make('Basic Information')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Product Name')
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (string $operation, $state, Forms\Set $set) {
                            if ($operation === 'create') {
                                $set('slug', Str::slug($state));
                            }
                        }),

                    Forms\Components\TextInput::make('slug')
                        ->label('Slug')
                        ->required()
                        ->unique(Product::class, 'slug', ignoreRecord: true)
                        ->maxLength(255)
                        ->helperText('Auto-generated from name. Edit only if needed.'),

                    Forms\Components\TextInput::make('brand')
                        ->label('Brand')
                        ->required()
                        ->maxLength(100),

                    Forms\Components\TextInput::make('type')
                        ->label('Type')
                        ->required()
                        ->maxLength(100)
                        ->helperText('e.g. Standard, Gold, Racing, Full System'),

                    Forms\Components\Select::make('category')
                        ->label('Category')
                        ->required()
                        ->options([
                            'motor'        => 'Motor',
                            'shockbreaker' => 'Shockbreaker',
                        ])
                        ->native(false),
                ]),

            Forms\Components\Section::make('Pricing & Stock')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('price')
                        ->label('Price (Rp)')
                        ->required()
                        ->numeric()
                        ->prefix('Rp')
                        ->minValue(0),

                    Forms\Components\TextInput::make('stock')
                        ->label('Stock')
                        ->required()
                        ->numeric()
                        ->minValue(0)
                        ->default(0),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Active / Visible')
                        ->default(true)
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Description & Specs')
                ->schema([
                    Forms\Components\Textarea::make('description')
                        ->label('Description')
                        ->rows(4)
                        ->nullable(),

                    Forms\Components\Textarea::make('technical_specs')
                        ->label('Technical Specifications')
                        ->rows(4)
                        ->nullable()
                        ->helperText('e.g. material, size, compatibility'),
                ]),

            Forms\Components\Section::make('Media')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('thumbnail_url')
                        ->label('Thumbnail URL')
                        ->url()
                        ->maxLength(500)
                        ->nullable()
                        ->helperText('Full URL of the product image'),

                    Forms\Components\TextInput::make('master_video_url')
                        ->label('Product Video URL')
                        ->url()
                        ->maxLength(500)
                        ->nullable()
                        ->helperText('YouTube / Vimeo URL'),
                ]),
        ]);
    }

    // -------------------------------------------------------------------------
    // TABLE
    // -------------------------------------------------------------------------

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('thumbnail_url')
                    ->label('Image')
                    ->square()
                    ->defaultImageUrl('https://via.placeholder.com/60x60?text=No+Image'),

                Tables\Columns\TextColumn::make('name')
                    ->label('Product Name')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('brand')
                    ->label('Brand')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('category')
                    ->label('Category')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'motor'        => 'warning',
                        'shockbreaker' => 'success',
                        default        => 'gray',
                    }),

                Tables\Columns\TextColumn::make('type')
                    ->label('Type')
                    ->searchable(),

                Tables\Columns\TextColumn::make('price')
                    ->label('Price')
                    ->money('IDR', locale: 'id')
                    ->sortable(),

                Tables\Columns\TextColumn::make('stock')
                    ->label('Stock')
                    ->sortable()
                    ->color(fn (int $state): string => $state === 0 ? 'danger' : ($state < 5 ? 'warning' : 'success')),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('d M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('category')
                    ->options([
                        'motor'        => 'Motor',
                        'shockbreaker' => 'Shockbreaker',
                    ]),

                Tables\Filters\SelectFilter::make('brand')
                    ->options(
                        Product::query()
                            ->distinct()
                            ->orderBy('brand')
                            ->pluck('brand', 'brand')
                            ->toArray()
                    ),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active Status')
                    ->trueLabel('Active Only')
                    ->falseLabel('Inactive Only'),

                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
                Tables\Actions\RestoreAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\RestoreBulkAction::make(),
                    Tables\Actions\ForceDeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    // -------------------------------------------------------------------------
    // PAGES
    // -------------------------------------------------------------------------

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit'   => Pages\EditProduct::route('/{record}/edit'),
            'view'   => Pages\ViewProduct::route('/{record}'),
        ];
    }

    // Soft delete support
    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->withTrashed();
    }
}
