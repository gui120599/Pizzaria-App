<?php

namespace App\Filament\Resources\Contratos\Pages;

use App\Enums\StatusContrato;
use App\Filament\Resources\Contratos\ContratoResource;
use App\Models\Contrato;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListContratos extends ListRecords
{
    protected static string $resource = ContratoResource::class;

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
                ->badge(Contrato::count()),

            'ativos' => Tab::make('Ativos')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', StatusContrato::Ativo))
                ->badge(Contrato::where('status', StatusContrato::Ativo)->count())
                ->badgeColor('success'),

            'suspensos' => Tab::make('Suspensos')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', StatusContrato::Suspenso))
                ->badge(Contrato::where('status', StatusContrato::Suspenso)->count())
                ->badgeColor('warning'),

            'encerrados' => Tab::make('Encerrados')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', StatusContrato::Encerrado))
                ->badge(Contrato::where('status', StatusContrato::Encerrado)->count()),
        ];
    }
}
