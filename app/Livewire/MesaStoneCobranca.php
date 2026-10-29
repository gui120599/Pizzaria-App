<?php

namespace App\Livewire;

use App\Enums\StonePedidoModo;
use App\Enums\StonePedidoOrigem;
use App\Enums\StonePedidoStatus;
use App\Exceptions\StoneConnectException;
use App\Exceptions\StoneResultadoDesconhecidoException;
use App\Models\Maquininha;
use App\Models\Pedido;
use App\Models\SessaoMesa;
use App\Models\StonePedido;
use App\Services\Stone\StoneRecebimentoService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Botão + modal "Cobrar conta na maquininha" — conta de mesa (sessaoMesaId)
 * ou pedido avulso (pedidoId, retirada do Painel do Garçom). Embutido na tela legada de
 * pedidos da mesa (resources/views/app/sessao_mesa/partials/list_itens.blade.php)
 * — envia a conta inteira da sessão (todos os pedidos ativos) sem Venda
 * prévia; o webhook charge.paid cria a Venda quando o pagamento chegar (ver
 * StoneVendaAutomaticaService). Espelha o modal Stone de OperarVenda/
 * AtenderPedido/PainelEntregador.
 */
class MesaStoneCobranca extends Component
{
    public ?int $sessaoMesaId = null;

    /** Pedido avulso (retirada do Painel do Garçom) em vez de conta de mesa. */
    public ?int $pedidoId = null;

    public bool $modalAberta = false;

    public ?int $maquininhaId = null;

    /** form|aguardando|pago|erro|cancelado|indeterminado */
    public string $status = 'form';

    public ?string $erro = null;

    public ?int $stonePedidoId = null;

    public function mount(?int $sessaoMesaId = null, ?int $pedidoId = null): void
    {
        $this->sessaoMesaId = $sessaoMesaId;
        $this->pedidoId = $pedidoId;
        // Última maquininha usada por este garçom — na prática ele leva
        // sempre o mesmo aparelho (ver Maquininha::scopeStoneDisponivel).
        $this->maquininhaId = session('stone_ultima_maquininha_id.'.Auth::id());
    }

    #[Computed]
    public function maquininhasStone()
    {
        return Maquininha::stoneDisponivel()->orderBy('nome')->pluck('nome', 'id');
    }

    /** Conta da mesa ou pedido avulso a cobrar (null quando já não é cobrável, ex.: pedido lançado no PDV). */
    private function alvo(): Pedido|SessaoMesa|null
    {
        return $this->pedidoId ? $this->pedidoAvulso() : SessaoMesa::find($this->sessaoMesaId);
    }

    /** Cobrança da maquininha ainda em aberto neste alvo, se houver. */
    private function cobrancaPendente(): ?StonePedido
    {
        return StonePedido::query()
            ->when($this->pedidoId, fn ($query) => $query->where('stp_pedido_id', $this->pedidoId)->where('stp_origem', StonePedidoOrigem::Pedido->value))
            ->unless($this->pedidoId, fn ($query) => $query->where('stp_sessao_mesa_id', $this->sessaoMesaId))
            ->whereIn('stp_status', StonePedidoStatus::valoresPendentes())
            ->latest('id')
            ->first();
    }

    /**
     * O botão aparece com cobrança em andamento (para acompanhar) ou quando a
     * conta pode receber uma — regra do StoneRecebimentoService::elegibilidade().
     */
    #[Computed]
    public function podeCobrar(): bool
    {
        if ($this->maquininhasStone->isEmpty()) {
            return false;
        }

        if ($this->cobrancaPendente() !== null) {
            return true;
        }

        $alvo = $this->alvo();

        return $alvo !== null
            && ! app(StoneRecebimentoService::class)->elegibilidade($alvo, haMaquininhaConnect: true)['oculto'];
    }

    /** O que a maquininha vai cobrar: o valor devido da conta ou do pedido (descontado o que já foi pago ou está aguardando). */
    #[Computed]
    public function totalAberto(): float
    {
        $alvo = $this->alvo();

        return $alvo ? app(StoneRecebimentoService::class)->valorDevido($alvo) : 0.0;
    }

    public function abrirModal(): void
    {
        $this->erro = null;
        $this->stonePedidoId = null;

        // Cobrança em aberto: reabre o acompanhamento em vez de oferecer outro envio.
        $pendente = $this->cobrancaPendente();
        if ($pendente) {
            $this->stonePedidoId = $pendente->id;
            $indeterminado = $pendente->stp_status === StonePedidoStatus::Indeterminado;
            $this->status = $indeterminado ? 'indeterminado' : 'aguardando';
            $this->erro = $indeterminado ? $pendente->stp_erro : null;
            $this->modalAberta = true;

            return;
        }

        $alvo = $this->alvo();
        $elegibilidade = $alvo ? app(StoneRecebimentoService::class)->elegibilidade($alvo) : ['elegivel' => false, 'motivo' => 'Conta ou pedido indisponível para cobrança.'];

        $this->status = $elegibilidade['elegivel'] ? 'form' : 'erro';
        $this->erro = $elegibilidade['elegivel'] ? null : $elegibilidade['motivo'];
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
        $alvo = $this->alvo();

        if (! $maquininha || ! $alvo) {
            $this->erro = 'Conta ou valor inválido para envio.';
            $this->status = 'erro';

            return;
        }

        session(['stone_ultima_maquininha_id.'.Auth::id() => $this->maquininhaId]);

        // O garçom escolhe o tipo (crédito/débito/PIX) na própria maquininha
        // — modo Listado, igual ao botão "Lançar total" do PDV. Sem valor: o
        // serviço cobra o valor devido da conta.
        try {
            $stonePedido = app(StoneRecebimentoService::class)->iniciarCobrancaDePedido(
                $alvo, null, $maquininha, modo: StonePedidoModo::Listado,
            );
            $this->stonePedidoId = $stonePedido->id;
            $this->status = 'aguardando';
        } catch (StoneResultadoDesconhecidoException $e) {
            $this->stonePedidoId = $e->stonePedidoId();
            $this->status = 'indeterminado';
            $this->erro = $e->getMessage();
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
            StonePedidoStatus::Indeterminado => 'indeterminado',
            default => $this->status === 'indeterminado' ? 'aguardando' : $this->status,
        };

        if ($this->status === 'erro') {
            $this->erro ??= $stonePedido->stp_erro ?: 'A cobrança falhou na Stone. Tente novamente.';
        }
    }

    /** Resultado desconhecido: pergunta à Stone (ou repete o envio com a mesma chave) e volta ao acompanhamento normal. */
    public function reconsultarCobranca(): void
    {
        $stonePedido = $this->stonePedidoId ? StonePedido::find($this->stonePedidoId) : null;
        if (! $stonePedido) {
            return;
        }

        try {
            app(StoneRecebimentoService::class)->reconsultar($stonePedido);
        } catch (StoneConnectException $e) {
            $this->erro = $e->getMessage();

            return;
        }

        $this->erro = null;
        $this->status = 'aguardando';
        $this->verificarStatus();
    }

    public function cancelarCobranca(): void
    {
        if ($this->stonePedidoId) {
            $stonePedido = StonePedido::find($this->stonePedidoId);
            if ($stonePedido) {
                try {
                    app(StoneRecebimentoService::class)->cancelarCobranca($stonePedido);
                } catch (StoneConnectException $e) {
                    $this->status = 'erro';
                    $this->erro = $e->getMessage();

                    return;
                }
            }
        }

        $this->fecharModal();
    }

    public function render()
    {
        return view('livewire.mesa-stone-cobranca');
    }

    /** Pedido avulso ainda cobrável: não cancelado/finalizado e sem item lançado em venda. */
    private function pedidoAvulso(): ?Pedido
    {
        return Pedido::query()
            ->whereKey($this->pedidoId)
            ->whereNull('pedido_sessao_mesa_id')
            ->whereNull('pedido_venda_id')
            ->whereNotIn('pedido_status', ['INICIADO', 'CANCELADO', 'FINALIZADO'])
            ->whereDoesntHave('item_pedido_pedido_id', fn ($q) => $q->whereNotNull('item_pedido_venda_id'))
            ->first();
    }
}
