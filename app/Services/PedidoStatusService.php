<?php

namespace App\Services;

use App\Enums\MotivoCancelamentoEnum;
use App\Enums\StatusPedidoEnum;
use App\Exceptions\TransicaoPedidoInvalidaException;
use App\Models\Pedido;
use App\Models\SessaoMesa;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Regra única de transição de status do pedido.
 *
 * Antes desta classe, as transições viviam espalhadas em PedidoController (5
 * handlers), EntregaService, ConfirmacoesPedidos, SessaoMesaController e
 * FinalizacaoVendaService — cada uma com sua cópia da regra ENTREGUE-vs-
 * FINALIZADO e do estorno de promoções.
 *
 * DUAS INVARIANTES QUE NÃO PODEM SER QUEBRADAS:
 *
 * 1. A gravação é SEMPRE `$pedido->save()`/`->update()` no model, nunca
 *    `updateQuietly()` nem `update()` no query builder. PedidoObserver::updating
 *    é a única fonte da baixa de estoque (na entrada em PREPARANDO), do estorno
 *    (no cancelamento) e do processamento de comissão. Pular o observer não gera
 *    erro nenhum: o estoque simplesmente para de ser movimentado.
 *
 * 2. Toda transição roda dentro de DB::transaction com o pedido relido em
 *    lockForUpdate. É isso — não o `wire:loading` do botão — que impede duplo
 *    clique ou dois operadores de aplicarem a mesma transição duas vezes e
 *    baixarem o estoque em dobro.
 *
 * O service não autoriza por conta própria quando o ator não é informado; com
 * ator, checa PedidoPolicy. Quem chama decide qual ator vale.
 */
class PedidoStatusService
{
    /** INICIADO → ABERTO (confirmação de pedido vindo do cardápio). */
    public function confirmar(Pedido $pedido, ?User $ator = null): Pedido
    {
        return $this->transicionar($pedido, StatusPedidoEnum::INICIADO, StatusPedidoEnum::ABERTO, $ator, 'accept');
    }

    /** ABERTO → PREPARANDO. É a transição que baixa o estoque. */
    public function aceitar(Pedido $pedido, ?User $ator = null): Pedido
    {
        return $this->transicionar($pedido, StatusPedidoEnum::ABERTO, StatusPedidoEnum::PREPARANDO, $ator, 'accept');
    }

    /**
     * Avança um degrau, seguindo StatusPedidoEnum::proximo().
     *
     * O destino depende do pedido: de PRONTO, delivery vai para EM TRANSPORTE e
     * mesa/balcão vai direto para ENTREGUE.
     */
    public function avancar(Pedido $pedido, ?User $ator = null): Pedido
    {
        $atual = $this->statusDe($pedido);
        $destino = $atual->proximo($pedido->exigeEntrega());

        if ($destino === null) {
            throw TransicaoPedidoInvalidaException::semProximoStatus($pedido, $atual);
        }

        return $this->transicionar($pedido, $atual, $destino, $ator, 'advance');
    }

    /**
     * Avança para um destino nomeado, exigindo que ele seja o próximo degrau
     * válido a partir do status atual. Útil para quem já sabe onde quer chegar
     * (as rotas legadas do Kanban antigo, por exemplo).
     */
    public function avancarPara(Pedido $pedido, StatusPedidoEnum $destino, ?User $ator = null): Pedido
    {
        return $this->transicionar($pedido, $this->statusDe($pedido), $destino, $ator, 'advance');
    }

    /**
     * PRONTO → EM TRANSPORTE, opcionalmente atribuindo o entregador.
     *
     * É uma intenção explícita do operador ("este pedido está saindo"), por isso
     * NÃO passa pela bifurcação de proximo(): mesmo um pedido sem endereço pode
     * ser despachado se alguém decidir levá-lo. A bifurcação governa o botão
     * genérico de avançar, e é o painel que decide quando oferecer cada um.
     */
    public function despachar(Pedido $pedido, ?User $ator = null, ?User $entregador = null): Pedido
    {
        $extras = [];

        if ($entregador !== null && $this->atribuiEntregador()) {
            $extras['pedido_usuario_entrega_id'] = $entregador->id;
        }

        return $this->transicionar(
            $pedido,
            StatusPedidoEnum::PRONTO,
            StatusPedidoEnum::EM_TRANSPORTE,
            $ator,
            'advance',
            $extras,
            validarFluxo: false,
        );
    }

    /**
     * Encerra a entrega. O status final é FINALIZADO quando o pedido já tem
     * venda paga, ENTREGUE caso contrário — regra resolvida em transicionar().
     */
    public function marcarEntregue(Pedido $pedido, ?User $ator = null): Pedido
    {
        $atual = $this->statusDe($pedido);

        return $this->transicionar($pedido, $atual, StatusPedidoEnum::ENTREGUE, $ator, 'advance');
    }

    /**
     * Troca o entregador atribuído SEM mexer no status — entregador ficou
     * indisponível no meio da rota, por exemplo. Usa o mesmo lock das
     * transições pra não colidir com um avancar()/marcarEntregue()
     * concorrente, mas como pedido_status não muda, nem PedidoObserver (baixa
     * de estoque) nem HistoricoStatusPedidoObserver (timeline) disparam —
     * corretamente, já que isto não é uma transição de status.
     */
    public function reatribuirEntregador(Pedido $pedido, User $entregador, ?User $ator = null): Pedido
    {
        $this->autorizar('advance', $pedido, $ator);

        return DB::transaction(function () use ($pedido, $entregador) {
            $fresco = Pedido::whereKey($pedido->getKey())->lockForUpdate()->firstOrFail();

            $fresco->fill(['pedido_usuario_entrega_id' => $entregador->id])->save();

            return $fresco;
        });
    }

    /**
     * Rejeita um pedido ainda não aceito. Mesmo efeito de cancelar, mas com a
     * permission `reject` e sem os guards de mesa/pagamento, que não fazem
     * sentido num pedido que nunca entrou em produção.
     */
    public function rejeitar(
        Pedido $pedido,
        ?User $ator = null,
        MotivoCancelamentoEnum $motivo = MotivoCancelamentoEnum::OUTRO,
    ): Pedido {
        return $this->cancelamento($pedido, $ator, $motivo, 'reject', comGuards: false);
    }

    /**
     * Cancela um pedido em produção. Aplica os guards que antes só existiam em
     * PedidoController::CancelarPedido: sessão de mesa aberta e pedido não pago.
     */
    public function cancelar(Pedido $pedido, ?User $ator, MotivoCancelamentoEnum $motivo): Pedido
    {
        return $this->cancelamento($pedido, $ator, $motivo, 'cancel', comGuards: true);
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $extras
     * @param  bool  $validarFluxo  false salta a checagem da bifurcação, para
     *                              transições que são intenção explícita do
     *                              operador (ver despachar()). O lock e a
     *                              checagem de origem continuam valendo.
     */
    private function transicionar(
        Pedido $pedido,
        StatusPedidoEnum $origemEsperada,
        StatusPedidoEnum $destino,
        ?User $ator,
        string $habilidade,
        array $extras = [],
        bool $validarFluxo = true,
    ): Pedido {
        $this->autorizar($habilidade, $pedido, $ator);

        return DB::transaction(function () use ($pedido, $origemEsperada, $destino, $extras, $validarFluxo) {
            $fresco = Pedido::whereKey($pedido->getKey())->lockForUpdate()->firstOrFail();
            $atual = $this->statusDe($fresco);

            if ($atual !== $origemEsperada) {
                throw TransicaoPedidoInvalidaException::jaAvancou($fresco, $atual);
            }

            if ($validarFluxo && ! $atual->podeAvancarPara($destino, $fresco->exigeEntrega())) {
                throw TransicaoPedidoInvalidaException::destinoInvalido($fresco, $atual, $destino);
            }

            $destinoFinal = $this->resolverDestinoFinal($fresco, $destino);

            $fresco->fill($extras + $this->atributosDaTransicao($destinoFinal))->save();

            return $fresco;
        });
    }

    private function cancelamento(
        Pedido $pedido,
        ?User $ator,
        MotivoCancelamentoEnum $motivo,
        string $habilidade,
        bool $comGuards,
    ): Pedido {
        $this->autorizar($habilidade, $pedido, $ator);

        return DB::transaction(function () use ($pedido, $ator, $motivo, $comGuards) {
            $fresco = Pedido::whereKey($pedido->getKey())->lockForUpdate()->firstOrFail();
            $atual = $this->statusDe($fresco);

            if (! $atual->podeSerCancelado()) {
                throw TransicaoPedidoInvalidaException::naoPodeSerCancelado($fresco, $atual);
            }

            if ($comGuards) {
                $this->garantirQuePodeCancelar($fresco);
            }

            // Devolve ao saldo qualquer promoção relâmpago/adicional consumida
            // pelos itens deste pedido. Isto NÃO está no PedidoObserver — é
            // responsabilidade de quem cancela, e era a razão de cancelamentos
            // por outros caminhos não estornarem nada.
            app(PromocaoRelampagoService::class)->estornarPedido($fresco);
            app(PromocaoAdicionalService::class)->estornarPedido($fresco);

            $fresco->fill([
                'pedido_status' => StatusPedidoEnum::CANCELADO->value,
                'pedido_motivo_cancelamento' => $motivo->value,
                'pedido_usuario_cancelou_id' => $ator?->id ?? auth()->id(),
                'pedido_datahora_cancelado' => Carbon::now(),
            ])->save();

            return $fresco;
        });
    }

    /**
     * Pedido já pago, ou preso numa sessão de mesa que já fechou, não pode ser
     * cancelado: o dinheiro e o fechamento da mesa já foram contabilizados.
     */
    private function garantirQuePodeCancelar(Pedido $pedido): void
    {
        if ($pedido->pedido_venda_id !== null) {
            throw TransicaoPedidoInvalidaException::jaPago($pedido);
        }

        if ($pedido->pedido_sessao_mesa_id === null) {
            return;
        }

        $sessao = SessaoMesa::find($pedido->pedido_sessao_mesa_id);

        if ($sessao && $sessao->sessao_mesa_status !== 'ABERTA') {
            throw TransicaoPedidoInvalidaException::sessaoMesaFechada($pedido, (string) $sessao->sessao_mesa_status);
        }
    }

    /**
     * Pedido que já tem venda finalizada encerra em FINALIZADO, não ENTREGUE.
     * Regra que estava duplicada em PedidoController::AvancarPedidoEntregue e
     * EntregaService::marcarEntregue().
     */
    private function resolverDestinoFinal(Pedido $pedido, StatusPedidoEnum $destino): StatusPedidoEnum
    {
        if ($destino === StatusPedidoEnum::ENTREGUE && $pedido->pedido_datahora_finalizado !== null) {
            return StatusPedidoEnum::FINALIZADO;
        }

        return $destino;
    }

    /**
     * Status + a data/hora de entrada nele. FINALIZADO alcançado por entrega
     * preserva a data original de finalização (que é a do pagamento) e grava a
     * hora da entrega.
     *
     * @return array<string, mixed>
     */
    private function atributosDaTransicao(StatusPedidoEnum $destino): array
    {
        $atributos = ['pedido_status' => $destino->value];

        $campo = $destino === StatusPedidoEnum::FINALIZADO
            ? StatusPedidoEnum::ENTREGUE->campoDataHora()
            : $destino->campoDataHora();

        if ($campo !== null) {
            $atributos[$campo] = Carbon::now();
        }

        return $atributos;
    }

    private function statusDe(Pedido $pedido): StatusPedidoEnum
    {
        return $pedido->status() ?? throw TransicaoPedidoInvalidaException::statusDesconhecido($pedido);
    }

    private function autorizar(string $habilidade, Pedido $pedido, ?User $ator): void
    {
        if ($ator === null) {
            return;
        }

        Gate::forUser($ator)->authorize($habilidade, $pedido);
    }

    private function atribuiEntregador(): bool
    {
        return (bool) config('pizzaria.pedidos.atribui_entregador', true);
    }
}
