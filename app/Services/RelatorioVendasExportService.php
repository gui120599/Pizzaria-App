<?php

namespace App\Services;

use App\Models\Venda;
use Illuminate\Database\Eloquent\Builder;

/**
 * CSV da tabela de vendas (selecionadas ou o filtro atual): uma linha por
 * venda, com valores, formas de pagamento e a situação da NFC-e. Abre direto
 * no Excel (`;`, UTF-8 com BOM, vírgula decimal).
 */
class RelatorioVendasExportService
{
    private const CABECALHO = [
        'Venda', 'Finalizada em', 'Caixa', 'Operador', 'Cliente', 'CPF/CNPJ', 'Itens', 'Desconto', 'Taxa de serviço',
        'Total', 'Pago', 'Troco', 'Saldo a prazo', 'Formas de pagamento', 'Status da venda', 'Status NFC-e',
        'Nº NFC-e', 'Série', 'Id NFe.io', 'Emitida sem CPF', 'Erro de envio',
    ];

    /**
     * @param  Builder<Venda>  $query
     * @return array<int, array<int, string|int|null>>
     */
    public function linhas(Builder $query): array
    {
        $linhas = [self::CABECALHO];

        $query->with([
            'cliente',
            'sessaoCaixa.caixa',
            'sessaoCaixa.user',
            'nfEmissaoAtual',
            'pagamentos.opcaoPagamento' => fn ($opcao) => $opcao->withTrashed(),
        ])->chunkById(500, function ($vendas) use (&$linhas): void {
            foreach ($vendas as $venda) {
                $linhas[] = $this->linha($venda);
            }
        }, 'vendas.id', 'id');

        return $linhas;
    }

    /**
     * @return array<int, string|int|null>
     */
    private function linha(Venda $venda): array
    {
        $numero = fn (mixed $valor): string => number_format((float) $valor, 2, ',', '');
        $emissao = $venda->nfEmissaoAtual;
        $cliente = $venda->cliente;

        return [
            $venda->id,
            $venda->venda_datahora_finalizada?->format('d/m/Y H:i'),
            $venda->sessaoCaixa?->caixa?->caixa_nome,
            $venda->sessaoCaixa?->user?->name,
            $cliente?->cliente_nome,
            $cliente ? ($cliente->cliente_cpf ?: $cliente->cliente_cnpj) : null,
            $numero($venda->venda_valor_itens),
            $numero($venda->venda_valor_desconto),
            $numero($venda->venda_valor_taxa_servico),
            $numero($venda->venda_valor_total),
            $numero($venda->venda_valor_pago),
            $numero($venda->venda_valor_troco),
            $numero(max(0, (float) $venda->venda_valor_total - (float) $venda->venda_valor_pago)),
            $venda->pagamentos->map(fn ($pagamento) => $pagamento->opcaoPagamento?->opcaopag_nome)->filter()->unique()->implode(', '),
            $venda->venda_status,
            $venda->statusNfeExibicao(),
            $emissao?->numero,
            $emissao?->serie,
            $venda->venda_id_nfe,
            $emissao?->comprador_motivo_omissao,
            $emissao?->erro_envio,
        ];
    }

    /**
     * @param  array<int, array<int, string|int|null>>  $linhas
     */
    public function escrever(array $linhas): void
    {
        $saida = fopen('php://output', 'w');
        fwrite($saida, "\xEF\xBB\xBF");

        foreach ($linhas as $linha) {
            fputcsv($saida, $linha, ';');
        }

        fclose($saida);
    }
}
