<?php

namespace App\Services;

use App\Enums\FormaPagamento;
use App\Enums\StatusFechamentoCaixa;
use App\Models\MovimentacoesSessaoCaixa;
use App\Models\SessaoCaixa;

class SessaoCaixaService
{
    /**
     * Um Caixa só pode ser reaberto depois que o fechamento (Filament, camada de
     * auditoria) da sua última sessão FECHADA for confirmado — evita pular a
     * conferência de um turno pra abrir o próximo. Usado tanto pelo fluxo legado
     * (SessaoCaixaController::store) quanto pelo Resource Filament (SessaoCaixaForm).
     */
    public function motivoBloqueioAbertura(int $caixaId): ?string
    {
        $ultimaFechada = SessaoCaixa::query()
            ->where('sessaocaixa_caixa_id', $caixaId)
            ->where('sessaocaixa_status', 'FECHADA')
            ->orderByDesc('sessaocaixa_data_hora_fechamento')
            ->first();

        if (! $ultimaFechada) {
            return null;
        }

        $fechamento = $ultimaFechada->fechamentoCaixa;

        if (! $fechamento || $fechamento->status !== StatusFechamentoCaixa::Confirmado) {
            return 'A última sessão deste caixa ainda não teve o fechamento confirmado. Confirme o fechamento antes de abrir uma nova sessão.';
        }

        return null;
    }

    /**
     * Registra o saldo de abertura como movimento(s) ENTRADA por forma de
     * pagamento (chaves de App\Enums\FormaPagamento), pra
     * FechamentoCaixaService::calcularEsperado() somar junto com vendas e
     * recebimentos de fiado — sem isso o troco inicial nunca entrava no
     * "esperado" e a conferência sempre acusava sobra de dinheiro.
     *
     * @param  array<string, float|string>  $valoresPorFormaPagamento
     */
    public function registrarMovimentoAbertura(SessaoCaixa $sessao, array $valoresPorFormaPagamento): void
    {
        foreach ($valoresPorFormaPagamento as $formaPagamento => $valor) {
            $valor = round((float) $valor, 2);

            if ($valor <= 0) {
                continue;
            }

            MovimentacoesSessaoCaixa::create([
                'mov_sessaocaixa_id' => $sessao->id,
                'mov_descricao' => 'Saldo inicial de abertura',
                'mov_tipo' => 'ENTRADA',
                'mov_forma_pagamento' => $formaPagamento,
                'mov_valor' => $valor,
            ]);
        }
    }

    /**
     * Fecha o ciclo da abertura depois que o Repeater 'notas' já persistiu as
     * linhas relacionadas: soma o dinheiro contado, grava saldo_inicial/final
     * e registra o movimento (só dinheiro — débito/crédito/Pix da abertura
     * ficam como carryover por maquininha, abatido no fechamento, não
     * somado ao esperado, ver FechamentoCaixa::totalPorCategoria).
     */
    public function finalizarAbertura(SessaoCaixa $sessao): void
    {
        $totalDinheiro = round((float) $sessao->notas()->sum('valor_total'), 2);

        $sessao->update([
            'sessaocaixa_saldo_inicial' => $totalDinheiro,
            'sessaocaixa_saldo_final' => $totalDinheiro,
        ]);

        $this->registrarMovimentoAbertura($sessao, [FormaPagamento::Dinheiro->value => $totalDinheiro]);
    }
}
