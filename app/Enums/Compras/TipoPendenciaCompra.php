<?php

namespace App\Enums\Compras;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum TipoPendenciaCompra: string implements HasColor, HasLabel
{
    case ProdutoNaoIdentificado = 'produto_nao_identificado';
    case ConversaoUnidade = 'conversao_unidade';
    case FornecedorNovo = 'fornecedor_novo';
    case FornecedorSemParametros = 'fornecedor_sem_parametros';
    case LancamentoAmbiguo = 'lancamento_ambiguo';
    case DivergenciaValor = 'divergencia_valor';
    case CaixaPdvFechado = 'caixa_pdv_fechado';
    case PagamentoAConfirmar = 'pagamento_a_confirmar';
    case PrecoForaPadrao = 'preco_fora_padrao';
    case NfeCancelada = 'nfe_cancelada';
    case XmlInvalido = 'xml_invalido';
    case OperacaoFiscal = 'operacao_fiscal';

    public function getLabel(): string
    {
        return match ($this) {
            self::ProdutoNaoIdentificado => 'Produto não identificado',
            self::ConversaoUnidade => 'Conversão de unidade',
            self::FornecedorNovo => 'Fornecedor novo',
            self::FornecedorSemParametros => 'Fornecedor sem parâmetros',
            self::LancamentoAmbiguo => 'Lançamento ambíguo',
            self::DivergenciaValor => 'Divergência de valor',
            self::CaixaPdvFechado => 'Caixa PDV fechado',
            self::PagamentoAConfirmar => 'Pagamento a confirmar',
            self::PrecoForaPadrao => 'Preço fora do padrão',
            self::NfeCancelada => 'NF-e cancelada',
            self::XmlInvalido => 'XML inválido',
            self::OperacaoFiscal => 'Operação fiscal a revisar',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::NfeCancelada, self::DivergenciaValor, self::XmlInvalido => 'danger',
            self::ProdutoNaoIdentificado, self::ConversaoUnidade, self::LancamentoAmbiguo, self::OperacaoFiscal => 'warning',
            self::CaixaPdvFechado, self::PagamentoAConfirmar => 'info',
            self::FornecedorNovo, self::FornecedorSemParametros, self::PrecoForaPadrao => 'gray',
        };
    }

    /** Impede a entrada automática no estoque enquanto estiver aberta. */
    public function bloqueiaConferencia(): bool
    {
        return in_array($this, [
            self::ProdutoNaoIdentificado,
            self::ConversaoUnidade,
            self::OperacaoFiscal,
            self::NfeCancelada,
        ], true);
    }
}
