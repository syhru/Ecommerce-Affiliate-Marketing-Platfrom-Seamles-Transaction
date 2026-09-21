<?php

namespace App\Filament\Resources\WithdrawalResource\Pages;

use App\Filament\Resources\WithdrawalResource;
use App\Models\AffiliateWithdrawal;
use App\Services\AffiliateService;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewWithdrawal extends ViewRecord
{
    protected static string $resource = WithdrawalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),

            // The canonical withdrawal decisions, reachable from the detail
            // page as well as the table. Both are idempotent and both leave
            // the balance untouched unless the withdrawal is still pending
            // (WS-03 §5.5 / AC-15..AC-18).
            Action::make('approve')
                ->label('Setujui')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Setujui Penarikan')
                ->modalDescription('Saldo sudah dipotong saat permintaan dibuat; menyetujui hanya menandai pencairan sebagai selesai.')
                ->action(function (AffiliateWithdrawal $record): void {
                    app(AffiliateService::class)->completeWithdrawal($record, auth()->id());

                    Notification::make()->title('Penarikan disetujui.')->success()->send();
                })
                ->visible(fn (AffiliateWithdrawal $record): bool => $record->status === AffiliateWithdrawal::STATUS_PENDING),

            Action::make('reject')
                ->label('Tolak')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Tolak Penarikan')
                ->modalDescription('Saldo akan dikembalikan ke affiliate tepat sekali.')
                ->form([
                    \Filament\Forms\Components\Textarea::make('reason')
                        ->label('Alasan Penolakan')
                        ->required()
                        ->rows(2),
                ])
                ->action(function (array $data, AffiliateWithdrawal $record): void {
                    app(AffiliateService::class)->rejectWithdrawal($record, $data['reason'], auth()->id());

                    Notification::make()->title('Penarikan ditolak. Saldo telah dikembalikan.')->success()->send();
                })
                ->visible(fn (AffiliateWithdrawal $record): bool => $record->status === AffiliateWithdrawal::STATUS_PENDING),
        ];
    }
}
