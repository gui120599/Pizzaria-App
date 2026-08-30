<?php

namespace App\Livewire;

use App\Enums\PedidoOrigemEnum;
use App\Models\ItensPedido;
use App\Models\OpcoesEntregas;
use App\Models\OpcoesPagamento;
use App\Models\Pedido;
use App\Services\PromocaoRelampagoService;
use App\Support\TotaisPedido;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Confirmações de Pedidos')]
class ConfirmacoesPedidos extends Component
{
    public int $contagem = 0;

    public int $pedidoEditandoId = 0;

    public string $editOpcaoEntregaId = '';

    public string $editEndereco = '';

    public string $editPagamentoNome = '';

    public string $editObsPagamento = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('accept:pedido'), 403);
    }

    public function confirmar(int $id): void
    {
        // Checagem aqui (e não só na rota) porque chamadas Livewire subsequentes
        // vão direto pro endpoint de update do componente, sem passar pelo
        // middleware da rota /confirmacoes.
        abort_unless(auth()->user()->can('accept:pedido'), 403);

        Pedido::where('id', $id)
            ->where('pedido_status', 'INICIADO')
            ->update(['pedido_status' => 'ABERTO']);
    }

    public function cancelar(int $id): void
    {
        abort_unless(auth()->user()->can('reject:pedido'), 403);

        $pedido = Pedido::where('id', $id)->where('pedido_status', 'INICIADO')->first();
        if (! $pedido) {
            return;
        }

        DB::transaction(function () use ($pedido) {
            // Devolve ao saldo qualquer promoção relâmpago consumida pelos
            // itens deste pedido (checkout público) antes de cancelá-lo.
            app(PromocaoRelampagoService::class)->estornarPedido($pedido);

            $pedido->update([
                'pedido_status' => 'CANCELADO',
                'pedido_datahora_cancelado' => now(),
            ]);
        });
    }

    public function abrirEdicao(int $id): void
    {
        $pedido = Pedido::find($id);
        if (! $pedido) {
            return;
        }

        $this->pedidoEditandoId = $id;
        $this->editOpcaoEntregaId = (string) ($pedido->pedido_opcaoentrega_id ?? '');
        $this->editEndereco = $pedido->pedido_endereco_entrega ?? '';
        $this->editPagamentoNome = $pedido->pedido_descricao_pagamento ?? '';
        $this->editObsPagamento = $pedido->pedido_observacao_pagamento ?? '';
    }

    public function fecharEdicao(): void
    {
        $this->pedidoEditandoId = 0;
        $this->editOpcaoEntregaId = '';
        $this->editEndereco = '';
        $this->editPagamentoNome = '';
        $this->editObsPagamento = '';
    }

    public function salvarAlteracoes(): void
    {
        abort_unless(auth()->user()->can('accept:pedido'), 403);

        if (! $this->pedidoEditandoId) {
            return;
        }

        $pedido = Pedido::where('id', $this->pedidoEditandoId)
            ->where('pedido_status', 'INICIADO')
            ->first();

        if ($pedido) {
            $itens = ItensPedido::where('item_pedido_pedido_id', $pedido->id)
                ->where('item_pedido_status', 'INSERIDO')
                ->get();

            $opcao = $this->editOpcaoEntregaId ? OpcoesEntregas::find($this->editOpcaoEntregaId) : null;
            $totais = TotaisPedido::paraItens($itens, $opcao);

            $pedido->update([
                'pedido_opcaoentrega_id' => $this->editOpcaoEntregaId ?: null,
                'pedido_endereco_entrega' => $this->editEndereco ?: null,
                'pedido_descricao_pagamento' => $this->editPagamentoNome ?: null,
                'pedido_observacao_pagamento' => $this->editObsPagamento ?: null,
                'pedido_valor_itens' => $totais['itens'],
                'pedido_valor_desconto' => $totais['desconto'],
                'pedido_valor_frete' => $totais['frete'],
                'pedido_valor_total' => $totais['total'],
            ]);
        }

        $this->fecharEdicao();
    }

    public function render()
    {
        $pedidos = Pedido::where('pedido_status', 'INICIADO')
            ->where('pedido_origem', PedidoOrigemEnum::CARDAPIO)
            ->with([
                'cliente',
                'opcaoEntrega',
                'item_pedido_pedido_id' => fn ($q) => $q
                    ->where('item_pedido_status', 'INSERIDO')
                    ->with('produto.categoria'),
            ])
            ->orderBy('created_at')
            ->get();

        $novaContagem = $pedidos->count();
        $temNovo = $novaContagem > $this->contagem && $this->contagem > 0;
        $this->contagem = $novaContagem;

        if ($temNovo) {
            $this->dispatch('novo-pedido');
        }

        $opcoesEntregas = OpcoesEntregas::orderBy('opcaoentrega_nome')->get();
        $opcoesPagamento = OpcoesPagamento::orderBy('opcaopag_nome')->get();

        return view('livewire.confirmacoes-pedidos', compact('pedidos', 'opcoesEntregas', 'opcoesPagamento'));
    }
}
