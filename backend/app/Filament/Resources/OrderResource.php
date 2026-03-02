<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrderResource\Pages;
use App\Models\Order;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Infolists\Infolist;
use Filament\Infolists\Components;
use Filament\Support\Enums\FontWeight;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;
    protected static ?string $navigationIcon  = 'heroicon-o-shopping-cart';
    protected static ?string $navigationGroup = 'Orders';
    protected static ?int    $navigationSort  = 2;

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Components\Grid::make(['sm' => 1, 'md' => 3])
                    ->schema([
                        Components\Section::make('Info Pelanggan')
                            ->extraAttributes(['class' => 'h-full'])
                            ->schema([
                                Components\TextEntry::make('customer.name')->label('Nama'),
                                Components\TextEntry::make('customer.email')->label('Email'),
                                Components\TextEntry::make('affiliate.name')->label('Affiliate')->placeholder('—'),
                            ])->columnSpan(1),

                        Components\Section::make('Pembayaran')
                            ->extraAttributes(['class' => 'h-full'])
                            ->schema([
                                Components\TextEntry::make('payment_method')->label('Metode'),
                                Components\TextEntry::make('status')->badge()->label('Status Transaksi')
                                    ->color(fn (string $state): string => match ($state) {
                                        'paid'       => 'info',
                                        'processing' => 'warning',
                                        'shipped'    => 'primary',
                                        'completed'  => 'success',
                                        'cancelled'  => 'danger',
                                        default      => 'gray',
                                    }),
                                Components\TextEntry::make('payment_verified_at')->dateTime('d M Y H:i')->label('Waktu')->placeholder('Belum Dibayar'),
                                Components\TextEntry::make('midtrans_transaction_id')->label('ID Transaksi')->placeholder('—'),
                            ])->columnSpan(1),

                        Components\Section::make('Pengiriman')
                            ->extraAttributes(['class' => 'h-full'])
                            ->schema([
                                Components\TextEntry::make('shipping_courier')
                                    ->label('Ekspedisi')
                                    ->formatStateUsing(fn (?string $state) => strtoupper($state ?? '-'))
                                    ->placeholder('—'),
                                Components\TextEntry::make('shipping_tracking_number')->label('No Resi')->placeholder('—'),
                                Components\TextEntry::make('shipping_address')->label('Alamat')->columnSpanFull(),
                            ])->columnSpan(1),
                    ]),

                Components\Section::make('Item Pesanan')
                    ->schema([
                        Components\RepeatableEntry::make('items')
                            ->hiddenLabel()
                            ->schema([
                                Components\TextEntry::make('product.name')->label('Produk')->weight(FontWeight::Medium),
                                Components\TextEntry::make('quantity')->label('QTY'),
                                Components\TextEntry::make('price')->money('IDR', locale: 'id')->label('Harga'),
                                Components\TextEntry::make('subtotal')->money('IDR', locale: 'id')->label('Subtotal')->weight(FontWeight::Bold),
                            ])
                            ->columns(4),
                        Components\TextEntry::make('total_amount')
                            ->money('IDR', locale: 'id')
                            ->label('Total Keseluruhan')
                            ->size(Components\TextEntry\TextEntrySize::Large)
                            ->weight(FontWeight::Bold),
                    ]),

                Components\Section::make('Riwayat Status')
                    ->schema([
                        Components\RepeatableEntry::make('trackingLogs')
                            ->hiddenLabel()
                            ->schema([
                                Components\TextEntry::make('status_title')
                                    ->badge()
                                    ->color('primary')
                                    ->label('Event'),
                                Components\TextEntry::make('created_at')->dateTime('d M Y H:i')->label('Waktu'),
                                Components\TextEntry::make('description')->hiddenLabel()->columnSpanFull(),
                            ])
                            ->columns(2),
                    ]),
            ])
            ->columns(1);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Order Info')->columns(2)->schema([
                Forms\Components\TextInput::make('order_number')->disabled()->label('Order #'),
                Forms\Components\Select::make('customer_id')->label('Customer')
                    ->relationship('customer', 'name')->searchable()->preload()->required(),
                Forms\Components\Select::make('affiliate_id')->label('Affiliate (via referral)')
                    ->relationship('affiliate', 'name')->searchable()->preload()->nullable(),
                Forms\Components\Select::make('status')->required()->options([
                    'pending'    => 'Pending',
                    'paid'       => 'Paid',
                    'processing' => 'Processing',
                    'shipped'    => 'Shipped',
                    'completed'  => 'Completed',
                    'cancelled'  => 'Cancelled',
                ])->native(false),
                Forms\Components\TextInput::make('payment_method')->nullable(),
                Forms\Components\TextInput::make('midtrans_transaction_id')->label('Midtrans Txn ID')->nullable(),
            ]),
            Forms\Components\Section::make('Amounts')->columns(2)->schema([
                Forms\Components\TextInput::make('subtotal')->numeric()->prefix('Rp')->required(),
                Forms\Components\TextInput::make('shipping_cost')->numeric()->prefix('Rp')->default(0),
                Forms\Components\TextInput::make('commission_amount')->numeric()->prefix('Rp')->default(0),
                Forms\Components\TextInput::make('total_amount')->numeric()->prefix('Rp')->required(),
            ]),
            Forms\Components\Section::make('Shipping')->columns(2)->schema([
                Forms\Components\Textarea::make('shipping_address')->rows(3)->nullable(),
                Forms\Components\TextInput::make('shipping_courier')->nullable(),
                Forms\Components\TextInput::make('shipping_tracking_number')
                    ->readOnly()
                    ->nullable()
                    ->helperText('No. Resi dihasilkan otomatis oleh sistem saat pesanan dibuat.'),
            ]),
            Forms\Components\Section::make('Notes')->schema([
                Forms\Components\Textarea::make('notes')->rows(3)->nullable(),
                Forms\Components\Textarea::make('cancellation_reason')->rows(2)->nullable(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('order_number')->label('Order #')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('customer.name')->label('Customer')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'paid'       => 'info',
                        'processing' => 'warning',
                        'shipped'    => 'primary',
                        'completed'  => 'success',
                        'cancelled'  => 'danger',
                        default      => 'gray',
                    }),
                Tables\Columns\TextColumn::make('total_amount')->money('IDR', locale: 'id')->sortable(),
                Tables\Columns\TextColumn::make('payment_method')->label('Payment')->placeholder('—'),
                Tables\Columns\TextColumn::make('affiliate.name')->label('Affiliate')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime('d M Y H:i')->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    'pending' => 'Pending', 'paid' => 'Paid', 'processing' => 'Processing',
                    'shipped' => 'Shipped', 'completed' => 'Completed', 'cancelled' => 'Cancelled',
                ]),
            ])
            ->actions([Tables\Actions\ViewAction::make()])
            ->bulkActions([Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()])])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListOrders::route('/'),
            'view'   => Pages\ViewOrder::route('/{record}'),
        ];
    }
}
