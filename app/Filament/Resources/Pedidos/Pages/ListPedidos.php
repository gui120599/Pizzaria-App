<?php

namespace App\Filament\Resources\Pedidos\Pages;

use App\Enums\StatusPedidoEnum;
use App\Filament\Exports\PedidoExporter;
use App\Filament\Pages\AtenderPedido;
use App\Filament\Resources\Pedidos\PedidoResource;
use App\Models\Pedido;
use Filament\Actions\Action;
use Filament\Actions\ExportAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ListPedidos extends ListRecords
{
    protected static string $resource = PedidoResource::class;

    /** Janela das abas de histórico quando ninguém escolheu um período nem buscou. */
    public const PERIODO_PADRAO_DIAS = 7;

    /** Abas de histórico: crescem sem parar, então abrem só nos últimos dias. */
    private const ABAS_COM_JANELA = ['concluidos', 'cancelados', 'todos'];

    protected function getHeaderActions(): array
    {
        return [
            // Exporta a aba e os filtros que estão na tela; o arquivo vai para o disco privado.
            ExportAction::make()
                ->label('Exportar')
                ->exporter(PedidoExporter::class)
                ->authorize('export', Pedido::class),
            Action::make('novo_pedido')
                ->label('Novo pedido')
                ->icon('heroicon-o-plus')
                ->url(AtenderPedido::getUrl()),
        ];
    }

    /**
     * Rascunhos (INICIADO) e excluídos ficam fora das abas de pedido. O
     * PedidoResource tira o SoftDeletingScope, então cada aba decide sozinha.
     *
     * Pendências é o que pede decisão de alguém (entregue sem receber, ou
     * parado além do SLA): o Admin ou o Gerente sanea por aqui, com as ações
     * da própria linha.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'andamento' => Tab::make('Em andamento')
                ->icon(Heroicon::OutlinedClock)
                ->badge(fn (): int => Pedido::query()->emAndamento()->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->withoutTrashed()->emAndamento()),
            'pendencias' => Tab::make('Pendências')
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->badge(fn (): ?int => Pedido::query()->pendencias()->count() ?: null)
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->withoutTrashed()->pendencias()),
            'concluidos' => Tab::make('Concluídos')
                ->icon(Heroicon::OutlinedCheckBadge)
                ->modifyQueryUsing(fn (Builder $query): Builder => $this->comJanelaPadrao($query
                    ->withoutTrashed()
                    ->where(fn (Builder $concluido) => $concluido
                        ->where('pedidos.pedido_status', StatusPedidoEnum::FINALIZADO->value)
                        ->orWhere(fn (Builder $entregue) => $entregue->where('pedidos.pedido_status', StatusPedidoEnum::ENTREGUE->value)->pagos())))),
            'cancelados' => Tab::make('Cancelados')
                ->icon(Heroicon::OutlinedXCircle)
                ->modifyQueryUsing(fn (Builder $query): Builder => $this->comJanelaPadrao($query
                    ->withoutTrashed()
                    ->where('pedidos.pedido_status', StatusPedidoEnum::CANCELADO->value))),
            'todos' => Tab::make('Todos')
                ->modifyQueryUsing(fn (Builder $query): Builder => $this->comJanelaPadrao($query->withoutTrashed()->reais())),
            'rascunhos' => Tab::make('Rascunhos')
                ->badge(fn (): ?int => Pedido::query()->where('pedido_status', StatusPedidoEnum::INICIADO->value)->count() ?: null)
                ->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->withoutTrashed()->where('pedidos.pedido_status', StatusPedidoEnum::INICIADO->value)),
            'lixeira' => Tab::make('Lixeira')
                ->icon(Heroicon::OutlinedTrash)
                ->visible(fn (): bool => Auth::user()?->can('restoreAny', Pedido::class) ?? false)
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->onlyTrashed()),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'todos';
    }

    /**
     * A janela padrão está valendo? Só nas abas de histórico, sem período
     * escolhido e sem busca: quem digita o número de um pedido antigo o
     * encontra, e quem escolhe as datas manda no período.
     */
    public function janelaPadraoAtiva(): bool
    {
        return in_array($this->activeTab, self::ABAS_COM_JANELA, true)
            && ! $this->periodoEscolhido()
            && blank($this->getTableSearch());
    }

    private function periodoEscolhido(): bool
    {
        return filled(data_get($this->tableFilters, 'periodo.de')) || filled(data_get($this->tableFilters, 'periodo.ate'));
    }

    private function comJanelaPadrao(Builder $query): Builder
    {
        return $this->janelaPadraoAtiva()
            ? $query->abertoEntre(today()->subDays(self::PERIODO_PADRAO_DIAS - 1), null)
            : $query;
    }
}
