<?php

namespace App\Livewire;

use App\Models\ItensPedido;
use App\Models\OpcoesPagamento;
use App\Models\OpcoesEntregas;
use App\Models\Pedido;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Confirmações de Pedidos')]
class ConfirmacoesPedidos extends Component
{
    public int    $contagem          = 0;
    public int    $pedidoEditandoId  = 0;
    public string $editOpcaoEntregaId = '';
    public string $editEndereco      = '';
    public string $editPagamentoNome = '';
    public string $editObsPagamento  = '';

    public function confirmar(int $id): void
    {
        Pedido::where('id', $id)
            ->where('pedido_status', 'INICIADO')
            ->update(['pedido_status' => 'ABERTO']);
    }

    public function cancelar(int $id): void
    {
        Pedido::where('id', $id)
            ->where('pedido_status', 'INICIADO')
            ->update([
                'pedido_status'             => 'CANCELADO',
                'pedido_datahora_cancelado' => now(),
            ]);
    }

    public function abrirEdicao(int $id): void
    {
        $pedido = Pedido::find($id);
        if (! $pedido) return;

        $this->pedidoEditandoId   = $id;
        $this->editOpcaoEntregaId = (string) ($pedido->pedido_opcaoentrega_id ?? '');
        $this->editEndereco       = $pedido->pedido_endereco_entrega ?? '';
        $this->editPagamentoNome  = $pedido->pedido_descricao_pagamento ?? '';
        $this->editObsPagamento   = $pedido->pedido_observacao_pagamento ?? '';
    }

    public function fecharEdicao(): void
    {
        $this->pedidoEditandoId   = 0;
        $this->editOpcaoEntregaId = '';
        $this->editEndereco       = '';
        $this->editPagamentoNome  = '';
        $this->editObsPagamento   = '';
    }

    public function salvarAlteracoes(): void
    {
        if (! $this->pedidoEditandoId) return;

        $pedido = Pedido::where('id', $this->pedidoEditandoId)
            ->where('pedido_status', 'INICIADO')
            ->first();

        if ($pedido) {
            $itens = ItensPedido::where('item_pedido_pedido_id', $pedido->id)
                ->where('item_pedido_status', 'INSERIDO')
                ->get();

            $valorItens    = round($itens->sum('item_pedido_valor'), 2);
            $totalDesconto = round($itens->sum('item_pedido_desconto'), 2);
            $valorFrete    = $this->calcularFrete($this->editOpcaoEntregaId, $valorItens - $totalDesconto);

            $pedido->update([
                'pedido_opcaoentrega_id'      => $this->editOpcaoEntregaId ?: null,
                'pedido_endereco_entrega'     => $this->editEndereco ?: null,
                'pedido_descricao_pagamento'  => $this->editPagamentoNome ?: null,
                'pedido_observacao_pagamento' => $this->editObsPagamento ?: null,
                'pedido_valor_itens'          => $valorItens,
                'pedido_valor_desconto'       => $totalDesconto,
                'pedido_valor_frete'          => $valorFrete,
                'pedido_valor_total'          => round(max(0, $valorItens - $totalDesconto + $valorFrete), 2),
            ]);
        }

        $this->fecharEdicao();
    }

    private function calcularFrete(?string $opcaoEntregaId, float $totalLiquido): float
    {
        if (! $opcaoEntregaId) {
            return 0.0;
        }

        $opcao = OpcoesEntregas::find($opcaoEntregaId);
        if (! $opcao || $opcao->opcaoentrega_valor_frete <= 0) {
            return 0.0;
        }

        if ($opcao->opcaoentrega_min_valor_frete > 0 && $totalLiquido >= $opcao->opcaoentrega_min_valor_frete) {
            return 0.0;
        }

        return (float) $opcao->opcaoentrega_valor_frete;
    }

    public function render()
    {
        $pedidos = Pedido::where('pedido_status', 'INICIADO')
            ->whereNull('pedido_sessao_mesa_id')
            ->whereNull('pedido_usuario_garcom_id')
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
        $temNovo      = $novaContagem > $this->contagem && $this->contagem > 0;
        $this->contagem = $novaContagem;

        if ($temNovo) {
            $this->dispatch('novo-pedido');
        }

        $opcoesEntregas  = OpcoesEntregas::orderBy('opcaoentrega_nome')->get();
        $opcoesPagamento = OpcoesPagamento::orderBy('opcaopag_nome')->get();

        return view('livewire.confirmacoes-pedidos', compact('pedidos', 'opcoesEntregas', 'opcoesPagamento'));
    }
}
