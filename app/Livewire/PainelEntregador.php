<?php

namespace App\Livewire;

use App\Enums\StonePedidoModo;
use App\Enums\StonePedidoStatus;
use App\Exceptions\StoneConnectException;
use App\Exceptions\StoneResultadoDesconhecidoException;
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

    /** form|aguardando|pago|erro|cancelado|indeterminado */
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

    /**
     * O botão "Receber na maquininha" aparece com cobrança em andamento (para
     * acompanhar) ou quando o pedido pode receber uma nova — a regra é do
     * StoneRecebimentoService::elegibilidade(), não da forma combinada.
     */
    public function podeCobrarNaMaquininha(Pedido $pedido): bool
    {
        if ($this->maquininhasStone->isEmpty()) {
            return false;
        }

        return $this->cobrancaPendenteDe($pedido) !== null
            || ! app(StoneRecebimentoService::class)->elegibilidade($pedido, haMaquininhaConnect: true)['oculto'];
    }

    private function cobrancaPendenteDe(Pedido $pedido): ?StonePedido
    {
        return $pedido->relationLoaded('stonePedidos')
            ? $pedido->stonePedidos->first(fn (StonePedido $stp): bool => $stp->stp_status->pendente())
            : $pedido->stonePedidosPendentes()->first();
    }

    public function abrirModalStone(int $pedidoId): void
    {
        $pedido = Pedido::find($pedidoId);
        if (! $pedido) {
            return;
        }

        $this->stonePedidoAlvoId = $pedidoId;
        $this->stoneErroModal = null;
        $this->stonePedidoId = null;

        // Cobrança em aberto: reabre o acompanhamento em vez de oferecer outro envio.
        $pendente = $pedido->stonePedidosPendentes()->first();
        if ($pendente) {
            $this->stonePedidoId = $pendente->id;
            $indeterminado = $pendente->stp_status === StonePedidoStatus::Indeterminado;
            $this->stoneStatusModal = $indeterminado ? 'indeterminado' : 'aguardando';
            $this->stoneErroModal = $indeterminado ? $pendente->stp_erro : null;
            $this->modalStoneAberta = true;

            return;
        }

        $elegibilidade = app(StoneRecebimentoService::class)->elegibilidade($pedido);
        if (! $elegibilidade['elegivel']) {
            $this->erroAceite = $elegibilidade['motivo'];
            $this->stonePedidoAlvoId = null;

            return;
        }

        $this->modalStoneAberta = true;
        $this->stoneStatusModal = 'form';
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
            // Sem valor: o serviço cobra o valor devido do pedido (descontado o que já foi pago).
            $stonePedido = app(StoneRecebimentoService::class)->iniciarCobrancaDePedido(
                $pedido, null, $maquininha, modo: StonePedidoModo::Listado,
            );
            $this->stonePedidoId = $stonePedido->id;
            $this->stoneStatusModal = 'aguardando';
        } catch (StoneResultadoDesconhecidoException $e) {
            $this->stonePedidoId = $e->stonePedidoId();
            $this->stoneStatusModal = 'indeterminado';
            $this->stoneErroModal = $e->getMessage();
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
            StonePedidoStatus::Indeterminado => 'indeterminado',
            default => $this->stoneStatusModal === 'indeterminado' ? 'aguardando' : $this->stoneStatusModal,
        };

        if ($this->stoneStatusModal === 'erro') {
            $this->stoneErroModal ??= $stonePedido->stp_erro ?: 'A cobrança falhou na Stone. Tente novamente.';
        }
    }

    /** Resultado desconhecido: pergunta à Stone (ou repete o envio com a mesma chave) e volta ao acompanhamento normal. */
    public function reconsultarCobrancaStone(): void
    {
        $stonePedido = $this->stonePedidoId ? StonePedido::find($this->stonePedidoId) : null;
        if (! $stonePedido) {
            return;
        }

        try {
            app(StoneRecebimentoService::class)->reconsultar($stonePedido);
        } catch (StoneConnectException $e) {
            $this->stoneErroModal = $e->getMessage();

            return;
        }

        $this->stoneErroModal = null;
        $this->stoneStatusModal = 'aguardando';
        $this->verificarStatusStone();
    }

    public function cancelarCobrancaStone(): void
    {
        if ($this->stonePedidoId) {
            $stonePedido = StonePedido::find($this->stonePedidoId);
            if ($stonePedido) {
                try {
                    app(StoneRecebimentoService::class)->cancelarCobranca($stonePedido);
                } catch (StoneConnectException $e) {
                    $this->stoneStatusModal = 'erro';
                    $this->stoneErroModal = $e->getMessage();

                    return;
                }
            }
        }

        $this->fecharModalStone();
    }

    private function comItens($query)
    {
        return $query->withExists(['item_pedido_pedido_id as tem_item_vendido' => fn ($q) => $q->whereNotNull('item_pedido_venda_id')])->with([
            'cliente',
            'opcaoEntrega',
            'pagamentosCombinados.opcaoPagamento',
            'stonePedidos',
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
