<?php

namespace App\Filament\Resources\AffiliateResource\Pages;

use App\Filament\Resources\AffiliateResource;
use App\Models\AffiliateProfile;
use App\Services\AffiliateService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class EditAffiliate extends EditRecord
{
    protected static string $resource = AffiliateResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $data = Arr::only($data, [
            'referral_code', 'commission_rate', 'bank_name',
            'bank_account_number', 'bank_account_holder',
        ]);

        // The repository defines numeric rates but no canonical business range.
        validator($data, ['commission_rate' => ['required', 'numeric']])->validate();
        $record->update($data);

        return $record;
    }

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
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

}
