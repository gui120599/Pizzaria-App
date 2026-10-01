<?php

namespace App\Observers;

use App\Models\HistoricoStatusPedido;
use App\Models\Pedido;
use Illuminate\Support\Facades\Auth;

/**
 * Grava a timeline de status do pedido (ver migration de
 * historico_status_pedidos). Registrado ao lado de PedidoObserver em
 * EventServiceProvider — dois observers independentes no mesmo model, cada um
 * com uma responsabilidade (este só grava histórico, não mexe em estoque nem
 * comissão).
 *
 * Cobre todo caminho que usa save()/update() no model. Os três caminhos que
 * escrevem pedido_status por query builder (e por isso não disparam eventos
 * Eloquent) chamam HistoricoStatusPedido::registrar() diretamente:
 * EntregaService::aceitar(), ConfirmacoesPedidos::confirmar() e o
 * updateQuietly() de VendaObserver.
 */
class HistoricoStatusPedidoObserver
{
    public function created(Pedido $pedido): void
    {
        // Pedido criado sem informar pedido_status (ex.: Pedido::factory()->create([]),
        // comum em testes) deixa a coluna pro DEFAULT 'INICIADO' do schema do
        // MySQL — o Eloquent não reflete esse default de volta no objeto em
        // memória após o INSERT, então $pedido->pedido_status fica null aqui
        // mesmo com o banco já tendo gravado um valor. fresh() busca o que
        // realmente foi persistido antes de desistir de registrar.
        $status = $pedido->pedido_status ?? $pedido->fresh()?->pedido_status;

        if ($status === null) {
            return;
        }

        HistoricoStatusPedido::registrar(
            pedidoId: $pedido->id,
            de: null,
            para: $status,
            userId: Auth::id(),
        );
    }

    public function updated(Pedido $pedido): void
    {
        if (! $pedido->wasChanged('pedido_status')) {
            return;
        }

        // Defensivo, espelhando o guard de created(): pedido_status não deveria
        // virar null num update, mas um valor inesperado aqui não pode derrubar
        // a transição de verdade por causa só do registro de histórico.
        if ($pedido->pedido_status === null) {
            return;
        }

        HistoricoStatusPedido::registrar(
            pedidoId: $pedido->id,
            de: $pedido->getOriginal('pedido_status'),
            para: $pedido->pedido_status,
            userId: Auth::id(),
        );
    }
}
