<?php

namespace App\Services;

use App\Models\ItensVenda;
use App\Models\NfEmissao;
use App\Models\Venda;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Relatório para o contador: vendas com NFC-e autorizada no período, com os
 * itens agrupados por NCM, categoria, produto, CFOP/CSOSN, forma de pagamento
 * ou dia — sempre com total. Os itens seguem o que vai na nota: sem cortesia
 * e sem item removido (ver NfeIoService::montarItens).
 *
 * @phpstan-type LinhaGrupo array{rotulo: string, itens: int, quantidade: float, bruto: float, desconto: float, liquido: float}
 */
class RelatorioFiscalNotasService
{
    public const DIMENSOES = [
        'ncm' => 'NCM',
        'categoria' => 'Categoria de produto',
        'produto' => 'Produto',
        'cfop_csosn' => 'CFOP / CSOSN',
        'forma_pagamento' => 'Forma de pagamento',
        'dia' => 'Dia de emissão',
    ];

    /** Títulos das tabelas (sem minúscula forçada: NCM e CFOP são siglas). */
    public const TITULOS = [
        'ncm' => 'Por NCM',
        'categoria' => 'Por categoria de produto',
        'produto' => 'Por produto',
        'cfop_csosn' => 'Por CFOP / CSOSN',
        'forma_pagamento' => 'Por forma de pagamento',
        'dia' => 'Por dia de emissão',
    ];

    public function __construct(
        private readonly CarbonInterface $inicio,
        private readonly CarbonInterface $fim,
    ) {}

    /**
     * @return Collection<int, Venda>
     */
    public function notas(): Collection
    {
        return $this->vendasDasNotas()
            ->with(['cliente', 'nfEmissoes' => fn ($query) => $this->noPeriodo($query)])
            ->get()
            ->each(function (Venda $venda): void {
                $emissao = $venda->nfEmissoes->first();
                $venda->setAttribute('nota_em', $emissao?->autorizada_em ?? $venda->venda_datahora_finalizada);
                $venda->setAttribute('nota_numero', $emissao?->numero);
                $venda->setAttribute('nota_serie', $emissao?->serie);
            })
            ->sortBy('nota_em')
            ->values();
    }

    /**
     * @return array{notas: int, itens: int, bruto: float, desconto: float, liquido: float, outros: float, total: float}
     */
    public function resumo(): array
    {
        $itens = $this->baseItens()
            ->selectRaw('COUNT(*) as itens, COALESCE(SUM(item_venda_valor + item_venda_desconto), 0) as bruto, COALESCE(SUM(item_venda_desconto), 0) as desconto, COALESCE(SUM(item_venda_valor), 0) as liquido')
            ->first();

        $notas = $this->notas();
        $total = round((float) $notas->sum('venda_valor_total'), 2);
        $liquido = round((float) $itens->liquido, 2);

        return [
            'notas' => $notas->count(),
            'itens' => (int) $itens->itens,
            'bruto' => round((float) $itens->bruto, 2),
            'desconto' => round((float) $itens->desconto, 2),
            'liquido' => $liquido,
            // Frete, taxa de serviço e demais ajustes da nota: não pertencem a item algum.
            'outros' => round($total - $liquido, 2),
            'total' => $total,
        ];
    }

    /**
     * @return array{linhas: Collection<int, LinhaGrupo>, total: LinhaGrupo}
     */
    public function agrupar(string $dimensao): array
    {
        $linhas = $dimensao === 'forma_pagamento'
            ? $this->porFormaPagamento()
            : $this->porItem($dimensao);

        return ['linhas' => $linhas, 'total' => $this->somar($linhas)];
    }

    /**
     * @param  array<int, string>  $dimensoes
     * @return array<int, array<int, string>>
     */
    public function paraCsv(array $dimensoes): array
    {
        $numero = fn (float $valor): string => number_format($valor, 2, ',', '');
        $linhas = [['Relatório fiscal — NFC-e autorizadas de '.$this->inicio->format('d/m/Y').' a '.$this->fim->format('d/m/Y')]];

        $resumo = $this->resumo();
        $linhas[] = [];
        $linhas[] = ['Resumo'];
        $linhas[] = ['Notas', $resumo['notas']];
        $linhas[] = ['Itens', $resumo['itens']];
        $linhas[] = ['Bruto', $numero($resumo['bruto'])];
        $linhas[] = ['Desconto', $numero($resumo['desconto'])];
        $linhas[] = ['Líquido dos itens', $numero($resumo['liquido'])];
        $linhas[] = ['Frete, taxa de serviço e ajustes', $numero($resumo['outros'])];
        $linhas[] = ['Total das notas', $numero($resumo['total'])];

        foreach ($dimensoes as $dimensao) {
            if (! isset(self::DIMENSOES[$dimensao])) {
                continue;
            }

            $grupo = $this->agrupar($dimensao);
            $linhas[] = [];
            $linhas[] = [self::TITULOS[$dimensao]];
            $linhas[] = [self::DIMENSOES[$dimensao], 'Itens', 'Quantidade', 'Bruto', 'Desconto', 'Líquido'];

            foreach ($grupo['linhas']->push($grupo['total']) as $linha) {
                $linhas[] = [$linha['rotulo'], $linha['itens'], $numero($linha['quantidade']), $numero($linha['bruto']), $numero($linha['desconto']), $numero($linha['liquido'])];
            }
        }

        return $linhas;
    }

    private function noPeriodo(Builder|Relation $query): void
    {
        $query->where('status', NfEmissao::STATUS_AUTORIZADA)
            ->whereBetween('autorizada_em', [$this->inicio->copy()->startOfDay(), $this->fim->copy()->endOfDay()]);
    }

    /**
     * Itens fiscais das vendas com nota autorizada no período.
     *
     * @return Builder<ItensVenda>
     */
    private function baseItens(): Builder
    {
        return ItensVenda::query()
            ->where('item_venda_status', 'INSERIDO')
            ->where('item_venda_cortesia', false)
            ->whereIn('item_venda_venda_id', $this->vendasDasNotas()->select('id'));
    }

    /**
     * @return Builder<Venda>
     */
    private function vendasDasNotas(): Builder
    {
        return Venda::query()
            ->where('venda_status', '!=', 'CANCELADA')
            ->where(fn (Builder $query) => $query
                ->whereHas('nfEmissoes', fn (Builder $emissao) => $this->noPeriodo($emissao))
                // Notas anteriores ao controle de numeração (nf_emissoes) só existem na venda.
                ->orWhere(fn (Builder $legada) => $legada
                    ->where('venda_status_nfe', NfEmissao::STATUS_AUTORIZADA)
                    ->whereDoesntHave('nfEmissoes')
                    ->whereBetween('venda_datahora_finalizada', [$this->inicio->copy()->startOfDay(), $this->fim->copy()->endOfDay()])));
    }

    /**
     * @return Collection<int, LinhaGrupo>
     */
    private function porItem(string $dimensao): Collection
    {
        $query = $this->baseItens()
            ->join('produtos', 'produtos.id', '=', 'itens_vendas.item_venda_produto_id');

        $rotulo = match ($dimensao) {
            'ncm' => "COALESCE(NULLIF(produtos.produto_codigo_NCM, ''), 'Sem NCM')",
            'produto' => 'produtos.produto_descricao',
            'cfop_csosn' => "CONCAT('CFOP ', COALESCE(NULLIF(produtos.produto_CFOP, ''), '—'), ' / CSOSN ', COALESCE(NULLIF(produtos.produto_CSOSN, ''), '—'))",
            'dia' => "DATE_FORMAT(COALESCE(nota_issued.autorizada_em, vendas_da_nota.venda_datahora_finalizada), '%Y-%m-%d')",
            default => "COALESCE(CASE WHEN pai.categoria_nome IS NULL THEN categorias.categoria_nome ELSE CONCAT(pai.categoria_nome, ' › ', categorias.categoria_nome) END, 'Sem categoria')",
        };

        if ($dimensao === 'dia') {
            $query->join('vendas as vendas_da_nota', 'vendas_da_nota.id', '=', 'itens_vendas.item_venda_venda_id')
                ->leftJoinSub(
                    NfEmissao::query()->where('status', NfEmissao::STATUS_AUTORIZADA)->selectRaw('venda_id, MIN(autorizada_em) as autorizada_em')->groupBy('venda_id'),
                    'nota_issued',
                    'nota_issued.venda_id',
                    '=',
                    'vendas_da_nota.id',
                );
        }

        if ($dimensao === 'categoria') {
            $query->leftJoin('categorias', 'categorias.id', '=', 'produtos.produto_categoria_id')
                ->leftJoin('categorias as pai', 'pai.id', '=', 'categorias.categoria_pai_id');
        }

        return $query
            ->selectRaw("{$rotulo} as rotulo, COUNT(*) as itens, COALESCE(SUM(item_venda_quantidade), 0) as quantidade, COALESCE(SUM(item_venda_valor + item_venda_desconto), 0) as bruto, COALESCE(SUM(item_venda_desconto), 0) as desconto, COALESCE(SUM(item_venda_valor), 0) as liquido")
            ->groupBy(DB::raw($rotulo))
            ->orderByRaw(in_array($dimensao, ['dia', 'ncm'], true) ? 'rotulo' : 'liquido DESC')
            ->get()
            ->map(fn ($linha): array => $this->linha(
                $dimensao === 'dia' && $linha->rotulo ? date('d/m/Y', strtotime($linha->rotulo)) : (string) $linha->rotulo,
                (int) $linha->itens, (float) $linha->quantidade, (float) $linha->bruto, (float) $linha->desconto, (float) $linha->liquido,
            ));
    }

    /**
     * Forma de pagamento vem dos pagamentos da venda (valor pago menos o troco),
     * não dos itens; o saldo a prazo entra como "Sem pagamento".
     *
     * @return Collection<int, LinhaGrupo>
     */
    private function porFormaPagamento(): Collection
    {
        $vendas = $this->vendasDasNotas()->with('pagamentos.opcaoPagamento')->get();

        $porForma = [];
        foreach ($vendas as $venda) {
            foreach ($venda->pagamentos as $pagamento) {
                $nome = $pagamento->opcaoPagamento?->opcaopag_nome ?? 'Forma não identificada';
                $valor = (float) $pagamento->pg_venda_valor_pago_pelo_cliente - (float) $pagamento->pg_venda_valor_troco;
                $porForma[$nome] = ($porForma[$nome] ?? 0) + $valor;
            }

            $saldo = round((float) $venda->venda_valor_total - (float) $venda->venda_valor_pago, 2);
            if ($saldo > 0.01) {
                $porForma['Sem pagamento (a prazo)'] = ($porForma['Sem pagamento (a prazo)'] ?? 0) + $saldo;
            }
        }

        return collect($porForma)
            ->sortDesc()
            ->map(fn (float $valor, string $nome): array => $this->linha($nome, 0, 0, round($valor, 2), 0, round($valor, 2)))
            ->values();
    }

    /**
     * @return LinhaGrupo
     */
    private function linha(string $rotulo, int $itens, float $quantidade, float $bruto, float $desconto, float $liquido): array
    {
        return [
            'rotulo' => $rotulo,
            'itens' => $itens,
            'quantidade' => round($quantidade, 3),
            'bruto' => round($bruto, 2),
            'desconto' => round($desconto, 2),
            'liquido' => round($liquido, 2),
        ];
    }

    /**
     * @param  Collection<int, LinhaGrupo>  $linhas
     * @return LinhaGrupo
     */
    private function somar(Collection $linhas): array
    {
        return $this->linha('TOTAL', (int) $linhas->sum('itens'), (float) $linhas->sum('quantidade'), (float) $linhas->sum('bruto'), (float) $linhas->sum('desconto'), (float) $linhas->sum('liquido'));
    }
}
