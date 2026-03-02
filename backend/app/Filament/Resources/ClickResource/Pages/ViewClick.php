<?php

namespace App\Filament\Resources\ClickResource\Pages;

use App\Filament\Resources\ClickResource;
use Filament\Resources\Pages\ViewRecord;

class ViewClick extends ViewRecord
{
    protected static string $resource = ClickResource::class;
    protected function getHeaderActions(): array
    {
        return [];
    }
}
