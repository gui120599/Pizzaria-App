<?php

namespace App\Filament\Resources\Vendas\Pages;

use App\Filament\Pages\OperarVenda;
use App\Filament\Resources\Vendas\VendaResource;
use App\Models\Venda;
use App\Services\RelatorioVendasExportService;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListVendas extends ListRecords
{
    protected static string $resource = VendaResource::class;

    /**
     * O Filament abre o agrupamento padrão em ordem crescente (e só na montagem
     * inicial da tabela); aqui a sessão mais recente vem primeiro e o operador
     * pode inverter no seletor.
     */
    public function bootedInteractsWithTable(): void
    {
        $montagemInicial = $this->shouldMountInteractsWithTable;

        parent::bootedInteractsWithTable();

        if ($montagemInicial && $this->tableGrouping === 'venda_sessao_caixa_id:asc') {
            $this->tableGrouping = 'venda_sessao_caixa_id:desc';
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportarCsv')
                ->label('Exportar CSV (filtro atual)')
                ->icon('heroicon-o-table-cells')
                ->color('gray')
                ->visible(fn (): bool => auth()->user()?->can('view:relatorio_financeiro') ?? false)
                ->action(function (RelatorioVendasExportService $exportacao): StreamedResponse {
                    $linhas = $exportacao->linhas($this->getTableQueryForExport());

                    return response()->streamDownload(
                        fn () => $exportacao->escrever($linhas),
                        'vendas-'.now()->format('Ymd-His').'.csv',
                        ['Content-Type' => 'text/csv; charset=UTF-8'],
                    );
                }),

            Action::make('nova_venda')
                ->label('Nova venda')
                ->icon('heroicon-o-plus')
                ->url(OperarVenda::getUrl()),
        ];
    }

    /**
     * Visão rápida por situação da NFC-e (só vendas finalizadas, exceto "Todas").
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $finalizadas = fn (): Builder => Venda::query()->where('venda_status', 'FINALIZADA');

        return [
            'todas' => Tab::make('Todas'),

            'pendentes' => Tab::make('Pendentes de envio')
                ->icon('heroicon-o-clock')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->situacaoNfe('pendente'))
                ->badge(fn (): int => Cache::remember('vendas.aba.pendentes', 60, fn (): int => $finalizadas()->situacaoNfe('pendente')->count()))
                ->badgeColor('warning'),

            'falha' => Tab::make('Com falha')
                ->icon('heroicon-o-exclamation-triangle')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('venda_status', 'FINALIZADA')->situacaoNfe('falha'))
                ->badge(fn (): int => $finalizadas()->situacaoNfe('falha')->count())
                ->badgeColor('danger'),

            'andamento' => Tab::make('Em andamento')
                ->icon('heroicon-o-arrow-path')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('venda_status', 'FINALIZADA')->situacaoNfe('andamento'))
                ->badge(fn (): int => $finalizadas()->situacaoNfe('andamento')->count())
                ->badgeColor('warning'),

            'autorizadas' => Tab::make('Autorizadas')
                ->icon('heroicon-o-check-circle')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->situacaoNfe('autorizada')),

            'em_alteracao' => Tab::make('Em alteração')
                ->icon('heroicon-o-pencil-square')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->emAlteracao())
                ->badge(fn (): int => Venda::emAlteracao()->count())
                ->badgeColor('warning'),

            'canceladas' => Tab::make('Canceladas')
                ->icon('heroicon-o-x-circle')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('venda_status', 'FINALIZADA')->situacaoNfe('cancelada')),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'todas';
    }
}
