<?php

namespace App\Filament\Resources\WithdrawalResource\Pages;

use App\Filament\Resources\WithdrawalResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class EditWithdrawal extends EditRecord
{
    protected static string $resource = WithdrawalResource::class;
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record->update(Arr::only($data, ['notes']));

        return $record;
    }

    protected function getHeaderActions(): array
    {
        return [Actions\ViewAction::make()];
    }
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
