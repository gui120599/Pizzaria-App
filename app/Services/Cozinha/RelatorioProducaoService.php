<?php

namespace App\Services\Cozinha;

use App\Enums\Cozinha\AcaoProducao;
use App\Enums\Cozinha\MotivoRefacao;
use App\Enums\Cozinha\StatusItemProducao;
use App\Enums\ProdutoTipoEnum;
use App\Enums\StatusPedidoEnum;
use App\Models\DispositivoCozinha;
use App\Models\ProducaoItem;
use App\Models\SetorProducao;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * Números da produção (spec §7.1, RelatorioProducao e widgets).
 *
 * Tempos contam só o que a cozinha concluiu de fato: linha cancelada (inclusive
 * a original de um refazer) e linha forçada pelo Painel de Pedidos ficam de
 * fora, senão o "pronto" forçado no fim do turno puxaria a média.
 *
 * - fila    = liberado → iniciado
 * - preparo = iniciado → concluído
 * - total   = liberado → concluído; no prazo quando ≤ tempo previsto da rota
 *   ou, sem ele, o alvo do setor.
 *
 * @phpstan-type Filtros array{setor_id?: ?int, produto_id?: ?int, operador_id?: ?int}
 */
class RelatorioProducaoService
{
    /**
     * @param  Filtros  $filtros
     * @return array{concluidos: int, fila_seg: ?float, preparo_seg: ?float, total_seg: ?float, no_prazo_pct: ?float, itens_hora: ?float, refeitos: int}
     */
    public function resumo(CarbonInterface $inicio, CarbonInterface $fim, array $filtros = []): array
    {
        $linha = $this->concluidos($inicio, $fim, $filtros)
            ->join('setores_producao as s', 's.id', '=', 'pi.setor_producao_id')
            ->selectRaw('COUNT(*) AS concluidos')
            ->selectRaw('AVG(CASE WHEN pi.iniciado_em IS NOT NULL THEN TIMESTAMPDIFF(SECOND, pi.liberado_em, pi.iniciado_em) END) AS fila_seg')
            ->selectRaw('AVG(CASE WHEN pi.iniciado_em IS NOT NULL THEN TIMESTAMPDIFF(SECOND, pi.iniciado_em, pi.concluido_em) END) AS preparo_seg')
            ->selectRaw('AVG(TIMESTAMPDIFF(SECOND, pi.liberado_em, pi.concluido_em)) AS total_seg')
            ->selectRaw('AVG(CASE WHEN TIMESTAMPDIFF(SECOND, pi.liberado_em, pi.concluido_em) <= COALESCE(pi.tempo_previsto_min, s.tempo_alvo_min) * 60 THEN 100 ELSE 0 END) AS no_prazo_pct')
            ->selectRaw("COUNT(DISTINCT DATE_FORMAT(pi.concluido_em, '%Y-%m-%d %H')) AS horas")
            ->first();

        $concluidos = (int) ($linha->concluidos ?? 0);
        $horas = (int) ($linha->horas ?? 0);

        return [
            'concluidos' => $concluidos,
            'fila_seg' => $this->numero($linha->fila_seg ?? null),
            'preparo_seg' => $this->numero($linha->preparo_seg ?? null),
            'total_seg' => $this->numero($linha->total_seg ?? null),
            'no_prazo_pct' => $concluidos > 0 ? $this->numero($linha->no_prazo_pct ?? null) : null,
            'itens_hora' => $horas > 0 ? round($concluidos / $horas, 1) : null,
            'refeitos' => $this->refeitos($inicio, $fim, $filtros)->count(),
        ];
    }

    /**
     * @param  Filtros  $filtros
     * @return Collection<int, object{produto_id: int, produto: string, categoria: ?string, itens: int, total_seg: float, preparo_seg: ?float}>
     */
    public function produtosMaisLentos(CarbonInterface $inicio, CarbonInterface $fim, array $filtros = [], int $limite = 10): Collection
    {
        return $this->concluidos($inicio, $fim, $filtros)
            ->join('produtos as p', 'p.id', '=', 'ip.item_pedido_produto_id')
            ->leftJoin('categorias as c', 'c.id', '=', 'p.produto_categoria_id')
            ->groupBy('p.id', 'p.produto_descricao', 'c.categoria_nome')
            ->select('p.id as produto_id', 'p.produto_descricao as produto', 'c.categoria_nome as categoria')
            ->selectRaw('COUNT(*) AS itens')
            ->selectRaw('AVG(TIMESTAMPDIFF(SECOND, pi.liberado_em, pi.concluido_em)) AS total_seg')
            ->selectRaw('AVG(CASE WHEN pi.iniciado_em IS NOT NULL THEN TIMESTAMPDIFF(SECOND, pi.iniciado_em, pi.concluido_em) END) AS preparo_seg')
            ->orderByDesc('total_seg')
            ->limit($limite)
            ->get();
    }

    /**
     * @param  Filtros  $filtros
     * @return Collection<int, object{operador_id: ?int, operador: string, itens: int, preparo_seg: ?float, total_seg: float}>
     */
    public function operadores(CarbonInterface $inicio, CarbonInterface $fim, array $filtros = []): Collection
    {
        return $this->concluidos($inicio, $fim, $filtros)
            ->groupBy('pi.operador_id', 'pi.operador_nome')
            ->select('pi.operador_id')
            ->selectRaw("COALESCE(pi.operador_nome, 'Sem operador') AS operador")
            ->selectRaw('COUNT(*) AS itens')
            ->selectRaw('AVG(CASE WHEN pi.iniciado_em IS NOT NULL THEN TIMESTAMPDIFF(SECOND, pi.iniciado_em, pi.concluido_em) END) AS preparo_seg')
            ->selectRaw('AVG(TIMESTAMPDIFF(SECOND, pi.liberado_em, pi.concluido_em)) AS total_seg')
            ->orderByDesc('itens')
            ->get();
    }

    /**
     * @param  Filtros  $filtros
     * @return Collection<int, object{motivo: string, rotulo: string, total: int}>
     */
    public function refacoesPorMotivo(CarbonInterface $inicio, CarbonInterface $fim, array $filtros = []): Collection
    {
        return $this->refeitos($inicio, $fim, $filtros)
            ->groupBy('pi.motivo_refacao')
            ->select('pi.motivo_refacao as motivo')
            ->selectRaw('COUNT(*) AS total')
            ->orderByDesc('total')
            ->get()
            ->map(function (object $linha): object {
                $linha->rotulo = MotivoRefacao::tryFrom((string) $linha->motivo)?->getLabel() ?? 'Não informado';

                return $linha;
            });
    }

    /**
     * Cobertura de fichas: quanto do que a cozinha produziu (produtos
     * "produzido" vendidos no período) tem ficha de preparo ativa. Pizza de
     * vários sabores conta pelo produto da linha (o 1º sabor).
     *
     * @return array{percentual: ?float, quantidade_total: float, sem_ficha: Collection<int, object{produto_id: int, produto: string, categoria: ?string, quantidade: float}>}
     */
    public function coberturaFichas(CarbonInterface $inicio, CarbonInterface $fim): array
    {
        $linhas = DB::table('itens_pedidos as ip')
            ->join('pedidos as pe', 'pe.id', '=', 'ip.item_pedido_pedido_id')
            ->whereNull('pe.deleted_at')
            ->join('produtos as p', 'p.id', '=', 'ip.item_pedido_produto_id')
            ->leftJoin('categorias as c', 'c.id', '=', 'p.produto_categoria_id')
            ->leftJoin('fichas_tecnicas as ft', fn ($join) => $join
                ->on('ft.produto_id', '=', 'p.id')
                ->whereNull('ft.deleted_at')
                ->where('ft.ativo', true))
            ->where('ip.item_pedido_status', 'INSERIDO')
            ->whereNotIn('pe.pedido_status', [StatusPedidoEnum::INICIADO->value, StatusPedidoEnum::CANCELADO->value])
            ->whereBetween('pe.pedido_datahora_abertura', [$inicio, $fim])
            ->where('p.produto_tipo', ProdutoTipoEnum::PRODUZIDO->value)
            ->where('p.produto_dispensa_producao', false)
            ->groupBy('p.id', 'p.produto_descricao', 'c.categoria_nome', 'ft.id')
            ->select('p.id as produto_id', 'p.produto_descricao as produto', 'c.categoria_nome as categoria', 'ft.id as ficha_id')
            ->selectRaw('SUM(ip.item_pedido_quantidade) AS quantidade')
            ->orderByDesc('quantidade')
            ->get();

        $total = (float) $linhas->sum('quantidade');
        $comFicha = (float) $linhas->whereNotNull('ficha_id')->sum('quantidade');

        return [
            'percentual' => $total > 0 ? round($comFicha / $total * 100, 1) : null,
            'quantidade_total' => $total,
            'sem_ficha' => $linhas->whereNull('ficha_id')->values(),
        ];
    }

    /**
     * Retrato de agora, por setor ativo (CozinhaAgoraWidget).
     *
     * @return Collection<int, array{setor: SetorProducao, fila: int, em_preparo: int, atrasados: int, tablets_online: int, tablets: int}>
     */
    public function agoraPorSetor(): Collection
    {
        $setores = SetorProducao::ativos()->ordenados()->get();
        $contagens = ProducaoItem::query()
            ->join('setores_producao as s', 's.id', '=', 'producao_itens.setor_producao_id')
            ->whereIn('producao_itens.status', StatusItemProducao::valoresAtivosNaEstacao())
            ->groupBy('producao_itens.setor_producao_id')
            ->select('producao_itens.setor_producao_id')
            ->selectRaw("SUM(producao_itens.status = 'na_fila') AS fila")
            ->selectRaw("SUM(producao_itens.status = 'em_preparo') AS em_preparo")
            // "Agora" vem do PHP: o NOW() do MySQL pode estar em outro fuso que o da aplicação.
            ->selectRaw('SUM(producao_itens.liberado_em < DATE_SUB(?, INTERVAL COALESCE(producao_itens.tempo_previsto_min, s.tempo_alvo_min) MINUTE)) AS atrasados', [now()->toDateTimeString()])
            ->get()
            ->keyBy('setor_producao_id');
        $tablets = DispositivoCozinha::ativos()->get(['id', 'setores', 'ultimo_ping_em']);

        return $setores->map(function (SetorProducao $setor) use ($contagens, $tablets): array {
            $doSetor = $tablets->filter(fn (DispositivoCozinha $d) => $d->atendeSetor($setor->id));
            $linha = $contagens->get($setor->id);

            return [
                'setor' => $setor,
                'fila' => (int) ($linha->fila ?? 0),
                'em_preparo' => (int) ($linha->em_preparo ?? 0),
                'atrasados' => (int) ($linha->atrasados ?? 0),
                'tablets' => $doSetor->count(),
                'tablets_online' => $doSetor->filter(fn (DispositivoCozinha $d) => $d->estaOnline())->count(),
            ];
        });
    }

    /**
     * Linhas para o CSV: um item concluído por linha.
     *
     * @param  Filtros  $filtros
     * @return LazyCollection<int, object>
     */
    public function linhasDetalhe(CarbonInterface $inicio, CarbonInterface $fim, array $filtros = []): LazyCollection
    {
        return $this->concluidos($inicio, $fim, $filtros)
            ->join('setores_producao as s', 's.id', '=', 'pi.setor_producao_id')
            ->join('produtos as p', 'p.id', '=', 'ip.item_pedido_produto_id')
            ->select('pi.id', 'pi.pedido_id', 'pi.referencia', 's.nome as setor', 'p.produto_descricao as produto', 'pi.quantidade', 'pi.operador_nome',
                'pi.liberado_em', 'pi.iniciado_em', 'pi.concluido_em', 'pi.tempo_previsto_min', 's.tempo_alvo_min', 'pi.geracao')
            ->orderBy('pi.concluido_em')
            ->lazy(500);
    }

    /** @param  Filtros  $filtros */
    private function concluidos(CarbonInterface $inicio, CarbonInterface $fim, array $filtros): Builder
    {
        return $this->base($filtros)
            ->whereNotNull('pi.concluido_em')
            ->whereNotNull('pi.liberado_em')
            ->where('pi.status', '!=', StatusItemProducao::Cancelado->value)
            ->whereBetween('pi.concluido_em', [$inicio, $fim])
            ->whereNotExists(fn (Builder $q) => $q
                ->selectRaw('1')
                ->from('producao_eventos as pe')
                ->whereColumn('pe.producao_item_id', 'pi.id')
                ->where('pe.acao', AcaoProducao::ForcadoPeloPainel->value));
    }

    /** @param  Filtros  $filtros */
    private function refeitos(CarbonInterface $inicio, CarbonInterface $fim, array $filtros): Builder
    {
        return $this->base($filtros)
            ->whereNotNull('pi.refeito_de_id')
            ->whereBetween('pi.created_at', [$inicio, $fim]);
    }

    /** @param  Filtros  $filtros */
    private function base(array $filtros): Builder
    {
        return DB::table('producao_itens as pi')
            ->join('itens_pedidos as ip', 'ip.id', '=', 'pi.item_pedido_id')
            ->whereNull('pi.deleted_at')
            ->when($filtros['setor_id'] ?? null, fn (Builder $q, $id) => $q->where('pi.setor_producao_id', $id))
            ->when($filtros['produto_id'] ?? null, fn (Builder $q, $id) => $q->where('ip.item_pedido_produto_id', $id))
            ->when($filtros['operador_id'] ?? null, fn (Builder $q, $id) => $q->where('pi.operador_id', $id));
    }

    private function numero(mixed $valor): ?float
    {
        return $valor === null ? null : round((float) $valor, 1);
    }
}
