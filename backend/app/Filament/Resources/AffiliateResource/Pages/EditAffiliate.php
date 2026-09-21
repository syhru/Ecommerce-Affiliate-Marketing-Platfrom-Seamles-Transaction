<?php

namespace App\Filament\Resources\AffiliateResource\Pages;

use App\Filament\Resources\AffiliateResource;
use App\Models\AffiliateProfile;
use App\Services\AffiliateService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAffiliate extends EditRecord
{
    protected static string $resource = AffiliateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\Action::make('approve')
                ->label('Approve')->requiresConfirmation()
                ->visible(fn () => $this->record->status === AffiliateProfile::STATUS_PENDING)
                ->action(fn () => app(AffiliateService::class)->transition($this->record, AffiliateProfile::STATUS_ACTIVE)),
            Actions\Action::make('reject')
                ->label('Reject')->requiresConfirmation()->color('danger')
                ->visible(fn () => $this->record->status === AffiliateProfile::STATUS_PENDING)
                ->action(fn () => app(AffiliateService::class)->transition($this->record, AffiliateProfile::STATUS_REJECTED)),
            Actions\Action::make('deactivate')
                ->label('Deactivate')->requiresConfirmation()->color('warning')
                ->visible(fn () => $this->record->status === AffiliateProfile::STATUS_ACTIVE)
                ->action(fn () => app(AffiliateService::class)->transition($this->record, AffiliateProfile::STATUS_INACTIVE)),
            Actions\Action::make('reactivate')
                ->label('Reactivate')->requiresConfirmation()
                ->visible(fn () => $this->record->status === AffiliateProfile::STATUS_INACTIVE)
                ->action(fn () => app(AffiliateService::class)->transition($this->record, AffiliateProfile::STATUS_ACTIVE)),
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

}
