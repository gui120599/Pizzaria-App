<?php

namespace App\Filament\Resources\BannersCardapio\Pages;

use App\Filament\Resources\BannersCardapio\BannerCardapioResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBannerCardapio extends CreateRecord
{
    protected static string $resource = BannerCardapioResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
