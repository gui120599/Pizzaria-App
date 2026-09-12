<?php

namespace App\Services;

use App\Enums\FormaPagamento;
use App\Enums\MotivoSaidaCaixa;
use App\Enums\StatusFechamentoCaixa;
use App\Models\MovimentacoesSessaoCaixa;
use App\Models\SessaoCaixa;
use App\Models\Venda;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registra movimentações MANUAIS de caixa (sangria/suprimento) e é a fonte
 * única do recompute de `sessaocaixa_saldo_final` — antes desta classe, 3
 * pontos do código escreviam esse campo com fórmulas divergentes
 * (FinalizacaoVendaService, o controller Blade legado de saída e a Stone), e o
 * último a salvar "vencia": uma sangria registrada aqui seria apagada pela
 * próxima venda finalizada. Ver App\Filament\Resources\SessoesCaixa\Support\
 * RegistrarSaidaCaixaAction / RegistrarSuprimentoCaixaAction.
 */
class MovimentacaoCaixaService
{
    public function __construct(private readonly FechamentoCaixaService $fechamentos) {}

    /** @throws ValidationException  sessão não ABERTA ou valor <= 0 */
    public function registrarSaida(
        SessaoCaixa $sessao,
        float $valor,
        FormaPagamento $formaPagamento,
        MotivoSaidaCaixa $motivo,
        string $descricao,
        ?string $observacoes = null,
    ): MovimentacoesSessaoCaixa {
        return $this->registrarMovimentoManual($sessao, 'SAIDA', $valor, $formaPagamento, $motivo, $descricao, $observacoes);
    }

    /**
     * Entrada manual (reforço de troco / aporte) — motivo sempre Suprimento,
     * não é escolhido pelo operador (é a única razão de existir desta ação).
     *
     * @throws ValidationException sessão não ABERTA ou valor <= 0
     */
    public function registrarSuprimento(
        SessaoCaixa $sessao,
        float $valor,
        FormaPagamento $formaPagamento,
        string $descricao,
        ?string $observacoes = null,
    ): MovimentacoesSessaoCaixa {
        return $this->registrarMovimentoManual($sessao, 'ENTRADA', $valor, $formaPagamento, MotivoSaidaCaixa::Suprimento, $descricao, $observacoes);
    }

    private function registrarMovimentoManual(
        SessaoCaixa $sessao,
        string $tipo,
        float $valor,
        FormaPagamento $formaPagamento,
        MotivoSaidaCaixa $motivo,
        string $descricao,
        ?string $observacoes,
    ): MovimentacoesSessaoCaixa {
        return DB::transaction(function () use ($sessao, $tipo, $valor, $formaPagamento, $motivo, $descricao, $observacoes) {
            $sessao->refresh();

            if ($sessao->sessaocaixa_status !== 'ABERTA') {
                throw ValidationException::withMessages([
                    'sessao' => 'Só é possível registrar movimentações numa sessão de caixa ABERTA.',
                ]);
            }

            if (round($valor, 2) <= 0) {
                throw ValidationException::withMessages([
                    'valor' => 'Informe um valor maior que zero.',
                ]);
            }

            $movimento = MovimentacoesSessaoCaixa::create([
                'mov_sessaocaixa_id' => $sessao->id,
                'mov_venda_id' => null,
                'mov_descricao' => $descricao,
                'mov_tipo' => $tipo,
                'mov_forma_pagamento' => $formaPagamento,
                'mov_motivo' => $motivo,
                'mov_user_id' => auth()->id(),
                'mov_valor' => round($valor, 2),
                'mov_observacoes' => $observacoes,
            ]);

            $this->recalcularSaldoFinal($sessao);

            // Se já existe um fechamento em rascunho pra esta sessão, refaz o
            // snapshot do esperado na hora — senão a conferência só reflete a
            // sangria/suprimento na próxima vez que o rascunho for salvo.
            $fechamento = $sessao->fechamentoCaixa()->first();
            if ($fechamento && $fechamento->status === StatusFechamentoCaixa::Rascunho) {
                $this->fechamentos->criarOuAtualizarRascunho($sessao, $fechamento);
            }

            return $movimento;
        });
    }

    /**
     * Recompute canônico e idempotente de sessaocaixa_saldo_final — chamado por
     * toda escrita que afeta o saldo do turno (venda finalizada, estorno Stone,
     * sangria, suprimento). Substitui as fórmulas divergentes que cada ponto
     * escrevia direto (uma usava venda_valor_total, outras venda_valor_pago; o
     * controller legado de saída fazia um delta incremental sobre o valor
     * anterior). Sempre recalcula do zero a partir do banco — seguro mesmo com
     * escritas concorrentes.
     *
     * saldo_final = saldo_inicial
     *             + SUM(vendas FINALIZADA da sessão -> venda_valor_pago)
     *             + SUM(movimentações ENTRADA manuais)   -- mov_motivo preenchido
     *             - SUM(movimentações SAIDA manuais)     -- mov_motivo preenchido
     *
     * "Manuais" (mov_motivo IS NOT NULL) exclui os movimentos automáticos de
     * venda/abertura (dobrariam a soma) e o estorno compensatório da Stone
     * (mov_venda_id preenchido, mov_motivo nulo) — o efeito daquele estorno já
     * vem da queda de venda_valor_pago.
     */
    public function recalcularSaldoFinal(SessaoCaixa $sessao): float
    {
        $totalVendas = (float) Venda::where('venda_sessao_caixa_id', $sessao->id)
            ->where('venda_status', 'FINALIZADA')
            ->sum('venda_valor_pago');

        $totalEntradasManuais = (float) MovimentacoesSessaoCaixa::where('mov_sessaocaixa_id', $sessao->id)
            ->where('mov_tipo', 'ENTRADA')
            ->manuais()
            ->sum('mov_valor');

        $totalSaidasManuais = (float) MovimentacoesSessaoCaixa::where('mov_sessaocaixa_id', $sessao->id)
            ->where('mov_tipo', 'SAIDA')
            ->manuais()
            ->sum('mov_valor');

        $saldoFinal = round(
            (float) $sessao->sessaocaixa_saldo_inicial + $totalVendas + $totalEntradasManuais - $totalSaidasManuais,
            2,
        );

        $sessao->update(['sessaocaixa_saldo_final' => $saldoFinal]);

        return $saldoFinal;
    }
}
