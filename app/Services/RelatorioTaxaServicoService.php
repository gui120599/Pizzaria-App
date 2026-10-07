<?php

namespace App\Services;

use App\Models\Empresa;
use App\Models\NfEmissao;
use App\Models\PagamentosVenda;
use App\Support\RateioCentavos;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Taxa de serviço por garçom, nas vendas FINALIZADAS do período. A taxa de
 * cada mesa numa venda é a mesma de ContaMesa::taxaServicoDaVenda (percentual
 * da sessão sobre o consumo, arredondado por sessão) e é repartida entre os
 * garçons pelo consumo das rodadas que cada um lançou — a soma bate no
 * centavo com venda_valor_taxa_servico. O cálculo é por rodada (pedido): a
 * taxa da mesa é repartida pelo consumo de cada rodada. Rodada sem garçom (PDV) fica com o
 * garçom que abriu a mesa. Venda em que o caixa tirou a taxa fica de fora.
 * Da taxa bruta saem a taxa da maquininha (proporcional) e o imposto da
 * NFC-e, chegando na taxa líquida — ver aplicarDescontos().
 * No modo "sessao", a taxa da mesa inteira vai para o garçom que a abriu.
 *
 * Filtros (mesmo shape da página RelatorioTaxaServico): inicio, fim,
 * garcom_id, atribuicao (rodada|sessao, padrão rodada).
 */
class RelatorioTaxaServicoService
{
    public const ATRIBUICAO_RODADA = 'rodada';

    public const ATRIBUICAO_SESSAO = 'sessao';

    /** @var array<string, string> */
    public const ATRIBUICOES = [
        self::ATRIBUICAO_RODADA => 'Por rodada (garçom que lançou)',
        self::ATRIBUICAO_SESSAO => 'Por sessão (garçom que abriu a mesa)',
    ];

    /** @var array<string, Collection<int, array<string, mixed>>> */
    private array $linhas = [];

    /**
     * @param  array{inicio?: ?string, fim?: ?string, garcom_id?: int|string|null, atribuicao?: ?string}  $filtros
     */
    public function __construct(private readonly array $filtros = []) {}

    /**
     * Sem filtro, assume o mês corrente (mesmo padrão de InteractsComPeriodo).
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function periodo(): array
    {
        $inicio = ! empty($this->filtros['inicio'])
            ? Carbon::parse($this->filtros['inicio'])->startOfDay()
            : now()->startOfMonth();

        $fim = ! empty($this->filtros['fim'])
            ? Carbon::parse($this->filtros['fim'])->endOfDay()
            : now()->endOfDay();

        return [$inicio, $fim];
    }

    public function atribuicao(): string
    {
        return ($this->filtros['atribuicao'] ?? null) === self::ATRIBUICAO_SESSAO
            ? self::ATRIBUICAO_SESSAO
            : self::ATRIBUICAO_RODADA;
    }

    /**
     * Uma linha por rodada (pedido) de mesa em cada venda.
     *
     * @return Collection<int, array{key: string, pedido_id: int, venda_id: int, finalizada_em: Carbon, sessao_id: int, mesa: string, garcom_id: int, garcom: string, percentual: float, consumo: float, taxa: float, desconto_maquininha: float, desconto_imposto: float, taxa_liquida: float}>
     */
    public function linhas(): Collection
    {
        return $this->linhas['cobradas'] ??= $this->montarLinhas(cobradas: true);
    }

    /**
     * Mesas que não pagaram a taxa: sessão aberta ou deixada sem taxa pelo
     * garçom (percentual 0) ou venda em que o caixa tirou a taxa. O valor é o
     * que teria sido cobrado — o percentual da sessão ou, se ela ficou em 0,
     * o padrão de config('pizzaria.salao.taxa_servico_percentual'). Só conta
     * sessões abertas depois da primeira sessão com taxa: as anteriores ao
     * recurso também têm percentual 0 e não são taxa perdida.
     *
     * @return array{mesas: int, valor: float}
     */
    public function semTaxa(): array
    {
        $linhas = $this->linhas['sem_taxa'] ??= $this->montarLinhas(cobradas: false);

        return [
            'mesas' => $linhas->pluck('sessao_id')->unique()->count(),
            'valor' => round($linhas->sum('taxa'), 2),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function montarLinhas(bool $cobradas): Collection
    {
        [$inicio, $fim] = $this->periodo();

        $inicioDaTaxa = $cobradas ? null : DB::table('sessao_mesas')
            ->where('sessao_mesa_taxa_servico_percentual', '>', 0)
            ->min('created_at');

        if (! $cobradas && $inicioDaTaxa === null) {
            return collect();
        }

        $garcom = $this->atribuicao() === self::ATRIBUICAO_SESSAO
            ? 'sessao_mesas.sessao_mesa_usuario_id'
            : 'COALESCE(pedidos.pedido_usuario_garcom_id, sessao_mesas.sessao_mesa_usuario_id)';

        $consumos = DB::table('itens_pedidos')
            ->join('pedidos', 'pedidos.id', '=', 'itens_pedidos.item_pedido_pedido_id')
            ->join('sessao_mesas', 'sessao_mesas.id', '=', 'pedidos.pedido_sessao_mesa_id')
            ->join('vendas', 'vendas.id', '=', 'itens_pedidos.item_pedido_venda_id')
            ->leftJoin('mesas', 'mesas.id', '=', 'sessao_mesas.sessao_mesa_mesa_id')
            ->where('vendas.venda_status', 'FINALIZADA')
            ->whereBetween('vendas.venda_datahora_finalizada', [$inicio, $fim])
            ->where('itens_pedidos.item_pedido_status', 'INSERIDO')
            ->when($cobradas, fn ($q) => $q
                ->where('vendas.venda_taxa_servico_removida', false)
                ->where('sessao_mesas.sessao_mesa_taxa_servico_percentual', '>', 0))
            ->when(! $cobradas, fn ($q) => $q
                ->where('sessao_mesas.created_at', '>=', $inicioDaTaxa)
                ->where(fn ($q) => $q
                    ->where('vendas.venda_taxa_servico_removida', true)
                    ->orWhere('sessao_mesas.sessao_mesa_taxa_servico_percentual', 0)))
            ->groupBy(
                'vendas.id', 'vendas.venda_datahora_finalizada', 'sessao_mesas.id',
                'sessao_mesas.sessao_mesa_taxa_servico_percentual', 'mesas.mesa_nome', 'pedidos.id', 'garcom_id',
            )
            ->selectRaw("vendas.id AS venda_id, vendas.venda_datahora_finalizada AS finalizada_em,
                sessao_mesas.id AS sessao_id, sessao_mesas.sessao_mesa_taxa_servico_percentual AS percentual,
                mesas.mesa_nome AS mesa, pedidos.id AS pedido_id,
                {$garcom} AS garcom_id,
                SUM(itens_pedidos.item_pedido_valor) AS consumo")
            ->orderBy('vendas.venda_datahora_finalizada')
            ->orderBy('pedidos.id')
            ->get();

        $nomes = DB::table('users')
            ->whereIn('id', $consumos->pluck('garcom_id')->unique())
            ->pluck('name', 'id');

        $linhas = $consumos
            ->groupBy(fn (object $linha): string => $linha->venda_id.'-'.$linha->sessao_id)
            ->flatMap(function (Collection $rodadas) use ($nomes, $cobradas): array {
                $consumoCents = $rodadas->mapWithKeys(fn (object $linha): array => [
                    (int) $linha->pedido_id => (int) round((float) $linha->consumo * 100),
                ])->all();
                $percentual = (float) $rodadas->first()->percentual;
                if (! $cobradas && $percentual <= 0) {
                    $percentual = (float) config('pizzaria.salao.taxa_servico_percentual');
                }
                $taxaCents = (int) round(round(array_sum($consumoCents) / 100 * $percentual / 100, 2) * 100);
                $rateio = RateioCentavos::ratearProporcional($taxaCents, $consumoCents);

                return $rodadas->map(fn (object $linha): array => [
                    'key' => $linha->venda_id.'-'.$linha->pedido_id,
                    'pedido_id' => (int) $linha->pedido_id,
                    'venda_id' => (int) $linha->venda_id,
                    'finalizada_em' => Carbon::parse($linha->finalizada_em),
                    'sessao_id' => (int) $linha->sessao_id,
                    'mesa' => (string) ($linha->mesa ?? '—'),
                    'garcom_id' => (int) $linha->garcom_id,
                    'garcom' => (string) ($nomes[$linha->garcom_id] ?? 'Usuário #'.$linha->garcom_id),
                    'percentual' => $percentual,
                    'consumo' => round((float) $linha->consumo, 2),
                    'taxa' => (float) (($rateio[(int) $linha->pedido_id] ?? 0) / 100),
                    'desconto_maquininha' => 0.0,
                    'desconto_imposto' => 0.0,
                ])->all();
            })
            ->values();

        // Descontos antes do filtro de garçom: o rateio é sobre todas as
        // rodadas da venda.
        if ($cobradas) {
            $linhas = $this->aplicarDescontos($linhas);
        }

        return $linhas
            ->map(fn (array $linha): array => [
                ...$linha,
                'taxa_liquida' => round($linha['taxa'] - $linha['desconto_maquininha'] - $linha['desconto_imposto'], 2),
            ])
            ->when(! empty($this->filtros['garcom_id']), fn (Collection $linhas) => $linhas
                ->where('garcom_id', (int) $this->filtros['garcom_id']))
            ->values();
    }

    /**
     * Desconta da taxa de cada venda:
     * - a taxa da maquininha proporcional à taxa de serviço no total da venda
     *   (custo dos pagamentos × taxa ÷ total);
     * - o imposto sobre a taxa, se a NFC-e foi autorizada.
     * Cada desconto é repartido entre as rodadas pela taxa de cada uma.
     *
     * @param  Collection<int, array<string, mixed>>  $linhas
     * @return Collection<int, array<string, mixed>>
     */
    private function aplicarDescontos(Collection $linhas): Collection
    {
        $vendaIds = $linhas->pluck('venda_id')->unique()->values();

        if ($vendaIds->isEmpty()) {
            return $linhas;
        }

        $vendas = DB::table('vendas')
            ->whereIn('id', $vendaIds)
            ->get(['id', 'venda_valor_total', 'venda_status_nfe', 'venda_imposto_nfe_percentual'])
            ->keyBy('id');

        $taxas = app(TaxaMaquininhaService::class);
        $custoMaquininha = PagamentosVenda::with('opcaoPagamento')
            ->whereIn('pg_venda_venda_id', $vendaIds)
            ->get()
            ->groupBy('pg_venda_venda_id')
            ->map(fn (Collection $pagamentos): float => $pagamentos->sum(fn (PagamentosVenda $pagamento): float => (float) (
                $pagamento->pg_venda_taxa_maquininha_percentual !== null
                    ? $pagamento->pg_venda_taxa_maquininha_valor
                    : ($taxas->calcular($pagamento)['valor'] ?? 0)
            )));

        $impostoAtual = (float) (Empresa::query()->value('empresa_percentual_imposto_nfe') ?? 0);

        return $linhas
            ->groupBy('venda_id')
            ->flatMap(function (Collection $rodadas, int $vendaId) use ($vendas, $custoMaquininha, $impostoAtual): array {
                $venda = $vendas[$vendaId];
                $taxaCents = $rodadas->mapWithKeys(fn (array $linha): array => [$linha['pedido_id'] => (int) round($linha['taxa'] * 100)])->all();
                $taxaVendaCents = array_sum($taxaCents);
                $total = (float) $venda->venda_valor_total;

                $maquininhaCents = $total > 0
                    ? (int) round(($custoMaquininha[$vendaId] ?? 0) * ($taxaVendaCents / 100) / $total * 100)
                    : 0;

                $percentualImposto = $venda->venda_status_nfe === NfEmissao::STATUS_AUTORIZADA
                    ? (float) ($venda->venda_imposto_nfe_percentual ?? $impostoAtual)
                    : 0.0;
                $impostoCents = (int) round($taxaVendaCents * $percentualImposto / 100);

                $maquininhaCents = min($maquininhaCents, $taxaVendaCents);
                $impostoCents = min($impostoCents, $taxaVendaCents - $maquininhaCents);

                $rateioMaquininha = RateioCentavos::ratearProporcional($maquininhaCents, $taxaCents);
                $rateioImposto = RateioCentavos::ratearProporcional($impostoCents, $taxaCents);

                return $rodadas->map(fn (array $linha): array => [
                    ...$linha,
                    'desconto_maquininha' => (float) (($rateioMaquininha[$linha['pedido_id']] ?? 0) / 100),
                    'desconto_imposto' => (float) (($rateioImposto[$linha['pedido_id']] ?? 0) / 100),
                ])->all();
            })
            ->values();
    }

    /**
     * Uma linha por venda, com as rodadas (pedido, garçom e taxa da rodada).
     *
     * @return Collection<int, array{key: string, venda_id: int, finalizada_em: Carbon, mesas: string, rodadas: array<int, array{pedido_id: int, mesa: string, garcom: string, consumo: float, taxa: float, desconto_maquininha: float, desconto_imposto: float, taxa_liquida: float}>, consumo: float, taxa: float, desconto_maquininha: float, desconto_imposto: float, taxa_liquida: float}>
     */
    public function porVenda(): Collection
    {
        return $this->linhas()
            ->groupBy('venda_id')
            ->map(fn (Collection $rodadas, int $vendaId): array => [
                'key' => (string) $vendaId,
                'venda_id' => $vendaId,
                'finalizada_em' => $rodadas->first()['finalizada_em'],
                'mesas' => $rodadas->pluck('mesa')->unique()->implode(', '),
                'rodadas' => $rodadas->map(fn (array $rodada): array => [
                    'pedido_id' => $rodada['pedido_id'],
                    'mesa' => $rodada['mesa'],
                    'garcom' => $rodada['garcom'],
                    'consumo' => $rodada['consumo'],
                    'taxa' => $rodada['taxa'],
                    'desconto_maquininha' => $rodada['desconto_maquininha'],
                    'desconto_imposto' => $rodada['desconto_imposto'],
                    'taxa_liquida' => $rodada['taxa_liquida'],
                ])->values()->all(),
                ...self::somas($rodadas),
            ])
            ->values();
    }

    /**
     * @return Collection<int, array{key: string, garcom_id: int, garcom: string, mesas: int, vendas: int, consumo: float, taxa: float, desconto_maquininha: float, desconto_imposto: float, taxa_liquida: float}>
     */
    public function porGarcom(): Collection
    {
        return $this->linhas()
            ->groupBy('garcom_id')
            ->map(fn (Collection $linhas, int $garcomId): array => [
                'key' => (string) $garcomId,
                'garcom_id' => $garcomId,
                'garcom' => $linhas->first()['garcom'],
                'mesas' => $linhas->pluck('sessao_id')->unique()->count(),
                'vendas' => $linhas->pluck('venda_id')->unique()->count(),
                ...self::somas($linhas),
            ])
            ->sortByDesc('taxa_liquida')
            ->values();
    }

    /**
     * @return array{consumo: float, taxa: float, desconto_maquininha: float, desconto_imposto: float, taxa_liquida: float, mesas: int, garcons: int}
     */
    public function totais(): array
    {
        $linhas = $this->linhas();

        return [
            ...self::somas($linhas),
            'mesas' => $linhas->pluck('sessao_id')->unique()->count(),
            'garcons' => $linhas->pluck('garcom_id')->unique()->count(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $linhas
     * @return array{consumo: float, taxa: float, desconto_maquininha: float, desconto_imposto: float, taxa_liquida: float}
     */
    private static function somas(Collection $linhas): array
    {
        return [
            'consumo' => round($linhas->sum('consumo'), 2),
            'taxa' => round($linhas->sum('taxa'), 2),
            'desconto_maquininha' => round($linhas->sum('desconto_maquininha'), 2),
            'desconto_imposto' => round($linhas->sum('desconto_imposto'), 2),
            'taxa_liquida' => round($linhas->sum('taxa_liquida'), 2),
        ];
    }
}
