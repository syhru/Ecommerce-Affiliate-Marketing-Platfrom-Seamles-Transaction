<?php

namespace App\Filament\Resources\TrackingLogResource\Pages;

use App\Filament\Resources\TrackingLogResource;
use Filament\Resources\Pages\ViewRecord;

class ViewTrackingLog extends ViewRecord
{
    protected static string $resource = TrackingLogResource::class;
    protected function getHeaderActions(): array
    {
        return [];
    }
}
