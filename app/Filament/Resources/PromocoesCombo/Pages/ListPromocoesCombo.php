<?php

namespace App\Filament\Resources\PromocoesCombo\Pages;

use App\Filament\Resources\PromocoesCombo\PromocaoComboResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListPromocoesCombo extends ListRecords
{
    protected static string $resource = PromocaoComboResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('relatorio')
                ->label('Relatório de combos')
                ->icon(Heroicon::OutlinedChartBar)
                ->color('gray')
                ->url(PromocaoComboResource::getUrl('relatorio')),
            CreateAction::make(),
        ];
    }
}
