<?php

namespace App\Filament\Resources\BannersCardapio\Pages;

use App\Filament\Resources\BannersCardapio\BannerCardapioResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBannersCardapio extends ListRecords
{
    protected static string $resource = BannerCardapioResource::class;

    protected static ?string $title = 'Banners do cardápio';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
