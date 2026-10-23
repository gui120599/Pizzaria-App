<?php

namespace App\Services\Financeiro;

use App\Enums\Financeiro\NaturezaMovimentacao;
use App\Enums\FormaPagamento;
use App\Enums\TipoLancamento;
use App\Exceptions\FinanceiroException;
use App\Models\ContaFinanceira;
use App\Models\LancamentoPagamento;
use App\Models\MovimentacaoFinanceira;
use App\Models\SessaoCaixa;
use Illuminate\Support\Str;

/**
 * Liga cada pagamento (baixa) de lançamento à movimentação na conta
 * financeira. Chamado pelos eventos de LancamentoPagamento, então vale para
 * todos os pontos que gravam pagamento (Repeater, action de baixa, compra
 * "já pago", vale do ponto):
 * - creating: valida conta, saldo e sessão do Caixa PDV antes de gravar;
 * - created: gera a movimentação;
 * - updating: valor, data, sessão e conta não mudam depois de movimentados;
 * - deleting: estorna a movimentação.
 *
 * Pagamento sem conta (antigo, compensação) não movimenta nada. Com
 * `foraDaSessao` (fechamento/abertura de caixa), o Caixa PDV não resolve a
 * sessão aberta, não grava sessao_caixa_id e não valida o saldo da gaveta.
 */
class BaixaFinanceira
{
    public function __construct(
        private readonly MovimentacaoService $movimentacoes,
        private readonly CaixaPdvBridge $caixaPdv,
    ) {}

    /** @throws FinanceiroException */
    public function preparar(LancamentoPagamento $pagamento): void
    {
        if ($pagamento->conta_financeira_id === null) {
            return;
        }

        // Compensação quita Pagar × Receber sem dinheiro de verdade.
        if ($pagamento->forma_pagamento === FormaPagamento::Compensacao) {
            $pagamento->conta_financeira_id = null;

            return;
        }

        $conta = ContaFinanceira::query()->find($pagamento->conta_financeira_id)
            ?? throw new FinanceiroException('A conta financeira escolhida está desativada.');

        if ($pagamento->foraDaSessao) {
            return;
        }

        $sessao = $pagamento->sessao_caixa_id !== null ? SessaoCaixa::query()->find($pagamento->sessao_caixa_id) : null;

        if (self::ehSaida($pagamento)) {
            $sessao = $this->movimentacoes->validarSaida($conta, (float) $pagamento->valor, $sessao);
        } elseif ($conta->ehCaixaPdv()) {
            $sessao = $this->caixaPdv->sessaoParaMovimentar($sessao);
        }

        // No Caixa PDV o pagamento guarda a sessão: a pagar vira retirada
        // ligada; a receber, a conferência do fechamento já soma como fiado.
        if ($conta->ehCaixaPdv()) {
            $pagamento->sessao_caixa_id = $sessao->id;
        }
    }

    /** @throws FinanceiroException */
    public function movimentar(LancamentoPagamento $pagamento): void
    {
        if ($pagamento->conta_financeira_id === null) {
            return;
        }

        $lancamento = $pagamento->lancamento;
        $conta = ContaFinanceira::query()->findOrFail($pagamento->conta_financeira_id);
        $sessao = $pagamento->sessao_caixa_id !== null ? SessaoCaixa::query()->find($pagamento->sessao_caixa_id) : null;
        $descricao = Str::limit($lancamento->descricao ?: "Lançamento #{$lancamento->id}", 255, '');
        $vinculos = [
            'lancamento_id' => $lancamento->id,
            'lancamento_pagamento_id' => $pagamento->id,
            'adiantamento_id' => $lancamento->adiantamento_id,
        ];

        if (self::ehSaida($pagamento)) {
            $this->movimentacoes->saida(
                $conta,
                (float) $pagamento->valor,
                $lancamento->adiantamento_id !== null ? NaturezaMovimentacao::Adiantamento : NaturezaMovimentacao::PagamentoLancamento,
                $pagamento->data_pagamento ?? now(),
                $descricao,
                $vinculos,
                auth()->user(),
                $sessao,
                validarSaldo: ! $pagamento->foraDaSessao,
                registrarNaSessao: ! $pagamento->foraDaSessao,
                sessaoCaixaId: $pagamento->foraDaSessao ? $lancamento->sessao_caixa_id : null,
            );

            return;
        }

        $this->movimentacoes->entrada(
            $conta,
            (float) $pagamento->valor,
            NaturezaMovimentacao::RecebimentoLancamento,
            $pagamento->data_pagamento ?? now(),
            $descricao,
            $vinculos,
            auth()->user(),
            $sessao,
            registrarNaSessao: false,
            sessaoCaixaId: $pagamento->foraDaSessao ? $lancamento->sessao_caixa_id : null,
        );
    }

    /** @throws FinanceiroException */
    public function garantirImutavel(LancamentoPagamento $pagamento): void
    {
        if ($pagamento->isDirty('conta_financeira_id')) {
            throw new FinanceiroException('A conta de um pagamento não muda depois de registrado: estorne o pagamento e registre de novo.');
        }

        if ($pagamento->isDirty(['valor', 'data_pagamento', 'sessao_caixa_id']) && $this->movimentacaoAtiva($pagamento) !== null) {
            throw new FinanceiroException('Este pagamento já movimentou uma conta financeira: para mudar valor, data ou sessão, estorne e registre de novo.');
        }
    }

    /** @throws FinanceiroException */
    public function estornar(LancamentoPagamento $pagamento): void
    {
        $movimentacao = $this->movimentacaoAtiva($pagamento);

        if ($movimentacao !== null) {
            $this->movimentacoes->reverter($movimentacao, $pagamento->motivoEstorno ?? 'Pagamento estornado', auth()->user());
        }
    }

    private function movimentacaoAtiva(LancamentoPagamento $pagamento): ?MovimentacaoFinanceira
    {
        return MovimentacaoFinanceira::query()
            ->where('lancamento_pagamento_id', $pagamento->id)
            ->efetivas()
            ->first();
    }

    private static function ehSaida(LancamentoPagamento $pagamento): bool
    {
        return $pagamento->lancamento?->tipo === TipoLancamento::Pagar;
    }
}
