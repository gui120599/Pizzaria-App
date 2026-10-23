<?php

namespace App\Services\Compras;

use App\Enums\CompraStatusEnum;
use App\Models\Compra;
use App\Models\CompraItem;
use App\Models\Empresa;
use App\Services\Compras\Dto\ClassificacaoItem;
use App\Services\Compras\Dto\ClassificacaoOperacao;
use App\Services\Compras\Dto\ContextoClassificacao;
use App\Services\Nfe\Dto\NfeItem;
use App\Services\Nfe\Dto\NfeParseada;

/**
 * Cola entre a NF-e parseada e a Compra: monta o contexto do classificador,
 * classifica e devolve os atributos fiscais/de sugestão das colunas
 * compra_* e ci_*. Só descreve — nunca mexe em estoque, financeiro, status
 * ou valores da compra.
 */
class CompraFiscalService
{
    public function __construct(private ClassificadorOperacaoEntrada $classificador) {}

    /** CNPJ da empresa (só dígitos), ou null se ainda não estiver cadastrado. */
    public function cnpjEmpresa(): ?string
    {
        $cnpj = preg_replace('/\D/', '', (string) Empresa::query()->value('empresa_cnpj')) ?? '';

        return $cnpj === '' ? null : $cnpj;
    }

    /**
     * @param  int|null  $ignorarCompraId  compra já existente que está sendo reclassificada:
     *                                     ela própria não conta como histórico do fornecedor
     */
    public function classificar(NfeParseada $nfe, ?int $prestadorId, ?int $ignorarCompraId = null): ClassificacaoOperacao
    {
        $historico = $prestadorId !== null && Compra::query()
            ->where('compra_prestador_id', $prestadorId)
            ->where('compra_status', CompraStatusEnum::CONFIRMADA)
            ->when($ignorarCompraId !== null, fn ($query) => $query->whereKeyNot($ignorarCompraId))
            ->exists();

        $nossas = $nfe->notasReferenciadas === [] ? [] : Compra::withTrashed()
            ->whereIn('compra_chave_nfe', $nfe->notasReferenciadas)
            ->pluck('compra_chave_nfe')
            ->all();

        return $this->classificador->classificar($nfe, new ContextoClassificacao(
            empresaCnpj: $this->cnpjEmpresa(),
            fornecedorTemHistorico: $historico,
            notasReferenciadasNossas: $nossas,
        ));
    }

    /**
     * Colunas fiscais e de sugestão do cabeçalho da compra.
     *
     * @return array<string, mixed>
     */
    public function atributosCompra(NfeParseada $nfe, ClassificacaoOperacao $classificacao): array
    {
        return [
            'compra_tp_nf' => $nfe->tipoNf,
            'compra_fin_nfe' => $nfe->finalidade,
            'compra_nat_op' => $nfe->naturezaOperacao,
            'compra_dest_documento' => $nfe->destinatarioDocumento,
            'compra_valor_nf' => $nfe->valorTotalNota,
            'compra_valor_st' => $nfe->valorSt,
            'compra_valor_ipi' => $nfe->valorIpi,
            'compra_valor_seguro' => $nfe->valorSeguro,
            'compra_valor_fcp_st' => $nfe->valorFcpSt,
            'compra_notas_referenciadas' => $nfe->notasReferenciadas,
            'compra_pagamentos_nf' => array_map(fn ($p) => $p->toArray(), $nfe->pagamentos),
            'compra_duplicatas_nf' => array_map(fn ($d) => $d->toArray(), $nfe->duplicatas),
            'compra_operacao' => $classificacao->valorCabecalho(),
            'compra_operacao_confianca' => $classificacao->confianca->value,
            'compra_operacao_criterios' => $classificacao->detalhes(),
        ];
    }

    /**
     * Colunas fiscais e de sugestão de um item.
     *
     * @return array<string, mixed>
     */
    public function atributosItem(NfeItem $item, ClassificacaoItem $classificacao): array
    {
        return [
            'ci_cfop' => $item->cfop,
            'ci_cst_csosn' => $item->cstCsosn,
            'ci_operacao' => $classificacao->operacao->value,
            'ci_valor_st' => $item->valorSt,
            'ci_valor_ipi' => $item->valorIpi,
            'ci_valor_fcp_st' => $item->valorFcpSt,
        ];
    }

    /**
     * Grava só as colunas fiscais e de sugestão numa compra já existente,
     * casando os itens do XML com os da compra pelo código do fornecedor.
     * Usado pelo comando compras:diagnosticar-operacoes --gravar.
     */
    public function gravar(Compra $compra, NfeParseada $nfe, ClassificacaoOperacao $classificacao): void
    {
        $compra->forceFill($this->atributosCompra($nfe, $classificacao))->save();

        $disponiveis = $compra->itens()->get()->all();

        foreach ($nfe->itens as $indice => $itemNfe) {
            foreach ($disponiveis as $posicao => $itemCompra) {
                /** @var CompraItem $itemCompra */
                if ((string) $itemCompra->ci_codigo_fornecedor !== $itemNfe->codigoFornecedor) {
                    continue;
                }

                $itemCompra->forceFill($this->atributosItem($itemNfe, $classificacao->porItem[$indice]))->save();
                unset($disponiveis[$posicao]);

                break;
            }
        }
    }
}
