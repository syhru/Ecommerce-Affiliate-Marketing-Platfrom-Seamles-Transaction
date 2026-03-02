<?php

namespace App\Filament\Resources\AffiliateResource\Pages;

use App\Filament\Resources\AffiliateResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAffiliate extends EditRecord
{
    protected static string $resource = AffiliateResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\ViewAction::make(), Actions\DeleteAction::make()];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function afterSave(): void
    {
        $profile = $this->record;
        $user = $profile->user;

        if (!$user) {
            return;
        }

        // Jika status affiliate diubah menjadi 'active' → role user = affiliate
        if ($profile->status === 'active') {
            if ($user->role !== 'affiliate') {
                $user->update(['role' => 'affiliate']);
            }
            if (!$profile->approved_at) {
                $profile->update([
                    'approved_at' => now(),
                    'approved_by' => auth()->id(),
                ]);
            }
        }

        // Jika status affiliate diubah menjadi 'inactive' → role user = customer
        if ($profile->status === 'inactive') {
            if ($user->role === 'affiliate') {
                $user->update(['role' => 'customer']);
            }
        }

        // Jika status affiliate diubah menjadi 'rejected' → role user = customer
        if ($profile->status === 'rejected') {
            if ($user->role === 'affiliate') {
                $user->update(['role' => 'customer']);
            }
        }
    }
}
