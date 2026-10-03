<?php

namespace App\Filament\Garcom\Concerns;

use App\Livewire\PedidoProdutoSelector;
use Livewire\Attributes\On;

/**
 * Carrinho do PedidoProdutoSelector exibido num painel da página (Painel do
 * Garçom no desktop). O seletor continua dono dos itens: a página só recebe a
 * lista por itens-pedido-atualizados e repassa os cliques do partial
 * pedido-item-linha (mesmo desenho do AtenderPedido do /admin).
 */
trait CarrinhoLateral
{
    /** @var array<int, array<string, mixed>> */
    public array $itensCarrinho = [];

    #[On('itens-pedido-atualizados')]
    public function onItensAtualizados(array $itens): void
    {
        $this->itensCarrinho = $itens;
    }

    public function incrementarQtd(string $itemId): void
    {
        $this->dispatch('pedido-incrementar-item', itemId: $itemId)->to(PedidoProdutoSelector::class);
    }

    public function decrementarQtd(string $itemId): void
    {
        $this->dispatch('pedido-decrementar-item', itemId: $itemId)->to(PedidoProdutoSelector::class);
    }

    public function removerItem(string $itemId): void
    {
        $this->dispatch('pedido-remover-item', itemId: $itemId)->to(PedidoProdutoSelector::class);
    }

    public function abrirEditModal(string $itemId): void
    {
        $this->dispatch('pedido-abrir-edicao-item', itemId: $itemId)->to(PedidoProdutoSelector::class);
    }

    /** Soma líquida dos itens do carrinho (item_pedido_valor já desconta o desconto). */
    public function totalCarrinho(): float
    {
        return round((float) collect($this->itensCarrinho)->sum('valor'), 2);
    }
}
