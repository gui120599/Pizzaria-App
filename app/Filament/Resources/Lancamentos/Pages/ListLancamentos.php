<?php

namespace App\Filament\Resources\Lancamentos\Pages;

use App\Enums\StatusLancamento;
use App\Filament\Resources\Lancamentos\LancamentoResource;
use App\Models\Lancamento;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Builder;

class ListLancamentos extends ListRecords
{
    protected static string $resource = LancamentoResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    /**
     * Filtros, ordenação e busca já persistem na sessão pela própria tabela
     * (persist*InSession). A aba e o agrupamento escolhidos o Filament não
     * guarda sozinho — restauramos aqui, antes do mount padrão.
     */
    public function mount(): void
    {
        $this->activeTab ??= session($this->sessionKey('tab'));
        $this->tableGrouping ??= session($this->sessionKey('grouping'));

        parent::mount();
    }

    public function updatedActiveTab(): void
    {
        parent::updatedActiveTab();

        session()->put($this->sessionKey('tab'), $this->activeTab);
    }

    public function updatedTableGrouping(): void
    {
        session()->put($this->sessionKey('grouping'), $this->tableGrouping);
    }

    private function sessionKey(string $item): string
    {
        return 'tables.'.md5(static::class)."_{$item}";
    }

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

            'parciais' => Tab::make('Parciais')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', StatusLancamento::Parcial))
                ->badge(Lancamento::where('status', StatusLancamento::Parcial)->count())
                ->badgeColor('info'),

            'pagos' => Tab::make('Pagos')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', StatusLancamento::Pago))
                ->badge(Lancamento::where('status', StatusLancamento::Pago)->count()),
        ];
    }
}
