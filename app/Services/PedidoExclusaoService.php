<?php

namespace App\Services;

use App\Enums\MotivoExclusaoPedidoEnum;
use App\Enums\StatusComissaoEnum;
use App\Enums\StatusPedidoEnum;
use App\Enums\StonePedidoStatus;
use App\Exceptions\PedidoNaoPodeSerExcluidoException;
use App\Models\Pedido;
use App\Models\StonePedido;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Exclusão (soft delete) de pedido com motivo obrigatório.
 *
 * A exclusão NUNCA é física e nunca deixa efeito colateral sem estorno: pedido
 * em INICIADO/ABERTO é cancelado antes, pelo PedidoStatusService (que estorna
 * promoção, cupom e produção); do PREPARANDO em diante o pedido precisa ser
 * cancelado antes, por envolver estoque e cozinha. Pedido com venda, cobrança
 * na maquininha ou comissão em andamento é recusado.
 */
class PedidoExclusaoService
{
    /** Status em que a exclusão é permitida. */
    private const STATUS_EXCLUIVEIS = [
        StatusPedidoEnum::CANCELADO,
        StatusPedidoEnum::INICIADO,
        StatusPedidoEnum::ABERTO,
    ];

    public function __construct(private readonly PedidoStatusService $statusService) {}

    /** O pedido, no estado atual, pode ser excluído? (não checa permissão) */
    public function podeExcluir(Pedido $pedido): bool
    {
        return $this->recusa($pedido) === null;
    }

    /** Motivo pelo qual o pedido não pode ser excluído, ou null quando pode. */
    public function recusa(Pedido $pedido): ?PedidoNaoPodeSerExcluidoException
    {
        $status = $pedido->status();

        if ($status === null || ! in_array($status, self::STATUS_EXCLUIVEIS, true)) {
            return $status === null
                ? new PedidoNaoPodeSerExcluidoException("O pedido {$pedido->id} tem um status fora do esperado.")
                : PedidoNaoPodeSerExcluidoException::statusNaoPermitido($pedido, $status);
        }

        if ($pedido->temPagamento()) {
            return PedidoNaoPodeSerExcluidoException::temVenda($pedido);
        }

        $stonePendenteOuPaga = StonePedido::where('stp_pedido_id', $pedido->id)
            ->whereIn('stp_status', [
                StonePedidoStatus::Aguardando->value,
                StonePedidoStatus::PagoParcial->value,
                StonePedidoStatus::Indeterminado->value,
                StonePedidoStatus::Pago->value,
            ])
            ->exists();

        if ($stonePendenteOuPaga) {
            return PedidoNaoPodeSerExcluidoException::cobrancaStone($pedido);
        }

        // Comissão pendente é cancelada junto com o pedido (ComissaoService);
        // aprovada ou paga já gerou obrigação com o influenciador.
        // method_exists: a comissão de influenciador chega em outra entrega.
        $comissao = method_exists($pedido, 'comissao') ? $pedido->comissao()->first() : null;

        if ($comissao && in_array($comissao->comissao_status, [StatusComissaoEnum::CREDITADA], true)) {
            return PedidoNaoPodeSerExcluidoException::comissaoAtiva($pedido);
        }

        return null;
    }

    /**
     * @throws PedidoNaoPodeSerExcluidoException
     * @throws AuthorizationException
     */
    public function excluir(Pedido $pedido, User $ator, MotivoExclusaoPedidoEnum $motivo, ?string $observacao = null): Pedido
    {
        Gate::forUser($ator)->authorize('delete', $pedido);

        $observacao = filled($observacao) ? trim($observacao) : null;

        if ($motivo === MotivoExclusaoPedidoEnum::OUTRO && mb_strlen((string) $observacao) < 10) {
            throw PedidoNaoPodeSerExcluidoException::observacaoObrigatoria();
        }

        return DB::transaction(function () use ($pedido, $ator, $motivo, $observacao) {
            $fresco = Pedido::whereKey($pedido->getKey())->lockForUpdate()->firstOrFail();

            if ($recusa = $this->recusa($fresco)) {
                throw $recusa;
            }

            if ($fresco->status() !== StatusPedidoEnum::CANCELADO) {
                $fresco = $this->statusService->rejeitar($fresco, $ator, $motivo->motivoCancelamento());
            }

            $fresco->forceFill([
                'pedido_motivo_exclusao' => $motivo->value,
                'pedido_exclusao_observacao' => $observacao,
                'pedido_usuario_excluiu_id' => $ator->id,
            ])->save();

            $fresco->delete();

            return $fresco;
        });
    }

    /** Traz o pedido de volta da lixeira. Ele retorna CANCELADO, nunca ativo. */
    public function restaurar(Pedido $pedido, User $ator): Pedido
    {
        Gate::forUser($ator)->authorize('restore', $pedido);

        return DB::transaction(function () use ($pedido) {
            $fresco = Pedido::onlyTrashed()->whereKey($pedido->getKey())->lockForUpdate()->firstOrFail();

            $fresco->restore();

            $fresco->forceFill([
                'pedido_motivo_exclusao' => null,
                'pedido_exclusao_observacao' => null,
                'pedido_usuario_excluiu_id' => null,
            ])->save();

            return $fresco;
        });
    }
}
