<?php

namespace App\Services\Financeiro;

use App\Enums\FormaPagamento;
use App\Enums\StatusLancamento;
use App\Enums\TipoLancamento;
use App\Exceptions\FinanceiroException;
use App\Models\ContaFinanceira;
use App\Models\Lancamento;
use App\Models\LancamentoPagamento;
use App\Models\SessaoCaixa;
use Illuminate\Support\Facades\DB;

/**
 * "Pagamento de despesa" registrado na sessão de caixa: toda despesa paga
 * com dinheiro da gaveta passa por um título a pagar (escolhido ou criado
 * na hora) e a retirada nasce da baixa dele pelo Caixa PDV. Assim uma
 * despesa é sempre 1 lançamento + 1 movimentação + 1 retirada (sem duplicar
 * no DRE nem na gaveta).
 */
class DespesaCaixaService
{
    /**
     * @param  array{descricao: string, plano_despesa_id: int, favorecido_id?: int|null, observacoes?: string|null}|null  $novoTitulo
     *
     * @throws FinanceiroException
     */
    public function pagar(SessaoCaixa $sessao, float $valor, ?Lancamento $titulo = null, ?array $novoTitulo = null, ?string $observacoes = null): LancamentoPagamento
    {
        $valor = round($valor, 2);
        $caixaPdv = ContaFinanceira::caixaPdv()
            ?? throw new FinanceiroException('Cadastre a conta financeira "Caixa PDV" antes de pagar despesas pelo caixa.');

        return DB::transaction(function () use ($sessao, $valor, $titulo, $novoTitulo, $observacoes, $caixaPdv): LancamentoPagamento {
            if ($titulo !== null) {
                $titulo = Lancamento::query()->lockForUpdate()->findOrFail($titulo->id);

                if ($titulo->tipo !== TipoLancamento::Pagar || ! in_array($titulo->status, [StatusLancamento::Pendente, StatusLancamento::Parcial], true)) {
                    throw new FinanceiroException('Escolha um título a pagar em aberto.');
                }

                if ($valor > round($titulo->valorRestante, 2) + 0.001) {
                    throw new FinanceiroException('O valor passa do que falta pagar no título (R$ '.number_format($titulo->valorRestante, 2, ',', '.').').');
                }
            } elseif ($novoTitulo !== null) {
                $titulo = Lancamento::create([
                    'tipo' => TipoLancamento::Pagar,
                    'descricao' => $novoTitulo['descricao'],
                    'plano_despesa_id' => $novoTitulo['plano_despesa_id'],
                    'favorecido_id' => $novoTitulo['favorecido_id'] ?? null,
                    'valor' => $valor,
                    'vencimento' => today()->toDateString(),
                    'competencia' => today()->startOfMonth()->toDateString(),
                    'status' => StatusLancamento::Pendente,
                    'observacoes' => $novoTitulo['observacoes'] ?? null,
                ]);
            } else {
                throw new FinanceiroException('Pagamento de despesa precisa de um título a pagar: escolha um ou crie na hora.');
            }

            return $titulo->registrarPagamento(
                valor: $valor,
                forma: FormaPagamento::Dinheiro,
                observacoes: $observacoes,
                sessaoCaixaId: $sessao->id,
                contaFinanceiraId: $caixaPdv->id,
            );
        });
    }
}
