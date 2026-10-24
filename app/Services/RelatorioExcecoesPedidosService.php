<?php

namespace App\Services;

use App\Enums\MotivoCancelamentoEnum;
use App\Models\ItensPedido;
use App\Models\Pedido;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Exceções e perdas dos pedidos num período: cancelamentos, itens removidos,
 * descontos, cortesias, exclusões e entregas sem pagamento.
 *
 * Cada indicador usa a data do próprio evento: cancelamento pela data do
 * cancelamento, item removido pela última alteração do item, exclusão pela
 * data da exclusão e cortesia pela data da cortesia. Descontos e pedidos
 * entregues sem pagamento seguem a abertura do pedido. Rascunhos (INICIADO)
 * nunca entram, e pedido excluído só aparece no bloco de exclusões.
 *
 * As contagens por usuário servem para acompanhamento; não medem tempo.
 *
 * @phpstan-type Resumo array{cancelados: array{quantidade: int, valor: float, base: float, percentual: float}, itens_removidos: array{quantidade: int, valor: float}, descontos: array{pedidos: int, valor: float, percentual: float}, cortesias: array{itens: int, valor: float, custo: float}, excluidos: array{quantidade: int, valor: float}, nao_pagos: array{quantidade: int, valor: float}}
 */
class RelatorioExcecoesPedidosService
{
    public const SEM_ATENDENTE = 'Sem atendente (cardápio)';

    public const NAO_INFORMADO = 'Não informado';

    private readonly CarbonInterface $inicio;

    private readonly CarbonInterface $fim;

    public function __construct(CarbonInterface $inicio, CarbonInterface $fim)
    {
        $this->inicio = $inicio->copy()->startOfDay();
        $this->fim = $fim->copy()->endOfDay();
    }

    /**
     * @return Resumo
     */
    public function resumo(): array
    {
        $cancelados = $this->cancelados()
            ->selectRaw('COUNT(*) as quantidade, COALESCE(SUM(pedido_valor_total), 0) as valor')
            ->first();
        $baseCancelamento = (float) $this->pedidosAbertos()->sum('pedido_valor_total');

        $removidos = $this->itensRemovidos()
            ->selectRaw('COUNT(*) as quantidade, COALESCE(SUM(item_pedido_valor), 0) as valor')
            ->first();

        $descontos = $this->pedidosComDesconto()
            ->selectRaw('COALESCE(SUM(CASE WHEN pedido_valor_desconto > 0 THEN 1 ELSE 0 END), 0) as pedidos, COALESCE(SUM(pedido_valor_desconto), 0) as valor, COALESCE(SUM(pedido_valor_itens), 0) as bruto')
            ->first();

        $cortesias = (new RelatorioCortesiasService($this->inicio, $this->fim))->totais();

        $excluidos = $this->excluidos()
            ->selectRaw('COUNT(*) as quantidade, COALESCE(SUM(pedido_valor_total), 0) as valor')
            ->first();

        $naoPagos = $this->entreguesNaoPagos()
            ->selectRaw('COUNT(*) as quantidade, COALESCE(SUM(pedido_valor_total), 0) as valor')
            ->first();

        $valorCancelado = round((float) $cancelados->valor, 2);
        $valorDescontos = round((float) $descontos->valor, 2);

        return [
            'cancelados' => [
                'quantidade' => (int) $cancelados->quantidade,
                'valor' => $valorCancelado,
                'base' => round($baseCancelamento, 2),
                'percentual' => $baseCancelamento > 0 ? $valorCancelado / $baseCancelamento * 100 : 0.0,
            ],
            'itens_removidos' => [
                'quantidade' => (int) $removidos->quantidade,
                'valor' => round((float) $removidos->valor, 2),
            ],
            'descontos' => [
                'pedidos' => (int) $descontos->pedidos,
                'valor' => $valorDescontos,
                'percentual' => (float) $descontos->bruto > 0 ? $valorDescontos / (float) $descontos->bruto * 100 : 0.0,
            ],
            'cortesias' => [
                'itens' => $cortesias['itens'],
                'valor' => $cortesias['valor_tabela'],
                'custo' => $cortesias['custo'],
            ],
            'excluidos' => [
                'quantidade' => (int) $excluidos->quantidade,
                'valor' => round((float) $excluidos->valor, 2),
            ],
            'nao_pagos' => [
                'quantidade' => (int) $naoPagos->quantidade,
                'valor' => round((float) $naoPagos->valor, 2),
            ],
        ];
    }

    /**
     * Cancelamentos por motivo, do mais frequente ao menos, com as últimas
     * observações digitadas (o motivo "Outro" exige texto).
     *
     * @return Collection<string, array{chave: string, motivo: string, quantidade: int, valor: float, percentual: float, observacoes: array<int, string>}>
     */
    public function cancelamentosPorMotivo(): Collection
    {
        $linhas = $this->cancelados()
            ->selectRaw('pedido_motivo_cancelamento as motivo, COUNT(*) as quantidade, COALESCE(SUM(pedido_valor_total), 0) as valor')
            ->groupBy('pedido_motivo_cancelamento')
            ->get();

        $observacoes = $this->cancelados()
            ->whereNotNull('pedido_motivo_cancelamento_observacao')
            ->where('pedido_motivo_cancelamento_observacao', '!=', '')
            ->orderByDesc('pedido_datahora_cancelado')
            ->orderByDesc('id')
            ->selectRaw('id, pedido_motivo_cancelamento as motivo, pedido_motivo_cancelamento_observacao as observacao')
            ->get()
            ->groupBy(fn (Pedido $pedido): string => (string) $pedido->getAttribute('motivo'));

        $total = (float) $linhas->sum('valor');

        return $linhas
            ->sortByDesc(fn (Pedido $linha): int => (int) $linha->getAttribute('quantidade'))
            ->mapWithKeys(function (Pedido $linha) use ($observacoes, $total): array {
                $motivo = (string) $linha->getAttribute('motivo');
                $valor = round((float) $linha->getAttribute('valor'), 2);

                return [($motivo ?: 'sem') => [
                    'chave' => $motivo ?: 'sem',
                    'motivo' => MotivoCancelamentoEnum::tryFrom($motivo)?->label() ?? self::NAO_INFORMADO,
                    'quantidade' => (int) $linha->getAttribute('quantidade'),
                    'valor' => $valor,
                    'percentual' => $total > 0 ? $valor / $total * 100 : 0.0,
                    'observacoes' => ($observacoes->get($motivo) ?? collect())
                        ->take(5)
                        ->map(fn (Pedido $pedido): string => "#{$pedido->id}: {$pedido->getAttribute('observacao')}")
                        ->all(),
                ]];
            });
    }

    /**
     * Cancelamentos por quem lançou o pedido e quem cancelou, em ordem
     * alfabética (sem ranking de "piores").
     *
     * @return Collection<string, array{chave: string, lancou: string, cancelou: string, quantidade: int, valor: float}>
     */
    public function cancelamentosPorUsuario(): Collection
    {
        $linhas = $this->cancelados()
            ->selectRaw('pedido_usuario_garcom_id as lancou_id, pedido_usuario_cancelou_id as cancelou_id, COUNT(*) as quantidade, COALESCE(SUM(pedido_valor_total), 0) as valor')
            ->groupBy('pedido_usuario_garcom_id', 'pedido_usuario_cancelou_id')
            ->get();

        $nomes = $this->nomes($linhas->pluck('lancou_id')->merge($linhas->pluck('cancelou_id')));

        return $linhas
            ->map(function (Pedido $linha) use ($nomes): array {
                $lancouId = $linha->getAttribute('lancou_id');
                $cancelouId = $linha->getAttribute('cancelou_id');

                return [
                    'chave' => ($lancouId ?? 'sem').'-'.($cancelouId ?? 'sem'),
                    'lancou' => $lancouId === null ? self::SEM_ATENDENTE : ($nomes[$lancouId] ?? self::NAO_INFORMADO),
                    'cancelou' => $cancelouId === null ? self::NAO_INFORMADO : ($nomes[$cancelouId] ?? self::NAO_INFORMADO),
                    'quantidade' => (int) $linha->getAttribute('quantidade'),
                    'valor' => round((float) $linha->getAttribute('valor'), 2),
                ];
            })
            ->sort(fn (array $a, array $b): int => $this->compararNomes($a['lancou'], $b['lancou']) ?: $this->compararNomes($a['cancelou'], $b['cancelou']))
            ->keyBy('chave');
    }

    /**
     * Itens removidos por quem os removeu. Itens antigos não guardam o autor
     * e ficam em "Não informado".
     *
     * @return Collection<string, array{chave: string, usuario: string, itens: int, valor: float}>
     */
    public function itensRemovidosPorUsuario(): Collection
    {
        $linhas = $this->itensRemovidos()
            ->selectRaw('item_pedido_usuario_removeu as usuario_id, COUNT(*) as itens, COALESCE(SUM(item_pedido_valor), 0) as valor')
            ->groupBy('item_pedido_usuario_removeu')
            ->get();

        $nomes = $this->nomes($linhas->pluck('usuario_id'));

        return $linhas
            ->map(fn (ItensPedido $linha): array => [
                'chave' => (string) ($linha->getAttribute('usuario_id') ?? 'sem'),
                'usuario' => $nomes[$linha->getAttribute('usuario_id')] ?? self::NAO_INFORMADO,
                'itens' => (int) $linha->getAttribute('itens'),
                'valor' => round((float) $linha->getAttribute('valor'), 2),
            ])
            ->sort(fn (array $a, array $b): int => $this->compararNomes($a['usuario'], $b['usuario']))
            ->keyBy('chave');
    }

    /**
     * Pedidos lançados por usuário: volume, valor e as exceções ligadas a
     * cada um. Sem nenhuma medida de tempo.
     *
     * Pedidos lançados e valor seguem a abertura; cancelados são os pedidos
     * lançados pelo usuário que foram cancelados no período; itens removidos
     * são os que o usuário removeu. O valor e o ticket médio não contam
     * pedidos cancelados.
     *
     * @return Collection<string, array{chave: string, usuario: string, pedidos: int, valor: float, ticket_medio: ?float, cancelados: int, itens_removidos: int}>
     */
    public function lancamentosPorUsuario(): Collection
    {
        $lancados = $this->pedidosAbertos()
            ->selectRaw("pedido_usuario_garcom_id as usuario_id, COUNT(*) as pedidos, COALESCE(SUM(CASE WHEN pedido_status != 'CANCELADO' THEN pedido_valor_total ELSE 0 END), 0) as valor, COALESCE(SUM(CASE WHEN pedido_status != 'CANCELADO' THEN 1 ELSE 0 END), 0) as validos")
            ->groupBy('pedido_usuario_garcom_id')
            ->get()
            ->keyBy(fn (Pedido $linha): string => (string) ($linha->getAttribute('usuario_id') ?? 'sem'));

        $cancelados = $this->cancelados()
            ->selectRaw('pedido_usuario_garcom_id as usuario_id, COUNT(*) as quantidade')
            ->groupBy('pedido_usuario_garcom_id')
            ->get()
            ->keyBy(fn (Pedido $linha): string => (string) ($linha->getAttribute('usuario_id') ?? 'sem'));

        $removidos = $this->itensRemovidos()
            ->whereNotNull('item_pedido_usuario_removeu')
            ->selectRaw('item_pedido_usuario_removeu as usuario_id, COUNT(*) as itens')
            ->groupBy('item_pedido_usuario_removeu')
            ->get()
            ->keyBy(fn (ItensPedido $linha): string => (string) $linha->getAttribute('usuario_id'));

        $chaves = $lancados->keys()->merge($cancelados->keys())->merge($removidos->keys())->unique();
        $nomes = $this->nomes($chaves->reject(fn (string $chave): bool => $chave === 'sem')->map(fn (string $chave): int => (int) $chave));

        return $chaves
            ->map(function (string $chave) use ($lancados, $cancelados, $removidos, $nomes): array {
                $linha = $lancados->get($chave);
                $validos = (int) ($linha?->getAttribute('validos') ?? 0);
                $valor = round((float) ($linha?->getAttribute('valor') ?? 0), 2);

                return [
                    'chave' => $chave,
                    'usuario' => $chave === 'sem' ? self::SEM_ATENDENTE : ($nomes[(int) $chave] ?? self::NAO_INFORMADO),
                    'pedidos' => (int) ($linha?->getAttribute('pedidos') ?? 0),
                    'valor' => $valor,
                    'ticket_medio' => $validos > 0 ? round($valor / $validos, 2) : null,
                    'cancelados' => (int) ($cancelados->get($chave)?->getAttribute('quantidade') ?? 0),
                    'itens_removidos' => (int) ($removidos->get($chave)?->getAttribute('itens') ?? 0),
                ];
            })
            ->sort(fn (array $a, array $b): int => $this->compararNomes($a['usuario'], $b['usuario']))
            ->keyBy('chave');
    }

    /**
     * Pedidos excluídos (soft delete) no período, pela data da exclusão.
     *
     * @return Builder<Pedido>
     */
    public function excluidos(): Builder
    {
        return Pedido::onlyTrashed()
            ->whereBetween('deleted_at', [$this->inicio, $this->fim]);
    }

    /**
     * Pedidos entregues que continuam sem pagamento, abertos no período.
     *
     * @return Builder<Pedido>
     */
    public function entreguesNaoPagos(): Builder
    {
        return Pedido::naoPagos()->abertoEntre($this->inicio, $this->fim);
    }

    /**
     * Pedidos cancelados no período. Pedido antigo sem a data do cancelamento
     * entra pela abertura.
     *
     * @return Builder<Pedido>
     */
    private function cancelados(): Builder
    {
        return Pedido::query()
            ->where('pedido_status', 'CANCELADO')
            ->where(fn (Builder $query) => $query
                ->whereBetween('pedido_datahora_cancelado', [$this->inicio, $this->fim])
                ->orWhere(fn (Builder $semData) => $semData
                    ->whereNull('pedido_datahora_cancelado')
                    ->abertoEntre($this->inicio, $this->fim)));
    }

    /**
     * Pedidos reais (sem rascunho) abertos no período.
     *
     * @return Builder<Pedido>
     */
    private function pedidosAbertos(): Builder
    {
        return Pedido::reais()->abertoEntre($this->inicio, $this->fim);
    }

    /**
     * Pedidos abertos no período que não foram cancelados: o desconto de um
     * pedido cancelado não chegou a ser concedido.
     *
     * @return Builder<Pedido>
     */
    private function pedidosComDesconto(): Builder
    {
        return $this->pedidosAbertos()->where('pedido_status', '!=', 'CANCELADO');
    }

    /**
     * Itens removidos de pedidos reais, pela última alteração do item (não há
     * coluna própria para a data da remoção).
     *
     * @return Builder<ItensPedido>
     */
    private function itensRemovidos(): Builder
    {
        return ItensPedido::query()
            ->where('item_pedido_status', 'REMOVIDO')
            ->whereBetween('updated_at', [$this->inicio, $this->fim])
            ->whereHas('pedido', fn (Builder $pedido) => $pedido->reais());
    }

    /**
     * @param  Collection<int, mixed>  $ids
     * @return array<int, string>
     */
    private function nomes(Collection $ids): array
    {
        return User::withTrashed()
            ->whereIn('id', $ids->filter()->unique()->values())
            ->pluck('name', 'id')
            ->all();
    }

    /** Alfabética; "Sem atendente" e "Não informado" vão para o fim. */
    private function compararNomes(string $a, string $b): int
    {
        $pesoA = in_array($a, [self::SEM_ATENDENTE, self::NAO_INFORMADO], true) ? 1 : 0;
        $pesoB = in_array($b, [self::SEM_ATENDENTE, self::NAO_INFORMADO], true) ? 1 : 0;

        return $pesoA <=> $pesoB ?: strcasecmp($a, $b);
    }
}
