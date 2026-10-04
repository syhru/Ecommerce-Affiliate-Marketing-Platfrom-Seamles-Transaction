<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use App\Models\Order;
use App\Services\OrderService;
use Filament\Actions;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    public function getTitle(): string | \Illuminate\Contracts\Support\Htmlable
    {
        return 'Pesanan ' . $this->getRecord()->order_number;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('simulasi_pembayaran')
                ->label('Simulasi Webhook Settlement')
                ->color('warning')
                ->icon('heroicon-o-bolt')
                ->requiresConfirmation()
                ->modalHeading('Simulasi Pembayaran')
                ->modalDescription('Apakah Anda yakin ingin menyimulasikan pembayaran yang berhasil untuk pesanan ini?')
                ->action(function (Order $record) {
                    try {
                        app(OrderService::class)->simulatePayment($record);
                    } catch (\DomainException $exception) {
                        Notification::make()->title('Simulasi pembayaran tidak dapat diproses.')->danger()->send();
                        return;
                    }

                    Notification::make()->title('Simulasi pembayaran berhasil diproses.')->success()->send();
                })
                ->visible(fn (Order $record) => ! app()->environment('production') && $record->status === Order::STATUS_PENDING),

            Actions\Action::make('update_status')
                ->label('Update Status & Kirim Notifikasi')
                ->color('primary')
                ->icon('heroicon-o-paper-airplane')
                ->form([
                    \Filament\Forms\Components\Select::make('event')
                        ->label('Pilih Event')
                        ->options([
                            'order.processing' => 'Sedang Diproses',
                            'order.shipped'    => 'Pesanan Dikirim',
                            'order.delivered'  => 'Pesanan Selesai',
                            'order.cancelled'  => 'Pesanan Dibatalkan',
                        ])
                        ->required()
                        ->in(['order.processing', 'order.shipped', 'order.delivered', 'order.cancelled']),
                    \Filament\Forms\Components\TextInput::make('resi')
                        ->label('Nomor Resi')
                        ->visible(fn (\Filament\Forms\Get $get) => $get('event') === 'order.shipped'),
                    Textarea::make('cancellation_reason')
                        ->label('Alasan Pembatalan')
                        ->rows(2)
                        ->visible(fn (\Filament\Forms\Get $get) => $get('event') === 'order.cancelled'),
                ])
                ->action(function (array $data, Order $record): void {
                    $orderService = app(OrderService::class);

                    try {
                        if (! in_array($data['event'] ?? null, ['order.processing', 'order.shipped', 'order.delivered', 'order.cancelled'], true)) {
                            throw new \DomainException('Event pesanan tidak valid.');
                        }

                        // Completion and cancellation retain their canonical financial operations.
                        if ($data['event'] === 'order.delivered') {
                            $orderService->markCompleted($record);
                            Notification::make()
                                ->title('Pesanan diselesaikan. Komisi affiliate telah dikredit.')
                                ->success()->send();
                            return;
                        }

                        if ($data['event'] === 'order.cancelled') {
                            $orderService->cancelOrder($record, $data['cancellation_reason'] ?? null);
                            Notification::make()
                                ->title('Pesanan dibatalkan. Stok telah dikembalikan.')
                                ->success()->send();
                            return;
                        }

                        $orderService->advanceFulfilment($record, $data['event'], $data['resi'] ?? null);
                    } catch (\DomainException $exception) {
                        Notification::make()->title('Status pesanan tidak dapat diperbarui.')->danger()->send();
                        return;
                    }

                    Notification::make()->title('Status pesanan diperbarui dan notifikasi terkirim.')->success()->send();
                })
                ->visible(fn (Order $record) => in_array($record->status, [
                    Order::STATUS_VERIFIED,
                    Order::STATUS_PROCESSING,
                    Order::STATUS_SHIPPED,
                ])),
        ];
    }
}
