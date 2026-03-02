<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use App\Models\Order;
use App\Services\NotificationService;
use Filament\Actions;
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
                    $record->update([
                        'status' => 'paid',
                        'midtrans_transaction_id' => 'SIMULATED-' . now()->timestamp,
                        'payment_verified_at' => now(),
                    ]);

                    $record->trackingLogs()->create([
                        'status_title' => 'Pembayaran Dikonfirmasi',
                        'description'  => 'Pembayaran telah diverifikasi via Simulasi Midtrans.',
                    ]);

                    app(NotificationService::class)->notifyOrderStatus($record, 'payment.confirmed');
                    Notification::make()->title('Simulasi pembayaran berhasil diproses.')->success()->send();
                })
                ->visible(fn (Order $record) => $record->status === 'pending'),

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
                ])
                ->action(function (array $data, Order $record): void {
                    $statusMap = [
                        'order.processing' => ['code' => 'processing', 'title' => 'Pesanan Diproses'],
                        'order.shipped'    => ['code' => 'shipped', 'title' => 'Pesanan Dikirim'],
                        'order.delivered'  => ['code' => 'completed', 'title' => 'Pesanan Selesai'],
                        'order.cancelled'  => ['code' => 'cancelled', 'title' => 'Pesanan Dibatalkan'],
                    ];

                    $actionData = $statusMap[$data['event']];
                    $updates = ['status' => $actionData['code']];
                    $descAddon = '';

                    // Always pull the resi to the updates if provided
                    if (!empty($data['resi'])) {
                        $updates['shipping_tracking_number'] = $data['resi'];
                        $descAddon .= " Resi: {$data['resi']}.";
                    }

                    if ($data['event'] === 'order.shipped') {
                        $updates['shipped_at'] = now();
                    }
                    if ($data['event'] === 'order.delivered') {
                        $updates['completed_at'] = now();
                    }
                    if ($data['event'] === 'order.cancelled') {
                        $updates['cancelled_at'] = now();
                    }

                    $record->update($updates);

                    $record->trackingLogs()->create([
                        'status_title' => $actionData['title'],
                        'description'  => "Status diperbarui secara manual via Panel Admin." . $descAddon,
                    ]);

                    app(NotificationService::class)->notifyOrderStatus($record, $data['event'], null);

                    Notification::make()->title('Status pesanan diperbarui dan notifikasi terkirim.')->success()->send();
                })
                ->visible(fn (Order $record) => in_array($record->status, ['paid', 'processing', 'shipped'])),
        ];
    }
}
