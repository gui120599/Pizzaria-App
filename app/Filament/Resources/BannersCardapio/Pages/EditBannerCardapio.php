<?php

namespace App\Filament\Resources\BannersCardapio\Pages;

use App\Filament\Resources\BannersCardapio\BannerCardapioResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditBannerCardapio extends EditRecord
{
    protected static string $resource = BannerCardapioResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            RestoreAction::make(),
            ForceDeleteAction::make(),
        ];
    }
}
