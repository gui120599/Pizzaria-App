<?php

namespace App\Livewire;

use App\Enums\ProdutoTipoEnum;
use App\Models\Categoria;
use App\Models\Produto;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Aba "Produtos" do PDV: catálogo com busca/filtro por categoria. Componente
 * puramente de apresentação — não persiste nada e não conhece a Venda atual.
 * Um clique só dispara o evento `produto-selecionado`, capturado pela Page
 * pai (App\Filament\Pages\OperarVenda::adicionarProdutoAvulso), que decide se
 * precisa criar a Venda antes (criação lazy) e faz a persistência de fato.
 * Espelha App\Livewire\PedidoProdutoSelector na estrutura (catálogo, busca),
 * mas fica deliberadamente mais simples — sem modal de adicionais/sabores —
 * porque o antigo ItensVendaController::adicionarProduto nunca ofereceu isso
 * aqui: customização de item (sabores, adicionais) é feita na tela de Pedido
 * e chega na venda já pronta pela aba Mesas/Pedidos.
 */
class VendaProdutoSelector extends Component
{
    public string $busca = '';

    public ?int $categoriaId = null;

    #[Computed]
    public function categorias()
    {
        $tiposVenda = [ProdutoTipoEnum::PRODUZIDO->value, ProdutoTipoEnum::REVENDA->value];

        return Categoria::whereHas('produtos', fn ($q) => $q->whereIn('produto_tipo', $tiposVenda))
            ->orderBy('categoria_ordem')
            ->orderBy('categoria_nome')
            ->get();
    }

    #[Computed]
    public function produtos()
    {
        $tiposVenda = [ProdutoTipoEnum::PRODUZIDO->value, ProdutoTipoEnum::REVENDA->value];

        return Produto::with('categoria')
            ->whereIn('produto_tipo', $tiposVenda)
            ->when($this->busca, fn ($q) => $q->where('produto_descricao', 'like', "%{$this->busca}%"))
            ->when($this->categoriaId, fn ($q) => $q->where('produto_categoria_id', $this->categoriaId))
            ->orderBy('produto_ordem')
            ->orderBy('produto_descricao')
            ->limit(60)
            ->get();
    }

    public function selecionarProduto(int $produtoId): void
    {
        $this->dispatch('produto-selecionado', produtoId: $produtoId);
    }

    public function render()
    {
        return view('livewire.venda-produto-selector');
    }
}
