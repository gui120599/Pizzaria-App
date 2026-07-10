<?php

namespace App\Filament\Resources\Lancamentos\Pages;

use App\Enums\StatusLancamento;
use App\Filament\Resources\Lancamentos\LancamentoResource;
use App\Models\Lancamento;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListLancamentos extends ListRecords
{
    protected static string $resource = LancamentoResource::class;

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
                ->badge(Lancamento::count()),

            'pagar' => Tab::make('A Pagar')
                ->modifyQueryUsing(fn (Builder $query) => $query->pagar())
                ->badge(Lancamento::pagar()->count())
                ->badgeColor('danger'),

            'receber' => Tab::make('A Receber')
                ->modifyQueryUsing(fn (Builder $query) => $query->receber())
                ->badge(Lancamento::receber()->count())
                ->badgeColor('success'),

            'vencidos' => Tab::make('Vencidos')
                ->modifyQueryUsing(fn (Builder $query) => $query->vencidos())
                ->badge(Lancamento::vencidos()->count())
                ->badgeColor('warning'),

            'pagos' => Tab::make('Pagos')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', StatusLancamento::Pago))
                ->badge(Lancamento::where('status', StatusLancamento::Pago)->count()),
        ];
    }
}
