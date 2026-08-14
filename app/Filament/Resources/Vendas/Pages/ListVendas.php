<?php

namespace App\Filament\Resources\Vendas\Pages;

use App\Filament\Pages\OperarVenda;
use App\Filament\Resources\Vendas\VendaResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListVendas extends ListRecords
{
    protected static string $resource = VendaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('nova_venda')
                ->label('Nova venda')
                ->icon('heroicon-o-plus')
                ->url(OperarVenda::getUrl()),
        ];
    }
}
