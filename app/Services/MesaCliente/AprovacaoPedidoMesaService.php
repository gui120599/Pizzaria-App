<?php

namespace App\Services\MesaCliente;

use App\Enums\MotivoCancelamentoEnum;
use App\Enums\PedidoOrigemEnum;
use App\Enums\StatusAprovacaoPedidoEnum;
use App\Exceptions\EstoqueInsuficienteException;
use App\Models\Pedido;
use App\Models\SessaoMesa;
use App\Models\User;
use App\Services\Garcom\AtendimentoMesaService;
use App\Services\LancamentoItemPedidoService;
use App\Services\PedidoStatusService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Decisão do garçom sobre o pedido do QR que ficou pendente. Aprovar segue o
 * mesmo envio da rodada do garçom (INICIADO → ABERTO); recusar cancela com o
 * motivo, estornando as promoções. Lock + recheck: dois garçons decidindo ao
 * mesmo tempo resultam numa decisão só.
 */
class AprovacaoPedidoMesaService
{
    public function __construct(
        private AtendimentoMesaService $atendimento,
        private PedidoStatusService $status,
        private LancamentoItemPedidoService $lancamento,
    ) {}

    /**
     * @return bool false quando o pedido já tinha sido decidido
     *
     * @throws RuntimeException|EstoqueInsuficienteException
     */
    public function aprovar(Pedido $pedido, User $garcom): bool
    {
        return DB::transaction(function () use ($pedido, $garcom) {
            $fresco = Pedido::whereKey($pedido->getKey())->lockForUpdate()->firstOrFail();

            if (! $fresco->aguardandoAprovacao()) {
                return false;
            }

            // O estoque pode ter acabado enquanto o pedido esperava.
            $this->lancamento->validarEstoqueDosItens(
                $fresco->item_pedido_pedido_id()->where('item_pedido_status', 'INSERIDO')->get(),
            );

            $fresco->fill([
                'pedido_aprovacao_status' => StatusAprovacaoPedidoEnum::APROVADO,
                'pedido_aprovado_por_id' => $garcom->id,
                'pedido_aprovado_em' => Carbon::now(),
            ])->save();

            $this->atendimento->enviarRodada($fresco);

            return true;
        });
    }

    /**
     * @return bool false quando o pedido já tinha sido decidido
     *
     * @throws RuntimeException sem motivo
     */
    public function recusar(Pedido $pedido, ?User $garcom, string $motivo): bool
    {
        $motivo = trim($motivo);

        if ($motivo === '') {
            throw new RuntimeException('Informe o motivo da recusa.');
        }

        return DB::transaction(function () use ($pedido, $garcom, $motivo) {
            $fresco = Pedido::whereKey($pedido->getKey())->lockForUpdate()->firstOrFail();

            if (! $fresco->aguardandoAprovacao()) {
                return false;
            }

            $fresco->fill([
                'pedido_aprovacao_status' => StatusAprovacaoPedidoEnum::RECUSADO,
                'pedido_recusa_motivo' => mb_substr($motivo, 0, 255),
                'pedido_aprovado_por_id' => $garcom?->id,
                'pedido_aprovado_em' => Carbon::now(),
            ])->save();

            // Sem ator: recusar o pedido do QR é atribuição do garçom da mesa,
            // não a permissão de cancelar pedido (que exige gerente).
            $this->status->cancelar($fresco, null, MotivoCancelamentoEnum::OUTRO);

            return true;
        });
    }

    /** Fechar a mesa recusa o que o cliente pediu e ninguém decidiu. */
    public function recusarPendentesDaSessao(SessaoMesa $sessao, ?User $garcom, string $motivo = 'Mesa fechada.'): int
    {
        return Pedido::query()
            ->where('pedido_sessao_mesa_id', $sessao->id)
            ->where('pedido_origem', PedidoOrigemEnum::MESA_QR->value)
            ->where('pedido_aprovacao_status', StatusAprovacaoPedidoEnum::PENDENTE->value)
            ->get()
            ->filter(fn (Pedido $pedido) => $this->recusar($pedido, $garcom, $motivo))
            ->count();
    }
}
