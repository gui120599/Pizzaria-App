<?php

namespace App\Filament\Resources\FechamentosCaixa\Pages;

use App\Enums\StatusFechamentoCaixa;
use App\Filament\Resources\FechamentosCaixa\FechamentoCaixaResource;
use App\Models\FechamentoCaixa;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListFechamentosCaixa extends ListRecords
{
    protected static string $resource = FechamentoCaixaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    public function getTabs(): array
    {
        return [
            'todos' => Tab::make('Todos')
                ->badge(FechamentoCaixa::count()),

            'rascunho' => Tab::make('Rascunho')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', StatusFechamentoCaixa::Rascunho))
                ->badge(FechamentoCaixa::where('status', StatusFechamentoCaixa::Rascunho)->count())
                ->badgeColor('warning'),

            'confirmados' => Tab::make('Confirmados')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', StatusFechamentoCaixa::Confirmado))
                ->badge(FechamentoCaixa::where('status', StatusFechamentoCaixa::Confirmado)->count())
                ->badgeColor('success'),
        ];
    }
}
