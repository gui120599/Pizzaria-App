<?php

namespace App\Services\Financeiro;

use App\Enums\FormaPagamento;
use App\Enums\StatusLancamento;
use App\Enums\TipoLancamento;
use App\Exceptions\FinanceiroException;
use App\Models\ContaFinanceira;
use App\Models\Empresa;
use App\Models\Lancamento;
use App\Models\LancamentoPagamento;
use App\Models\Maquininha;
use App\Models\OpcoesPagamento;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Recebimento de título fiado no PDV, já no razão. A conta vem da forma:
 * dinheiro vai para o Caixa PDV (a sessão conta por conta própria, ver
 * BaixaFinanceira); cartão/Pix de maquininha entra direto na conta da
 * maquininha, com a taxa como despesa; PIX CNPJ vai para a conta da opção.
 *
 * Cartão/Pix de maquininha NÃO vira recebível de adquirente: o título do
 * fiado já é a receita, e um segundo título somaria a receita duas vezes na
 * DRE. O dinheiro entra na data do recebimento, não em D+n, porque o volume
 * é pequeno e a alternativa duplicaria a receita.
 */
class RecebimentoFiadoService
{
    /** @throws FinanceiroException */
    public function receber(
        Lancamento $titulo,
        float $valor,
        ?FormaPagamento $forma,
        ?string $observacoes,
        ?int $sessaoCaixaId,
        ?int $cartaoId = null,
        ?string $numeroAutorizacaoCartao = null,
        ?int $maquininhaId = null,
    ): LancamentoPagamento {
        return DB::transaction(function () use ($titulo, $valor, $forma, $observacoes, $sessaoCaixaId, $cartaoId, $numeroAutorizacaoCartao, $maquininhaId): LancamentoPagamento {
            $pagamento = $titulo->registrarPagamento(
                valor: $valor,
                forma: $forma,
                observacoes: $observacoes,
                sessaoCaixaId: $sessaoCaixaId,
                cartaoId: $cartaoId,
                numeroAutorizacaoCartao: $numeroAutorizacaoCartao,
                maquininhaId: $maquininhaId,
                contaFinanceiraId: $this->contaDe($forma, $maquininhaId)?->id,
            );

            if ($pagamento->conta_financeira_id !== null && $forma?->tipoMaquininha() !== null) {
                $this->lancarTaxa($titulo, $pagamento);
            }

            return $pagamento;
        });
    }

    /** Conta onde o dinheiro do recebimento entra; nula quando a forma não tem conta cadastrada. */
    public function contaDe(?FormaPagamento $forma, ?int $maquininhaId): ?ContaFinanceira
    {
        if ($forma === null) {
            return null;
        }

        if ($forma === FormaPagamento::Dinheiro) {
            return ContaFinanceira::caixaPdv();
        }

        if ($forma === FormaPagamento::PixCnpj) {
            return OpcoesPagamento::query()->where('opcaopag_pix_cnpj', true)->whereNotNull('conta_financeira_id')->orderBy('id')->first()?->contaFinanceira;
        }

        if ($forma->tipoMaquininha() !== null) {
            $maquininha = $maquininhaId ? Maquininha::find($maquininhaId) : Maquininha::padrao();

            if ($maquininha?->contaFinanceira === null) {
                Log::warning('Recebimento de fiado em maquininha sem conta financeira: sem movimentação na conta.', ['maquininha_id' => $maquininha?->id]);
            }

            return $maquininha?->contaFinanceira;
        }

        return null;
    }

    private function lancarTaxa(Lancamento $titulo, LancamentoPagamento $pagamento): void
    {
        $taxa = round((float) $pagamento->taxa_valor, 2);
        $planoId = Empresa::query()->value('empresa_plano_despesa_taxa_cartao_id');

        if ($taxa <= 0 || $planoId === null) {
            return;
        }

        $despesa = Lancamento::create([
            'tipo' => TipoLancamento::Pagar,
            'plano_despesa_id' => $planoId,
            'descricao' => "Taxa de cartão — fiado recebido (título #{$titulo->id})",
            'valor' => $taxa,
            'vencimento' => $pagamento->data_pagamento,
            'status' => StatusLancamento::Pendente,
        ]);

        $despesa->registrarPagamento(
            valor: $taxa,
            data: $pagamento->data_pagamento,
            forma: FormaPagamento::Transferencia,
            observacoes: 'Taxa da adquirente descontada do fiado recebido.',
            contaFinanceiraId: $pagamento->conta_financeira_id,
            foraDaSessao: true,
        );
    }
}
