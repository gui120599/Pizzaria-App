<?php

namespace App\Filament\Resources\Pedidos\Pages;

use App\Filament\Pages\AtenderPedido;
use App\Filament\Resources\Pedidos\PedidoResource;
use App\Filament\Support\PedidoExclusaoActions;
use App\Filament\Support\PedidoStatusActions;
use App\Filament\Support\PedidoStoneActions;
use App\Models\Pedido;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Pedido em modo somente leitura (qualquer perfil com view:pedido, o Caixa
 * inclusive). Pedido excluído só abre para quem pode restaurar.
 */
class ViewPedido extends ViewRecord
{
    protected static string $resource = PedidoResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        abort_if(
            $this->getRecord()->trashed() && ! (Auth::user()?->can('restoreAny', Pedido::class) ?? false),
            404,
        );
    }

    public function getTitle(): string
    {
        return 'Pedido #'.$this->getRecord()->id;
    }

    /**
     * Carregamento antecipado num só lugar: tudo que o infolist mostra (os
     * mesmos relacionamentos de PedidoStatusActions::verDetalhes() mais venda,
     * quem cancelou/excluiu e os itens removidos), para a contagem de consultas
     * não crescer com o número de itens.
     */
    protected function resolveRecord(int|string $key): Model
    {
        $relacoes = method_exists(Pedido::class, 'cupom') ? ['cupom'] : [];

        return Pedido::withTrashed()
            ->with([
                ...$relacoes,
                'cliente',
                'opcaoEntrega',
                'sessaoMesa.mesa',
                'garcom:id,name_first',
                'entregador:id,name_first',
                'usuarioCancelou:id,name_first',
                'usuarioExcluiu:id,name_first',
                'vendas',
                'pagamentosCombinados',
                'stonePedidos',
                'historicoStatus.usuario:id,name_first',
                'item_pedido_pedido_id' => fn ($query) => $query
                    ->with(['produto.categoria', 'adicionaisItemPedido.adicional', 'usuarioRemoveu:id,name_first']),
            ])
            ->findOrFail($key);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('editar')
                ->label('Editar')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->url(fn (Pedido $record): string => AtenderPedido::getUrl(['pedido' => $record]))
                ->visible(fn (Pedido $record): bool => ! $record->trashed()
                    && (Auth::user()?->can('update', $record) ?? false)
                    && ! in_array($record->pedido_status, AtenderPedido::STATUS_SOMENTE_LEITURA, true)),
            Action::make('imprimir')
                ->label('Imprimir')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->url(fn (Pedido $record): string => $record->linkImpressao())
                ->openUrlInNewTab()
                ->visible(fn (Pedido $record): bool => ! $record->trashed()),
            PedidoStoneActions::enviar(),
            PedidoStoneActions::reconsultar(),
            PedidoStoneActions::verCobranca(),
            PedidoStoneActions::cancelar(),
            PedidoStatusActions::cancelarDaTabela(),
            PedidoStatusActions::cancelarEntregueDaTabela(),
            PedidoExclusaoActions::excluir(verificacaoCompleta: true),
            PedidoExclusaoActions::restaurar(),
        ];
    }

    /** Depois de qualquer ação, relê o pedido: status, timeline e valores mudaram. */
    protected function afterActionCalled(Action $action): void
    {
        $this->record = $this->resolveRecord($this->getRecord()->getKey());
    }
}
