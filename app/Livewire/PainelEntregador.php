<?php

namespace App\Livewire;

use App\Enums\StonePedidoModo;
use App\Enums\StonePedidoStatus;
use App\Exceptions\StoneConnectException;
use App\Models\Maquininha;
use App\Models\Pedido;
use App\Models\StonePedido;
use App\Services\EntregaService;
use App\Services\Stone\StoneRecebimentoService;
use Livewire\Attributes\Computed;
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

    // ── Cobrança na maquininha Stone (o entregador leva a própria maquininha) ──
    public bool $modalStoneAberta = false;

    public ?int $stonePedidoAlvoId = null;

    public ?int $stoneMaquininhaId = null;

    /** form|aguardando|pago|erro|cancelado */
    public string $stoneStatusModal = 'form';

    public ?string $stoneErroModal = null;

    public ?int $stonePedidoId = null;

    public function mount(): void
    {
        // Última maquininha usada por este entregador — na prática ele sempre
        // leva o mesmo aparelho (ver Maquininha::scopeStoneDisponivel).
        $this->stoneMaquininhaId = session('stone_ultima_maquininha_id.'.auth()->id());
    }

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

    #[Computed]
    public function maquininhasStone()
    {
        return Maquininha::stoneDisponivel()->orderBy('nome')->pluck('nome', 'id');
    }

    public function abrirModalStone(int $pedidoId): void
    {
        $this->stonePedidoAlvoId = $pedidoId;
        $this->modalStoneAberta = true;
        $this->stoneStatusModal = 'form';
        $this->stoneErroModal = null;
        $this->stonePedidoId = null;
    }

    public function fecharModalStone(): void
    {
        $this->modalStoneAberta = false;
        $this->stonePedidoAlvoId = null;
        $this->stonePedidoId = null;
        $this->stoneErroModal = null;
        $this->stoneStatusModal = 'form';
    }

    public function enviarCobrancaStone(): void
    {
        if (! $this->stoneMaquininhaId) {
            $this->addError('stoneMaquininhaId', 'Selecione a maquininha.');

            return;
        }

        $maquininha = Maquininha::find($this->stoneMaquininhaId);
        $pedido = Pedido::find($this->stonePedidoAlvoId);

        if (! $maquininha || ! $pedido) {
            $this->erroAceite = 'Pedido ou maquininha não encontrado.';
            $this->fecharModalStone();

            return;
        }

        session(['stone_ultima_maquininha_id.'.auth()->id() => $this->stoneMaquininhaId]);

        // O entregador escolhe o tipo (crédito/débito/PIX) na própria
        // maquininha — modo Listado, igual ao botão "Lançar total" do PDV.
        try {
            $stonePedido = app(StoneRecebimentoService::class)->iniciarCobrancaDePedido(
                $pedido, null, $maquininha, (float) $pedido->pedido_valor_total, StonePedidoModo::Listado,
            );
            $this->stonePedidoId = $stonePedido->id;
            $this->stoneStatusModal = 'aguardando';
        } catch (StoneConnectException $e) {
            $this->stoneStatusModal = 'erro';
            $this->stoneErroModal = $e->getMessage();
        }
    }

    public function verificarStatusStone(): void
    {
        if (! $this->stonePedidoId) {
            return;
        }

        $stonePedido = StonePedido::find($this->stonePedidoId);
        if (! $stonePedido) {
            return;
        }

        $this->stoneStatusModal = match ($stonePedido->stp_status) {
            StonePedidoStatus::Pago => 'pago',
            StonePedidoStatus::Cancelado, StonePedidoStatus::Estornado => 'cancelado',
            StonePedidoStatus::Falha => 'erro',
            default => $this->stoneStatusModal,
        };
    }

    public function cancelarCobrancaStone(): void
    {
        if ($this->stonePedidoId) {
            $stonePedido = StonePedido::find($this->stonePedidoId);
            if ($stonePedido) {
                app(StoneRecebimentoService::class)->cancelarCobranca($stonePedido);
            }
        }

        $this->fecharModalStone();
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
