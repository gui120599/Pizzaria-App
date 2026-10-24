<?php

namespace App\Services;

use App\Enums\StatusComissaoEnum;
use App\Enums\TipoDescontoEnum;
use App\Models\Comissao;
use App\Models\Influenciador;
use App\Models\Pedido;
use App\Notifications\ComissaoAprovadaNotification;

/**
 * Ciclo de vida da comissão de influenciador — chamado de múltiplos pontos
 * do sistema (ver PedidoObserver e VendaObserver) porque nem toda mudança de
 * pedido_status dispara eventos Eloquent (VendaObserver::finalizarPedidosEMesas
 * usa updateQuietly()). Todos os métodos são idempotentes de propósito.
 */
class ComissaoService
{
    public function criarSeAplicavel(Pedido $pedido): void
    {
        $cupom = $pedido->cupom;

        if (! $cupom || ! $cupom->cupom_influenciador_id) {
            return;
        }

        $influenciador = $cupom->influenciador;

        if (! $influenciador) {
            return;
        }

        Comissao::firstOrCreate(
            ['comissao_pedido_id' => $pedido->id],
            [
                'comissao_influenciador_id' => $influenciador->id,
                'comissao_cupom_id' => $cupom->id,
                'comissao_base_calculo' => $pedido->pedido_valor_total,
                'comissao_tipo' => $influenciador->influenciador_tipo_comissao,
                'comissao_valor_referencia' => $influenciador->influenciador_valor_comissao,
                'comissao_valor' => $this->calcularValor($influenciador, (float) $pedido->pedido_valor_total),
                'comissao_status' => StatusComissaoEnum::PENDENTE,
            ]
        );
    }

    /** Nunca reabre uma comissão já paga — só promove pendente → aprovada. */
    public function aprovar(Pedido $pedido): void
    {
        $comissao = Comissao::where('comissao_pedido_id', $pedido->id)->first();

        if (! $comissao || $comissao->comissao_status !== StatusComissaoEnum::PENDENTE) {
            return;
        }

        $comissao->forceFill([
            'comissao_status' => StatusComissaoEnum::APROVADA,
            'comissao_aprovada_em' => now(),
        ])->save();

        $comissao->influenciador?->notify(new ComissaoAprovadaNotification($comissao));
    }

    /** Nunca cancela uma comissão já paga. */
    public function cancelar(Pedido $pedido): void
    {
        $comissao = Comissao::where('comissao_pedido_id', $pedido->id)->first();

        if (! $comissao || $comissao->comissao_status === StatusComissaoEnum::PAGA) {
            return;
        }

        if ($comissao->comissao_status === StatusComissaoEnum::CANCELADA) {
            return;
        }

        $comissao->forceFill(['comissao_status' => StatusComissaoEnum::CANCELADA])->save();
    }

    private function calcularValor(Influenciador $influenciador, float $baseCalculo): float
    {
        $valor = $influenciador->influenciador_tipo_comissao === TipoDescontoEnum::PERCENTUAL
            ? $baseCalculo * ((float) $influenciador->influenciador_valor_comissao / 100)
            : (float) $influenciador->influenciador_valor_comissao;

        return round($valor, 2);
    }
}
