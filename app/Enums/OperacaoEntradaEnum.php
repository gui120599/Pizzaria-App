<?php

namespace App\Enums;

use App\Services\Compras\Dto\EfeitosOperacao;

/**
 * Operação fiscal de uma NF-e de entrada. A matriz de efeitos (estoque, custo,
 * financeiro) vive só em efeitos() — tela, serviço e testes leem daqui.
 * Decisões marcadas ⚖️ no plano aguardam validação do contador.
 */
enum OperacaoEntradaEnum: string
{
    case COMPRA = 'compra';
    case BONIFICACAO = 'bonificacao';
    case DOACAO = 'doacao';
    case AMOSTRA_GRATIS = 'amostra_gratis';
    case DEVOLUCAO_AO_FORNECEDOR = 'devolucao_ao_fornecedor';
    case COMPLEMENTAR = 'complementar';
    case AJUSTE = 'ajuste';
    case COMODATO = 'comodato';
    case VASILHAME = 'vasilhame';
    case CONSERTO = 'conserto';
    case TRANSFERENCIA = 'transferencia';
    case OUTRA = 'outra';
    case NAO_PROCESSAVEL = 'nao_processavel';

    /** Rótulo gravado no cabeçalho quando os itens têm operações diferentes. */
    public const MISTA = 'mista';

    public function label(): string
    {
        return match ($this) {
            self::COMPRA => 'Compra',
            self::BONIFICACAO => 'Bonificação',
            self::DOACAO => 'Doação',
            self::AMOSTRA_GRATIS => 'Amostra grátis',
            self::DEVOLUCAO_AO_FORNECEDOR => 'Devolução ao fornecedor',
            self::COMPLEMENTAR => 'Nota complementar',
            self::AJUSTE => 'Nota de ajuste',
            self::COMODATO => 'Comodato',
            self::VASILHAME => 'Vasilhame',
            self::CONSERTO => 'Conserto',
            self::TRANSFERENCIA => 'Transferência',
            self::OUTRA => 'Outra operação',
            self::NAO_PROCESSAVEL => 'Não processável',
        };
    }

    public function cor(): string
    {
        return match ($this) {
            self::COMPRA => 'success',
            self::BONIFICACAO, self::DOACAO, self::AMOSTRA_GRATIS => 'info',
            self::DEVOLUCAO_AO_FORNECEDOR, self::COMPLEMENTAR, self::OUTRA => 'warning',
            self::COMODATO, self::VASILHAME, self::CONSERTO, self::AJUSTE, self::TRANSFERENCIA => 'gray',
            self::NAO_PROCESSAVEL => 'danger',
        };
    }

    /** Rótulo para o valor gravado em compra_operacao (enum, 'mista' ou legado/null). */
    public static function rotulo(?string $valor): string
    {
        if ($valor === null || $valor === '') {
            return 'Legado (não classificada)';
        }

        if ($valor === self::MISTA) {
            return 'Mista';
        }

        return self::tryFrom($valor)?->label() ?? $valor;
    }

    public static function corDoValor(?string $valor): string
    {
        if ($valor === self::MISTA) {
            return 'warning';
        }

        return ($valor !== null ? self::tryFrom($valor)?->cor() : null) ?? 'gray';
    }

    /** Opções para filtros/selects: valor => rótulo (inclui 'mista'). */
    public static function opcoes(): array
    {
        $opcoes = [];
        foreach (self::cases() as $caso) {
            $opcoes[$caso->value] = $caso->label();
        }
        $opcoes[self::MISTA] = 'Mista';

        return $opcoes;
    }

    public function efeitos(): EfeitosOperacao
    {
        return match ($this) {
            self::COMPRA => new EfeitosOperacao(
                EfeitoEstoqueEnum::ENTRADA, PoliticaCustoEnum::NORMAL, ContaPagarOperacaoEnum::SIM,
            ),
            // ⚖️ Q1/Q2: custo zero (opção A) é o padrão provisório até o contador responder.
            self::BONIFICACAO, self::DOACAO => new EfeitosOperacao(
                EfeitoEstoqueEnum::ENTRADA, PoliticaCustoEnum::ZERO, ContaPagarOperacaoEnum::NAO,
                exigeConfirmacao: true,
            ),
            // ⚖️ Q3: sem movimentação é o padrão provisório.
            self::AMOSTRA_GRATIS => new EfeitosOperacao(
                EfeitoEstoqueEnum::NENHUM, PoliticaCustoEnum::NENHUMA, ContaPagarOperacaoEnum::NAO,
                registraSemEfeito: true,
            ),
            self::DEVOLUCAO_AO_FORNECEDOR => new EfeitosOperacao(
                EfeitoEstoqueEnum::SAIDA, PoliticaCustoEnum::ORIGINAL, ContaPagarOperacaoEnum::NAO,
                exigeVinculo: true, exigeConfirmacao: true,
            ),
            self::COMPLEMENTAR => new EfeitosOperacao(
                EfeitoEstoqueEnum::NENHUM, PoliticaCustoEnum::AJUSTE_VALOR, ContaPagarOperacaoEnum::SE_HOUVER_COBRANCA,
                exigeVinculo: true, exigeConfirmacao: true,
            ),
            self::AJUSTE, self::COMODATO, self::VASILHAME, self::CONSERTO => new EfeitosOperacao(
                EfeitoEstoqueEnum::NENHUM, PoliticaCustoEnum::NENHUMA, ContaPagarOperacaoEnum::NAO,
                registraSemEfeito: true,
            ),
            self::TRANSFERENCIA => new EfeitosOperacao(
                EfeitoEstoqueEnum::A_DEFINIR, PoliticaCustoEnum::A_DEFINIR, ContaPagarOperacaoEnum::NAO,
                exigeConfirmacao: true,
            ),
            self::OUTRA => new EfeitosOperacao(
                EfeitoEstoqueEnum::A_DEFINIR, PoliticaCustoEnum::A_DEFINIR, ContaPagarOperacaoEnum::A_DEFINIR,
                exigeConfirmacao: true,
            ),
            self::NAO_PROCESSAVEL => new EfeitosOperacao(
                EfeitoEstoqueEnum::NENHUM, PoliticaCustoEnum::NENHUMA, ContaPagarOperacaoEnum::NAO,
                bloqueiaConfirmacao: true, registraSemEfeito: true,
            ),
        };
    }
}
