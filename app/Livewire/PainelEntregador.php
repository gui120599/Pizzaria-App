<?php

namespace App\Livewire;

use App\Models\Pedido;
use App\Services\EntregaService;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Painel do Entregador')]
class PainelEntregador extends Component
{
    // Delivery é sempre pedido_opcaoentrega_id = 3 — mesma checagem hardcoded
    // já usada em resources/views/app/pedido/abertos.blade.php.
    private const OPCAO_ENTREGA_DELIVERY_ID = 3;

    public int $contagemDisponiveis = 0;

    public ?string $erroAceite = null;

    public function aceitar(int $pedidoId): void
    {
        $this->erroAceite = null;

        if (! app(EntregaService::class)->aceitar($pedidoId, auth()->user())) {
            $this->erroAceite = 'Esse pedido já foi aceito por outro entregador.';
        }
    }

    public function marcarEntregue(int $pedidoId): void
    {
        app(EntregaService::class)->marcarEntregue($pedidoId, auth()->user());
    }

    private function comItens($query)
    {
        return $query->with([
            'cliente',
            'opcaoEntrega',
            'item_pedido_pedido_id' => fn ($q) => $q
                ->where('item_pedido_status', 'INSERIDO')
                ->with('produto.categoria'),
        ]);
    }

    public function render()
    {
        $disponiveis = $this->comItens(
            Pedido::where('pedido_status', 'PRONTO')
                ->where('pedido_opcaoentrega_id', self::OPCAO_ENTREGA_DELIVERY_ID)
                ->whereNull('pedido_usuario_entrega_id')
                ->orderBy('pedido_datahora_pronto')
        )->get();

        $minhasEntregas = $this->comItens(
            Pedido::where('pedido_status', 'EM TRANSPORTE')
                ->where('pedido_usuario_entrega_id', auth()->id())
                ->orderBy('pedido_datahora_transporte')
        )->get();

        $novaContagem = $disponiveis->count();
        $temNovo = $novaContagem > $this->contagemDisponiveis && $this->contagemDisponiveis > 0;
        $this->contagemDisponiveis = $novaContagem;

        if ($temNovo) {
            $this->dispatch('nova-entrega-disponivel');
        }

        return view('livewire.painel-entregador', compact('disponiveis', 'minhasEntregas'));
    }
}
