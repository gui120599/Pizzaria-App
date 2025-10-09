<?php

namespace App\Livewire;

use App\Models\Categoria;
use App\Models\Pedido;
use App\Models\Produto;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

class ListProdutos extends Component
{
    public $categorias;
    public $produtos = [];

    public $search = '';
    public $categoria_id = null;
    public $modoMaisPedidos = true;


    public function mount(Categoria $categoria)
    {
        $this->categorias = $categoria;
        $this->produtos = Cache::remember('top_10_produtos_mais_pedidos', now()->addMinutes(30), function () {
            // Buscar os últimos 100 pedidos
            $ultimosPedidosIds = Pedido::where('pedido_status', ['FINALIZADO'])
                ->orderByDesc('id')
                ->limit(100)
                ->pluck('id');

            // Buscar os 10 produtos mais pedidos nesses pedidos
            return Produto::whereHas('pedidos', function ($query) use ($ultimosPedidosIds) {
                $query->whereIn('pedidos.id', $ultimosPedidosIds);
            })
                ->withCount([
                    'pedidos as total_pedidos' => function ($query) use ($ultimosPedidosIds) {
                        $query->whereIn('pedidos.id', $ultimosPedidosIds);
                    }
                ])
                ->orderByDesc('total_pedidos')
                ->limit(10)
                ->get();
        });

    }

    public function FiltrarProdutosCategoriaId($categoria_id)
    {
        $this->modoMaisPedidos = false; // agora não estamos mais no modo "top 10"
        $this->categoria_id = $categoria_id;
        $this->filtrarProdutos();
    }

    // Método chamado automaticamente quando $search muda
    public function updatedSearch()
    {
        $this->filtrarProdutos();
    }

    // Método unificado de filtro
    private function filtrarProdutos()
    {
        $query = Produto::query();

        // Se houver categoria selecionada
        if ($this->categoria_id) {
            $query->where('produto_categoria_id', $this->categoria_id);
        }

        // Se houver busca
        if (!empty($this->search)) {
            $query->where(function ($q) {
                $q->where('produto_descricao', 'like', '%' . $this->search . '%')
                    ->orWhereHas('categoria', function ($c) {
                        $c->where('categoria_nome', 'like', '%' . $this->search . '%');
                    });
            });
        }


        if (empty($this->search) && empty($this->categoria_id)) {
            $this->produtos = null;
            return;
        }

        $this->produtos = $query->get();
    }

    public function top10MaisPedidos()
    {
        $this->categoria_id = null;  // reseta a categoria
        $this->search = '';           // reseta a busca
        $this->modoMaisPedidos = true; // indica que estamos no modo top 10

        $this->produtos = Cache::remember('top_10_produtos_mais_pedidos', now()->addMinutes(10), function () {
            $ultimosPedidosIds = Pedido::orderByDesc('id')->limit(100)->pluck('id');

            return Produto::whereHas('pedidos', function ($query) use ($ultimosPedidosIds) {
                $query->whereIn('pedidos.id', $ultimosPedidosIds);
            })
                ->withCount([
                    'pedidos as total_pedidos' => function ($query) use ($ultimosPedidosIds) {
                        $query->whereIn('pedidos.id', $ultimosPedidosIds);
                    }
                ])
                ->orderByDesc('total_pedidos')
                ->limit(10)
                ->get();
        });
    }

    public function render()
    {
        return view('livewire.list-produtos');
    }
}