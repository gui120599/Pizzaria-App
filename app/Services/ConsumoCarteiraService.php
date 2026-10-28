<?php

namespace App\Services;

use App\Enums\TipoMovimentoCarteiraEnum;
use App\Exceptions\CarteiraInfluenciadorException;
use App\Models\CarteiraMovimento;
use App\Models\CodigoResgate;
use App\Models\PagamentosVenda;
use App\Models\Venda;
use Illuminate\Support\Facades\DB;

/**
 * Consumo em pizza: o próprio influenciador paga (parte de) uma venda com o
 * saldo da carteira, provando a identidade com o código de resgate. O débito, o
 * uso do código e o vínculo com o pagamento ficam na mesma transação.
 */
class ConsumoCarteiraService
{
    public function __construct(private readonly CarteiraInfluenciadorService $carteira) {}

    /**
     * Valor máximo que o código pode consumir agora: o menor entre o saldo e o restante a pagar.
     */
    public function limite(CodigoResgate $codigo, float $restante): float
    {
        return max(0.0, round(min($this->carteira->saldo($codigo->cr_influenciador_id), $restante), 2));
    }

    /**
     * Debita o consumo e marca o código como usado. Chamar dentro da transação
     * que grava o PagamentosVenda.
     *
     * @throws CarteiraInfluenciadorException
     */
    public function consumir(CodigoResgate $codigo, PagamentosVenda $pagamento, float $valor): CarteiraMovimento
    {
        return DB::transaction(function () use ($codigo, $pagamento, $valor): CarteiraMovimento {
            $travado = CodigoResgate::whereKey($codigo->id)->lockForUpdate()->firstOrFail();

            if (! $travado->estaVigente()) {
                throw new CarteiraInfluenciadorException('Código inválido, vencido ou já usado.');
            }

            $movimento = $this->carteira->debitar($travado->influenciador, TipoMovimentoCarteiraEnum::CONSUMO, $valor, [
                'pagamento_venda_id' => $pagamento->id,
                'observacao' => "Venda #{$pagamento->pg_venda_venda_id}",
            ]);

            $travado->update(['cr_usado_em' => now(), 'cr_pagamento_venda_id' => $pagamento->id]);

            return $movimento;
        });
    }

    /**
     * Devolve à carteira o que o pagamento ainda tem debitado (consumo menos
     * estornos já feitos). Idempotente; chamado ao remover o pagamento e ao
     * cancelar a venda.
     */
    public function estornarDoPagamento(int $pagamentoId): void
    {
        DB::transaction(function () use ($pagamentoId): void {
            $movimentos = CarteiraMovimento::where('cm_pagamento_venda_id', $pagamentoId)
                ->whereIn('cm_tipo', [TipoMovimentoCarteiraEnum::CONSUMO, TipoMovimentoCarteiraEnum::ESTORNO_CONSUMO])
                ->get();

            $debitado = -round((float) $movimentos->sum('cm_valor'), 2);

            if ($debitado < 0.01) {
                return;
            }

            $influenciador = $movimentos->first()->influenciador;

            $this->carteira->lancar($influenciador, TipoMovimentoCarteiraEnum::ESTORNO_CONSUMO, $debitado, [
                'pagamento_venda_id' => $pagamentoId,
                'observacao' => "Pagamento #{$pagamentoId} estornado",
            ]);
        });
    }

    /** Estorna o consumo de todos os pagamentos da venda (venda cancelada). */
    public function estornarDaVenda(Venda $venda): void
    {
        PagamentosVenda::where('pg_venda_venda_id', $venda->id)
            ->pluck('id')
            ->each(fn (int $id) => $this->estornarDoPagamento($id));
    }
}
