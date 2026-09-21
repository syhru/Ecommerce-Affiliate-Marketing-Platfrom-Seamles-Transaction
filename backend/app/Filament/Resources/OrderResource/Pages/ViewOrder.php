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
                    // Run the exact same flow as a real Midtrans settlement webhook:
                    // sets status => 'verified', payment_verified_at, midtrans_transaction_id,
                    // creates a tracking log, records a pending affiliate commission, and fires
                    // the Telegram notifications — keeping simulation and production in sync.
                    app(OrderService::class)->verifyPayment($record, 'SIMULATED-' . now()->timestamp);

                    Notification::make()->title('Simulasi pembayaran berhasil diproses.')->success()->send();
                })
                ->visible(fn (Order $record) => $record->status === Order::STATUS_PENDING),

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
                        ->required(),
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

                    // Fulfilment transitions are a pure status change, but the
                    // two financial transitions — completion and cancellation —
                    // must run through the canonical OrderService operations so
                    // commission earning, balance credit and stock restoration
                    // can never be bypassed by a bare model update (WS-03 §5.2,
                    // §5.3 / PA-F-08, PA-F-09).
                    if ($data['event'] === 'order.delivered') {
                        $orderService->markCompleted($record);

                        Notification::make()
                            ->title('Pesanan diselesaikan. Komisi affiliate telah dikredit.')
                            ->success()
                            ->send();

                        return;
                    }

                    if ($data['event'] === 'order.cancelled') {
                        $orderService->cancelOrder($record, $data['cancellation_reason'] ?? null);

                        Notification::make()
                            ->title('Pesanan dibatalkan. Stok telah dikembalikan.')
                            ->success()
                            ->send();

                        return;
                    }

                    $statusMap = [
                        'order.processing' => ['code' => Order::STATUS_PROCESSING, 'title' => 'Pesanan Diproses'],
                        'order.shipped'    => ['code' => Order::STATUS_SHIPPED,    'title' => 'Pesanan Dikirim'],
                    ];

                    $actionData = $statusMap[$data['event']];
                    $updates    = ['status' => $actionData['code']];
                    $descAddon  = '';

                    // Always pull the resi to the updates if provided
                    if (!empty($data['resi'])) {
                        $updates['shipping_tracking_number'] = $data['resi'];
                        $descAddon .= " Resi: {$data['resi']}.";
                    }

                    if ($data['event'] === 'order.shipped') {
                        $updates['shipped_at'] = now();
                    }

                    $record->update($updates);

                    $record->trackingLogs()->create([
                        'status_title' => $actionData['title'],
                        'description'  => "Status diperbarui oleh Admin." . $descAddon,
                    ]);

                    Notification::make()->title('Status pesanan diperbarui dan notifikasi terkirim.')->success()->send();
                })
                // No completed order can be touched here: completion and
                // cancellation are both terminal for this action.
                ->visible(fn (Order $record) => in_array($record->status, [
                    Order::STATUS_VERIFIED,
                    Order::STATUS_PROCESSING,
                    Order::STATUS_SHIPPED,
                ])),
        ];
    }
}
