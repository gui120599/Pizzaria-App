<?php

namespace App\Livewire;

use App\Enums\StonePedidoModo;
use App\Enums\StonePedidoStatus;
use App\Exceptions\StoneConnectException;
use App\Models\Maquininha;
use App\Models\Pedido;
use App\Models\SessaoMesa;
use App\Models\StonePedido;
use App\Services\Stone\StoneRecebimentoService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Botão + modal "Cobrar conta na maquininha" embutido na tela legada de
 * pedidos da mesa (resources/views/app/sessao_mesa/partials/list_itens.blade.php)
 * — envia a conta inteira da sessão (todos os pedidos ativos) sem Venda
 * prévia; o webhook charge.paid cria a Venda quando o pagamento chegar (ver
 * StoneVendaAutomaticaService). Espelha o modal Stone de OperarVenda/
 * AtenderPedido/PainelEntregador.
 */
class MesaStoneCobranca extends Component
{
    public int $sessaoMesaId;

    public bool $modalAberta = false;

    public ?int $maquininhaId = null;

    /** form|aguardando|pago|erro|cancelado */
    public string $status = 'form';

    public ?string $erro = null;

    public ?int $stonePedidoId = null;

    public function mount(int $sessaoMesaId): void
    {
        $this->sessaoMesaId = $sessaoMesaId;
        // Última maquininha usada por este garçom — na prática ele leva
        // sempre o mesmo aparelho (ver Maquininha::scopeStoneDisponivel).
        $this->maquininhaId = session('stone_ultima_maquininha_id.'.Auth::id());
    }

    #[Computed]
    public function maquininhasStone()
    {
        return Maquininha::stoneDisponivel()->orderBy('nome')->pluck('nome', 'id');
    }

    #[Computed]
    public function totalAberto(): float
    {
        return (float) Pedido::where('pedido_sessao_mesa_id', $this->sessaoMesaId)
            ->whereNotIn('pedido_status', ['CANCELADO', 'FINALIZADO'])
            ->sum('pedido_valor_total');
    }

    public function abrirModal(): void
    {
        $this->status = 'form';
        $this->erro = null;
        $this->stonePedidoId = null;
        $this->modalAberta = true;
    }

    public function fecharModal(): void
    {
        $recemPago = $this->status === 'pago';

        $this->modalAberta = false;
        $this->stonePedidoId = null;
        $this->erro = null;
        $this->status = 'form';

        if ($recemPago) {
            $this->dispatch('$refresh');
        }
    }

    public function enviarCobranca(): void
    {
        if (! $this->maquininhaId) {
            $this->addError('maquininhaId', 'Selecione a maquininha.');

            return;
        }

        $maquininha = Maquininha::find($this->maquininhaId);
        $sessaoMesa = SessaoMesa::find($this->sessaoMesaId);
        $valor = $this->totalAberto;

        if (! $maquininha || ! $sessaoMesa || $valor <= 0) {
            $this->erro = 'Sessão de mesa ou valor inválido para envio.';
            $this->status = 'erro';

            return;
        }

        session(['stone_ultima_maquininha_id.'.Auth::id() => $this->maquininhaId]);

        // O garçom escolhe o tipo (crédito/débito/PIX) na própria maquininha
        // — modo Listado, igual ao botão "Lançar total" do PDV.
        try {
            $stonePedido = app(StoneRecebimentoService::class)->iniciarCobrancaDePedido(
                $sessaoMesa, null, $maquininha, $valor, StonePedidoModo::Listado,
            );
            $this->stonePedidoId = $stonePedido->id;
            $this->status = 'aguardando';
        } catch (StoneConnectException $e) {
            $this->status = 'erro';
            $this->erro = $e->getMessage();
        }
    }

    public function verificarStatus(): void
    {
        if (! $this->stonePedidoId) {
            return;
        }

        $stonePedido = StonePedido::find($this->stonePedidoId);
        if (! $stonePedido) {
            return;
        }

        $this->status = match ($stonePedido->stp_status) {
            StonePedidoStatus::Pago => 'pago',
            StonePedidoStatus::Cancelado, StonePedidoStatus::Estornado => 'cancelado',
            StonePedidoStatus::Falha => 'erro',
            default => $this->status,
        };
    }

    public function cancelarCobranca(): void
    {
        if ($this->stonePedidoId) {
            $stonePedido = StonePedido::find($this->stonePedidoId);
            if ($stonePedido) {
                app(StoneRecebimentoService::class)->cancelarCobranca($stonePedido);
            }
        }

        $this->fecharModal();
    }

    public function render()
    {
        return view('livewire.mesa-stone-cobranca');
    }
}
