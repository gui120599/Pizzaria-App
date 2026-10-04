<?php

namespace App\Services;

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
     * @return Collection<int, array{key: string, pedido_id: int, venda_id: int, finalizada_em: Carbon, sessao_id: int, mesa: string, garcom_id: int, garcom: string, percentual: float, consumo: float, taxa: float}>
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

        return $consumos
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
                ])->all();
            })
            ->when(! empty($this->filtros['garcom_id']), fn (Collection $linhas) => $linhas
                ->where('garcom_id', (int) $this->filtros['garcom_id']))
            ->values();
    }

    /**
     * Uma linha por venda, com as rodadas (pedido, garçom e taxa da rodada).
     *
     * @return Collection<int, array{key: string, venda_id: int, finalizada_em: Carbon, mesas: string, rodadas: array<int, array{pedido_id: int, mesa: string, garcom: string, consumo: float, taxa: float}>, consumo: float, taxa: float}>
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
                ])->values()->all(),
                'consumo' => round($rodadas->sum('consumo'), 2),
                'taxa' => round($rodadas->sum('taxa'), 2),
            ])
            ->values();
    }

    /**
     * @return Collection<int, array{key: string, garcom_id: int, garcom: string, mesas: int, vendas: int, consumo: float, taxa: float}>
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
                'consumo' => round($linhas->sum('consumo'), 2),
                'taxa' => round($linhas->sum('taxa'), 2),
            ])
            ->sortByDesc('taxa')
            ->values();
    }

    /**
     * @return array{taxa: float, consumo: float, mesas: int, garcons: int}
     */
    public function totais(): array
    {
        $linhas = $this->linhas();

        return [
            'taxa' => round($linhas->sum('taxa'), 2),
            'consumo' => round($linhas->sum('consumo'), 2),
            'mesas' => $linhas->pluck('sessao_id')->unique()->count(),
            'garcons' => $linhas->pluck('garcom_id')->unique()->count(),
        ];
    }
}
