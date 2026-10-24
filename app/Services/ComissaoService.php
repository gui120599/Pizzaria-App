<?php

namespace App\Services;

use App\Enums\StatusComissaoEnum;
use App\Enums\TipoDescontoEnum;
use App\Enums\TipoMovimentoCarteiraEnum;
use App\Models\Comissao;
use App\Models\Influenciador;
use App\Models\ItensPedido;
use App\Models\OpcoesPagamento;
use App\Models\PagamentosVenda;
use App\Models\Pedido;
use App\Models\Venda;
use App\Notifications\ComissaoCreditadaNotification;
use App\Notifications\ComissaoEstornadaNotification;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo de vida da comissão de influenciador: PENDENTE (valor estimado, pedido
 * ainda não pago) → CREDITADA (na carteira, quando a venda é paga) → ESTORNADA;
 * ou PENDENTE → CANCELADA. Chamado de vários pontos (PedidoObserver,
 * VendaObserver, PedidoStatusService, CancelamentoVendaService) porque nem toda
 * mudança de pedido dispara eventos Eloquent. Todos os métodos são idempotentes.
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

    /**
     * Acompanha a troca ou remoção do cupom de um pedido ainda não pago: a
     * comissão pendente passa a refletir o novo cupom (ou é cancelada). Comissão
     * creditada nunca é mexida aqui.
     */
    public function sincronizarComCupom(Pedido $pedido): void
    {
        $pedido = $pedido->fresh();
        $cupom = $pedido->cupom;
        $influenciador = $cupom?->influenciador;
        $comissao = Comissao::where('comissao_pedido_id', $pedido->id)->first();

        if (! $influenciador) {
            if ($comissao?->comissao_status === StatusComissaoEnum::PENDENTE) {
                $comissao->forceFill(['comissao_status' => StatusComissaoEnum::CANCELADA])->save();
            }

            return;
        }

        if (! $comissao) {
            $this->criarSeAplicavel($pedido);

            return;
        }

        if (! in_array($comissao->comissao_status, [StatusComissaoEnum::PENDENTE, StatusComissaoEnum::CANCELADA], true)) {
            return;
        }

        $comissao->forceFill([
            'comissao_influenciador_id' => $influenciador->id,
            'comissao_cupom_id' => $cupom->id,
            'comissao_base_calculo' => $pedido->pedido_valor_total,
            'comissao_tipo' => $influenciador->influenciador_tipo_comissao,
            'comissao_valor_referencia' => $influenciador->influenciador_valor_comissao,
            'comissao_valor' => $this->calcularValor($influenciador, (float) $pedido->pedido_valor_total),
            'comissao_status' => StatusComissaoEnum::PENDENTE,
        ])->save();
    }

    /**
     * Credita a comissão na carteira quando a venda do pedido é paga. A base é
     * recalculada na hora: soma dos itens líquidos (já sem o cupom e sem frete),
     * menos a parte paga com carteira de influenciador. Idempotente: só age
     * sobre comissão PENDENTE, e o crédito sai na mesma transação.
     */
    public function creditar(Pedido $pedido): void
    {
        $creditada = DB::transaction(function () use ($pedido): ?Comissao {
            $comissao = Comissao::where('comissao_pedido_id', $pedido->id)->lockForUpdate()->first();

            if (! $comissao || $comissao->comissao_status !== StatusComissaoEnum::PENDENTE) {
                return null;
            }

            $influenciador = Influenciador::find($comissao->comissao_influenciador_id);

            if (! $influenciador) {
                return null;
            }

            $base = $this->baseDeCalculo($pedido);
            $valor = $base > 0 ? $this->calcularValor($comissao->comissao_tipo, (float) $comissao->comissao_valor_referencia, $base) : 0.0;

            $comissao->forceFill([
                'comissao_base_calculo' => $base,
                'comissao_valor' => $valor,
                'comissao_status' => StatusComissaoEnum::CREDITADA,
                'comissao_creditada_em' => now(),
            ])->save();

            if ($valor > 0) {
                app(CarteiraInfluenciadorService::class)->lancar($influenciador, TipoMovimentoCarteiraEnum::CREDITO_COMISSAO, $valor, [
                    'comissao_id' => $comissao->id,
                    'observacao' => "Pedido #{$pedido->id}",
                ]);
            }

            return $comissao;
        });

        if ($creditada && (float) $creditada->comissao_valor > 0) {
            $this->notificarDepoisDoCommit($creditada, new ComissaoCreditadaNotification($creditada));
        }
    }

    /**
     * Pedido cancelado: comissão pendente é cancelada; já creditada é estornada
     * (movimento inverso na carteira, que pode ficar negativa).
     */
    public function cancelar(Pedido $pedido): void
    {
        $this->reverter($pedido, StatusComissaoEnum::ESTORNADA, StatusComissaoEnum::CANCELADA, "Pedido #{$pedido->id} cancelado");
    }

    /**
     * Venda cancelada/reaberta no PDV (o pedido não foi cancelado): estorna o
     * crédito e volta a comissão a PENDENTE, para creditar de novo quando a
     * venda for paga outra vez.
     */
    public function reabrir(Pedido $pedido): void
    {
        $this->reverter($pedido, StatusComissaoEnum::PENDENTE, StatusComissaoEnum::PENDENTE, "Venda do pedido #{$pedido->id} reaberta");
    }

    private function reverter(Pedido $pedido, StatusComissaoEnum $quandoCreditada, StatusComissaoEnum $quandoPendente, string $observacao): void
    {
        $estornada = DB::transaction(function () use ($pedido, $quandoCreditada, $quandoPendente, $observacao): ?Comissao {
            $comissao = Comissao::where('comissao_pedido_id', $pedido->id)->lockForUpdate()->first();

            if (! $comissao) {
                return null;
            }

            if ($comissao->comissao_status === StatusComissaoEnum::PENDENTE) {
                if ($quandoPendente !== StatusComissaoEnum::PENDENTE) {
                    $comissao->forceFill(['comissao_status' => $quandoPendente])->save();
                }

                return null;
            }

            if ($comissao->comissao_status !== StatusComissaoEnum::CREDITADA) {
                return null;
            }

            $influenciador = Influenciador::find($comissao->comissao_influenciador_id);
            $creditado = (float) $comissao->movimentos()
                ->where('cm_tipo', TipoMovimentoCarteiraEnum::CREDITO_COMISSAO)->sum('cm_valor');

            if ($influenciador && $creditado > 0) {
                app(CarteiraInfluenciadorService::class)->lancar($influenciador, TipoMovimentoCarteiraEnum::ESTORNO_COMISSAO, -$creditado, [
                    'comissao_id' => $comissao->id,
                    'observacao' => $observacao,
                ]);
            }

            $comissao->forceFill([
                'comissao_status' => $quandoCreditada,
                'comissao_creditada_em' => $quandoCreditada === StatusComissaoEnum::PENDENTE ? null : $comissao->comissao_creditada_em,
            ])->save();

            return $comissao;
        });

        if ($estornada && (float) $estornada->comissao_valor > 0) {
            $this->notificarDepoisDoCommit($estornada, new ComissaoEstornadaNotification($estornada));
        }
    }

    /** Soma dos itens líquidos do pedido (sem frete, já com o cupom) menos a parte paga com carteira. */
    private function baseDeCalculo(Pedido $pedido): float
    {
        $itens = round((float) ItensPedido::where('item_pedido_pedido_id', $pedido->id)
            ->where('item_pedido_status', 'INSERIDO')
            ->sum('item_pedido_valor'), 2);

        return round(max(0.0, $itens * (1 - $this->fracaoPagaComCarteira($pedido))), 2);
    }

    /** Fração (0 a 1) da venda do pedido paga com a forma "Carteira de influenciador". */
    private function fracaoPagaComCarteira(Pedido $pedido): float
    {
        $vendaId = $pedido->pedido_venda_id ?? ItensPedido::where('item_pedido_pedido_id', $pedido->id)->whereNotNull('item_pedido_venda_id')->value('item_pedido_venda_id');

        if (! $vendaId) {
            return 0.0;
        }

        $opcoesCarteira = OpcoesPagamento::withTrashed()->where('opcaopag_carteira_influenciador', true)->pluck('id');

        if ($opcoesCarteira->isEmpty()) {
            return 0.0;
        }

        $pagoComCarteira = (float) PagamentosVenda::where('pg_venda_venda_id', $vendaId)
            ->whereIn('pg_venda_opcaopagamento_id', $opcoesCarteira)
            ->sum('pg_venda_valor_pagamento');

        $totalVenda = (float) Venda::whereKey($vendaId)->value('venda_valor_total');

        return $totalVenda > 0 ? min(1.0, $pagoComCarteira / $totalVenda) : 0.0;
    }

    private function calcularValor(Influenciador|TipoDescontoEnum $quem, float $referenciaOuBase, ?float $base = null): float
    {
        if ($quem instanceof Influenciador) {
            $tipo = $quem->influenciador_tipo_comissao;
            $referencia = (float) $quem->influenciador_valor_comissao;
            $base = $referenciaOuBase;
        } else {
            $tipo = $quem;
            $referencia = $referenciaOuBase;
        }

        $valor = $tipo === TipoDescontoEnum::PERCENTUAL ? $base * ($referencia / 100) : $referencia;

        return round($valor, 2);
    }

    /** Notificação só depois do commit da transação que mexeu na carteira. */
    private function notificarDepoisDoCommit(Comissao $comissao, object $notificacao): void
    {
        DB::afterCommit(fn () => $comissao->influenciador?->notify($notificacao));
    }
}
