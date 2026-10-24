<?php

namespace App\Services\Compras;

use App\Enums\ConfiancaOperacaoEnum;
use App\Enums\OperacaoEntradaEnum;
use App\Services\Compras\Dto\ClassificacaoItem;
use App\Services\Compras\Dto\ClassificacaoOperacao;
use App\Services\Compras\Dto\ContextoClassificacao;
use App\Services\Nfe\Dto\NfeItem;
use App\Services\Nfe\Dto\NfeParseada;
use Illuminate\Support\Str;

/**
 * Sugere a operação fiscal (compra, bonificação, comodato...) de uma NF-e de
 * entrada. Função pura de NfeParseada + ContextoClassificacao: não lê banco.
 * Regras na ordem da seção 4.3 do plano docs/planos/operacoes-fiscais-entrada-nfe.md;
 * a regra 3 (protocolo ≠ 100) já é barrada pelo NfeXmlParser.
 *
 * O XML descreve a operação sob a ótica do emitente, e nenhum sinal isolado
 * decide: conflito entre sinais rebaixa a confiança em vez de escolher um lado.
 */
class ClassificadorOperacaoEntrada
{
    public function classificar(NfeParseada $nfe, ContextoClassificacao $contexto): ClassificacaoOperacao
    {
        $empresa = $this->soDigitos($contexto->empresaCnpj);

        if ($empresa !== '' && $nfe->destinatarioDocumento !== null && $nfe->destinatarioDocumento !== $empresa) {
            return $this->naoProcessavel($nfe, 'Destinatário da nota não é a empresa: documento de outra empresa, ou em que somos transportador/autXML.');
        }

        if ($empresa !== '' && $nfe->emitente->documento === $empresa) {
            return $this->naoProcessavel($nfe, 'Emitente é a própria empresa: nota própria, não é entrada de terceiro.');
        }

        $alertas = [];
        $criterios = [];

        $cabecalho = $this->classificarCabecalho($nfe, $contexto, $empresa, $criterios);

        if ($cabecalho !== null) {
            [$operacao, $confianca] = $cabecalho;
            $porItem = $this->itensComOperacao($nfe, $operacao, $confianca);
        } else {
            $porItem = [];
            $alertasPorTexto = [];
            $semPagamento = $this->vendaSemPagamento($nfe);
            foreach ($nfe->itens as $indice => $item) {
                [$operacao, $confianca, $criterio, $alerta] = $this->classificarItem($item, $nfe, $contexto, $semPagamento);
                $porItem[] = new ClassificacaoItem($indice + 1, $item->cfop, $operacao, $confianca);
                $criterios[] = 'Item '.($indice + 1).': '.$criterio;
                if ($alerta !== null) {
                    $alertasPorTexto[$alerta][] = $indice + 1;
                }
            }

            foreach ($alertasPorTexto as $texto => $numeros) {
                $alertas[] = count($numeros) === count($nfe->itens)
                    ? "Todos os itens: {$texto}"
                    : 'Item(ns) '.implode(', ', $numeros).": {$texto}";
            }
        }

        $operacoes = array_unique(array_map(fn (ClassificacaoItem $i) => $i->operacao->value, $porItem));
        $mista = count($operacoes) > 1;
        $operacaoCabecalho = $mista ? null : $porItem[0]->operacao;
        $confianca = $this->menorConfianca($porItem);

        if ($mista) {
            $alertas[] = 'Nota mista: itens com operações diferentes ('.implode(', ', array_map(
                fn (string $v) => OperacaoEntradaEnum::from($v)->label(),
                $operacoes,
            )).'). Confira item a item.';
            $confianca = $this->limitar($confianca, ConfiancaOperacaoEnum::MEDIA);
        }

        if ($nfe->tipoNf === 0 && $nfe->finalidade !== 4) {
            $alertas[] = 'Nota de ENTRADA do emitente (tpNF=0): não descreve uma venda a nós e nunca deve virar entrada de estoque.';
            $confianca = $this->limitar($confianca, ConfiancaOperacaoEnum::BAIXA);
        }

        if ($this->naturezaDivergeDaOperacao($nfe, $porItem)) {
            $alertas[] = "Natureza da operação ({$nfe->naturezaOperacao}) diverge do CFOP dos itens.";
            $confianca = $confianca->rebaixar();
        }

        $alertas = array_merge($alertas, $this->alertasDeCobranca($nfe, $porItem));

        return new ClassificacaoOperacao($operacaoCabecalho, $confianca, $criterios, $alertas, $porItem, $mista);
    }

    /**
     * Regras 4 a 8 (valem para a nota toda). Retorna [operação, confiança] ou
     * null para seguir à classificação item a item.
     *
     * @param  string[]  $criterios
     * @return array{0: OperacaoEntradaEnum, 1: ConfiancaOperacaoEnum}|null
     */
    private function classificarCabecalho(NfeParseada $nfe, ContextoClassificacao $contexto, string $empresa, array &$criterios): ?array
    {
        // finNFe=4 é devolução, qualquer que seja o tpNF: o fornecedor pode emitir a
        // nota como entrada (tpNF=0) ou como saída (tpNF=1) referente à devolução
        // que fizemos. Nunca pode virar entrada de estoque.
        if ($nfe->finalidade === 4) {
            $referenciada = array_intersect($nfe->notasReferenciadas, $contexto->notasReferenciadasNossas) !== [];
            $criterios[] = "finNFe=4 e tpNF={$nfe->tipoNf}: devolução emitida pelo fornecedor"
                .($referenciada ? ', com NFref para compra nossa.' : ', sem NFref para compra nossa.');

            return [OperacaoEntradaEnum::DEVOLUCAO_AO_FORNECEDOR, $referenciada ? ConfiancaOperacaoEnum::ALTA : ConfiancaOperacaoEnum::BAIXA];
        }

        if ($nfe->finalidade === 2) {
            $criterios[] = 'finNFe=2: nota complementar.';

            return [OperacaoEntradaEnum::COMPLEMENTAR, ConfiancaOperacaoEnum::MEDIA];
        }

        if ($nfe->finalidade === 3) {
            $criterios[] = 'finNFe=3: nota de ajuste.';

            return [OperacaoEntradaEnum::AJUSTE, ConfiancaOperacaoEnum::ALTA];
        }

        if ($empresa !== '' && strlen($empresa) === 14 && strlen($nfe->emitente->documento) === 14
            && substr($nfe->emitente->documento, 0, 8) === substr($empresa, 0, 8)) {
            $criterios[] = 'Emitente com a mesma raiz de CNPJ da empresa: transferência.';

            return [OperacaoEntradaEnum::TRANSFERENCIA, ConfiancaOperacaoEnum::MEDIA];
        }

        return null;
    }

    /**
     * Regras 9 a 15 para um item, a partir do CFOP.
     *
     * @return array{0: OperacaoEntradaEnum, 1: ConfiancaOperacaoEnum, 2: string, 3: string|null}
     */
    private function classificarItem(NfeItem $item, NfeParseada $nfe, ContextoClassificacao $contexto, bool $vendaSemPagamento): array
    {
        $cfop = $item->cfop !== null ? $this->soDigitos($item->cfop) : '';
        $sufixo = strlen($cfop) === 4 ? substr($cfop, 1) : null;
        $mapeada = $sufixo !== null ? config('operacoes_entrada.sufixos.'.$sufixo) : null;

        if (! $mapeada instanceof OperacaoEntradaEnum) {
            $descricao = $cfop === '' ? 'sem CFOP' : "CFOP {$cfop}";

            return [
                OperacaoEntradaEnum::OUTRA, ConfiancaOperacaoEnum::BAIXA,
                "{$descricao} → Outra operação (decisão manual).",
                $cfop === '' || $sufixo !== '949' ? "{$descricao} não mapeado para uma operação conhecida." : null,
            ];
        }

        $operacao = $mapeada;
        $confianca = ConfiancaOperacaoEnum::ALTA;
        $alerta = null;

        if ($operacao === OperacaoEntradaEnum::BONIFICACAO) {
            // Mesmo CFOP (x910) para bonificação e doação: a distinção é comercial.
            $operacao = $contexto->fornecedorTemHistorico ? OperacaoEntradaEnum::BONIFICACAO : OperacaoEntradaEnum::DOACAO;
            $confianca = ConfiancaOperacaoEnum::MEDIA;
            $motivo = $contexto->fornecedorTemHistorico ? 'fornecedor habitual' : 'fornecedor sem compras anteriores';

            return [$operacao, $confianca, "CFOP {$cfop} ({$motivo}) → {$operacao->label()}.", null];
        }

        if ($operacao === OperacaoEntradaEnum::DEVOLUCAO_AO_FORNECEDOR) {
            $referenciada = array_intersect($nfe->notasReferenciadas, $contexto->notasReferenciadasNossas) !== [];

            return [
                $operacao,
                $referenciada ? ConfiancaOperacaoEnum::ALTA : ConfiancaOperacaoEnum::MEDIA,
                "CFOP {$cfop} → {$operacao->label()}".($referenciada ? ', com NFref para compra nossa.' : '.'),
                null,
            ];
        }

        if ($operacao === OperacaoEntradaEnum::COMPRA && $vendaSemPagamento) {
            $confianca = ConfiancaOperacaoEnum::MEDIA;
            $alerta = 'Venda sem pagamento (tPag=90 ou vPag=0) e sem duplicatas: pode ser bonificação ou remessa.';
        }

        return [$operacao, $confianca, "CFOP {$cfop} → {$operacao->label()}.", $alerta];
    }

    /** tPag=90 ou vPag=0, sem duplicatas: venda "sem pagamento" é suspeita. */
    private function vendaSemPagamento(NfeParseada $nfe): bool
    {
        if ($nfe->duplicatas !== []) {
            return false;
        }

        if ($nfe->pagamentos === []) {
            return false;
        }

        foreach ($nfe->pagamentos as $pagamento) {
            if ($pagamento->semPagamento()) {
                return true;
            }
        }

        return round(array_sum(array_map(fn ($p) => $p->valor, $nfe->pagamentos)), 2) <= 0.0;
    }

    /**
     * @param  ClassificacaoItem[]  $porItem
     * @return string[]
     */
    private function alertasDeCobranca(NfeParseada $nfe, array $porItem): array
    {
        $gratuita = array_filter($porItem, fn (ClassificacaoItem $i) => in_array(
            $i->operacao,
            [OperacaoEntradaEnum::BONIFICACAO, OperacaoEntradaEnum::DOACAO, OperacaoEntradaEnum::AMOSTRA_GRATIS],
            true,
        ));

        if ($gratuita !== [] && $nfe->duplicatas !== []) {
            return ['Itens gratuitos (bonificação/doação/amostra) em nota com duplicatas: confira se o fornecedor está cobrando.'];
        }

        return [];
    }

    /** @param  ClassificacaoItem[]  $porItem */
    private function naturezaDivergeDaOperacao(NfeParseada $nfe, array $porItem): bool
    {
        if ($nfe->naturezaOperacao === null) {
            return false;
        }

        $natureza = Str::lower(Str::ascii($nfe->naturezaOperacao));

        foreach ((array) config('operacoes_entrada.natureza') as $palavra => $compativeis) {
            if (! preg_match('/\b'.preg_quote($palavra, '/').'/', $natureza)) {
                continue;
            }

            foreach ($porItem as $item) {
                if (! in_array($item->operacao, $compativeis, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return ClassificacaoItem[] */
    private function itensComOperacao(NfeParseada $nfe, OperacaoEntradaEnum $operacao, ConfiancaOperacaoEnum $confianca): array
    {
        $porItem = [];
        foreach ($nfe->itens as $indice => $item) {
            $porItem[] = new ClassificacaoItem($indice + 1, $item->cfop, $operacao, $confianca);
        }

        return $porItem;
    }

    /** @param  ClassificacaoItem[]  $porItem */
    private function menorConfianca(array $porItem): ConfiancaOperacaoEnum
    {
        $menor = ConfiancaOperacaoEnum::ALTA;
        foreach ($porItem as $item) {
            if ($item->confianca->peso() < $menor->peso()) {
                $menor = $item->confianca;
            }
        }

        return $menor;
    }

    private function limitar(ConfiancaOperacaoEnum $atual, ConfiancaOperacaoEnum $teto): ConfiancaOperacaoEnum
    {
        return $atual->peso() > $teto->peso() ? $teto : $atual;
    }

    private function naoProcessavel(NfeParseada $nfe, string $motivo): ClassificacaoOperacao
    {
        $porItem = $this->itensComOperacao($nfe, OperacaoEntradaEnum::NAO_PROCESSAVEL, ConfiancaOperacaoEnum::NAO_PROCESSAVEL);

        return new ClassificacaoOperacao(
            OperacaoEntradaEnum::NAO_PROCESSAVEL,
            ConfiancaOperacaoEnum::NAO_PROCESSAVEL,
            [$motivo],
            [$motivo],
            $porItem,
        );
    }

    private function soDigitos(?string $valor): string
    {
        return preg_replace('/\D/', '', (string) $valor) ?? '';
    }
}
