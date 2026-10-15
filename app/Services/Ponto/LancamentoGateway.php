<?php

namespace App\Services\Ponto;

use App\Enums\FormaPagamento;
use App\Enums\StatusLancamento;
use App\Enums\TipoLancamento;
use App\Exceptions\PontoException;
use App\Models\Colaborador;
use App\Models\ContaFinanceira;
use App\Models\Lancamento;
use App\Models\PlanoDespesa;
use App\Models\SessaoCaixa;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Único ponto do módulo de ponto que conhece a estrutura de `lancamentos` e
 * `lancamento_pagamentos`. Se o financeiro mudar, só esta classe muda.
 */
class LancamentoGateway
{
    public function criarContaPagar(ContaPagarDados $dados): Lancamento
    {
        return Lancamento::create([
            'tipo' => TipoLancamento::Pagar,
            'competencia' => $dados->competencia->copy()->startOfMonth()->toDateString(),
            'plano_despesa_id' => $dados->planoDespesaId,
            'favorecido_id' => $dados->favorecidoId,
            'descricao' => $dados->descricao,
            'valor' => round($dados->valor, 2),
            'vencimento' => $dados->vencimento->toDateString(),
            'status' => StatusLancamento::Pendente,
            'forma_pagamento' => $dados->formaPagamento,
            'observacoes' => $dados->observacoes,
            'repasse_id' => $dados->repasseId,
            'adiantamento_id' => $dados->adiantamentoId,
        ]);
    }

    /** Título já pago: o pagamento sai da conta escolhida (e movimenta a conta pelo evento do pagamento). */
    public function criarPago(ContaPagarDados $dados, ContaFinanceira $conta, CarbonInterface $data, ?SessaoCaixa $sessao = null): Lancamento
    {
        $lancamento = $this->criarContaPagar($dados);

        $lancamento->registrarPagamento(
            valor: round($dados->valor, 2),
            data: Carbon::instance($data),
            forma: $dados->formaPagamento,
            sessaoCaixaId: $sessao?->id,
            contaFinanceiraId: $conta->id,
        );

        return $lancamento->refresh();
    }

    /** @throws PontoException */
    public function cancelar(Lancamento $lancamento, string $motivo): void
    {
        if ($lancamento->pagamentos()->exists()) {
            throw new PontoException('O título no financeiro já tem pagamento registrado. Estorne o pagamento no financeiro antes.');
        }

        $lancamento->update([
            'status' => StatusLancamento::Cancelado,
            'observacoes' => trim(($lancamento->observacoes ?? '')."\nCancelado pelo módulo de ponto: ".trim($motivo)),
        ]);
    }

    /** Estorna as baixas (cada estorno devolve o dinheiro à conta de origem). */
    public function estornarPagamentos(Lancamento $lancamento, string $motivo): void
    {
        $lancamento->estornarPagamento($motivo);
    }

    public function estaPago(Lancamento $lancamento): bool
    {
        return $lancamento->status === StatusLancamento::Pago;
    }

    public function temPagamento(Lancamento $lancamento): bool
    {
        return in_array($lancamento->status, [StatusLancamento::Pago, StatusLancamento::Parcial], true);
    }

    /**
     * Plano de despesa do repasse/vale pelo vínculo (config ponto.plano_contas);
     * colaborador entregador usa o plano "entregador".
     *
     * @throws PontoException
     */
    public function planoDe(Colaborador $colaborador): int
    {
        $chave = $colaborador->entregador ? 'entregador' : $colaborador->vinculo->value;
        $id = config("ponto.plano_contas.{$chave}");

        if (blank($id) || ! PlanoDespesa::query()->whereKey($id)->exists()) {
            throw new PontoException("Plano de despesa do vínculo \"{$chave}\" não configurado (PONTO_PLANO_* no .env).");
        }

        return (int) $id;
    }

    /** @throws PontoException */
    public function favorecidoDe(Colaborador $colaborador): int
    {
        return $colaborador->prestador()->value('id')
            ?? throw new PontoException("{$colaborador->nome} não tem favorecido no financeiro. Salve o cadastro do colaborador de novo.");
    }

    /** PIX quando o colaborador tem chave; senão o financeiro escolhe na baixa. */
    public function formaPagamentoDe(Colaborador $colaborador): ?FormaPagamento
    {
        return filled($colaborador->chave_pix) ? FormaPagamento::Pix : null;
    }

    public function observacaoPix(Colaborador $colaborador): ?string
    {
        if (blank($colaborador->chave_pix)) {
            return null;
        }

        return "PIX ({$colaborador->tipo_chave_pix?->getLabel()}): {$colaborador->chave_pix}";
    }
}
