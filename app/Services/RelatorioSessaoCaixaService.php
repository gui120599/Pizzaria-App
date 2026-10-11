<?php

namespace App\Services;

use App\Enums\FormaTrocoEnum;
use App\Enums\PedidoOrigemEnum;
use App\Models\CorrecaoPagamento;
use App\Models\LancamentoPagamento;
use App\Models\Maquininha;
use App\Models\PagamentosVenda;
use App\Models\Pedido;
use App\Models\Venda;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Relatório de Sessão de Caixa: uma linha por venda finalizada com o que foi
 * lançado × o que o pedido combinou, o fiado gerado, o troco e avisos de
 * lançamento suspeito. Complementa o Relatório de Fechamento de Caixa (que é
 * agregado) para achar a venda que fez a conferência não bater.
 *
 * Recorte igual ao do fechamento (inicio, fim, caixa_id, sessoes — ver
 * RelatorioFechamentoCaixaService::vendasQuery). Os filtros extras (opcoes,
 * maquininha_id, origem, cliente_id, situacao) são aplicados sobre as linhas.
 */
class RelatorioSessaoCaixaService
{
    public const AVISO_FORMA_DIVERGENTE = 'forma_divergente';

    public const AVISO_TROCO_FORA_DINHEIRO = 'troco_fora_dinheiro';

    public const AVISO_FIADO_COM_FORMA_NO_PEDIDO = 'fiado_com_forma_no_pedido';

    /** Venda lançada direto no PDV, sem pedido (valor do filtro de origem). */
    public const ORIGEM_DIRETO = 'direto';

    public const SITUACAO_FIADO = 'fiado';

    public const SITUACAO_DIVERGENCIA = 'divergencia';

    /** @var array<string, mixed> */
    private array $cache = [];

    /**
     * @param  array{inicio?: ?string, fim?: ?string, caixa_id?: int|string|null, sessoes?: array<int, int|string>|null, opcoes?: array<int, int|string>|null, maquininha_id?: int|string|null, origem?: ?string, cliente_id?: int|string|null, situacao?: ?string}  $filtros
     */
    public function __construct(private readonly array $filtros = []) {}

    /** Relatório de fechamento com o mesmo recorte — período, sessões e conferência saem dele. */
    public function fechamento(): RelatorioFechamentoCaixaService
    {
        return $this->cache['fechamento'] ??= new RelatorioFechamentoCaixaService($this->filtros);
    }

    /**
     * Uma linha por venda FINALIZADA do recorte, já com os filtros extras.
     *
     * @return Collection<int, array{key: string, venda_id: int, sessao_id: ?int, finalizada_em: ?Carbon, cliente_id: ?int, cliente: string, origens: array<int, string>, origens_valores: array<int, string>, total: float, pago: float, troco: float, fiado: float, status_nfe: ?string, pagamentos: array<int, array{id: int, opcao_id: ?int, forma: string, desc_nfe: ?string, valor: float, troco: float, forma_troco: ?string, maquininha_id: ?int, maquininha: ?string}>, formas_pedido: array<int, string>, formas_pedido_nomes: array<int, string>, formas_pedido_ids: array<int, int>, formas_pedido_estruturadas: bool, observacoes_pedido: array<int, string>, avisos: array<int, array{tipo: string, texto: string}>, correcoes: array<int, string>}>
     */
    public function vendas(): Collection
    {
        return $this->cache['vendas'] ??= $this->todasAsVendas()
            ->filter(fn (array $linha): bool => $this->passaNosFiltros($linha))
            ->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function todasAsVendas(): Collection
    {
        $taxas = app(TaxaMaquininhaService::class);
        $maquininhas = Maquininha::withTrashed()->pluck('nome', 'id');

        return $this->vendasQuery()
            ->with([
                'cliente',
                'pagamentos.opcaoPagamento' => fn ($query) => $query->withTrashed(),
                'pedidos.pagamentosCombinados',
                'lancamentos',
                'correcoesPagamento.user',
            ])
            ->orderBy('venda_datahora_finalizada')
            ->get()
            ->map(function (Venda $venda) use ($taxas, $maquininhas): array {
                $pagamentos = $venda->pagamentos->map(function (PagamentosVenda $pagamento) use ($taxas, $maquininhas): array {
                    $maquininhaId = $pagamento->opcaoPagamento?->tipoMaquininha() ? $taxas->resolverMaquininhaId($pagamento) : null;

                    return [
                        'id' => $pagamento->id,
                        'opcao_id' => $pagamento->pg_venda_opcaopagamento_id,
                        'forma' => (string) ($pagamento->opcaoPagamento?->opcaopag_nome ?? 'Forma #'.$pagamento->pg_venda_opcaopagamento_id),
                        'desc_nfe' => $pagamento->opcaoPagamento?->opcaopag_desc_nfe,
                        'valor' => (float) $pagamento->pg_venda_valor_pagamento,
                        'troco' => (float) $pagamento->pg_venda_valor_troco,
                        'forma_troco' => (float) $pagamento->pg_venda_valor_troco > 0
                            ? ($pagamento->pg_venda_forma_troco ?? FormaTrocoEnum::Dinheiro)->getLabel()
                            : null,
                        'maquininha_id' => $maquininhaId,
                        'maquininha' => $maquininhaId ? ($maquininhas[$maquininhaId] ?? null) : null,
                    ];
                })->values()->all();

                $linha = [
                    'key' => (string) $venda->id,
                    'venda_id' => $venda->id,
                    'sessao_id' => $venda->venda_sessao_caixa_id,
                    'finalizada_em' => $venda->venda_datahora_finalizada,
                    'cliente_id' => $venda->venda_cliente_id,
                    'cliente' => (string) ($venda->cliente?->cliente_nome ?? ''),
                    'origens' => $venda->pedidos
                        ->map(fn (Pedido $pedido): string => $pedido->pedido_origem?->label() ?? 'Pedido')
                        ->unique()
                        ->values()
                        ->all(),
                    'origens_valores' => $venda->pedidos
                        ->map(fn (Pedido $pedido): ?string => $pedido->pedido_origem?->value)
                        ->filter()
                        ->unique()
                        ->values()
                        ->all(),
                    'total' => (float) $venda->venda_valor_total,
                    'pago' => (float) $venda->venda_valor_pago,
                    'troco' => (float) $venda->venda_valor_troco,
                    'fiado' => round((float) $venda->lancamentos->sum('valor'), 2),
                    'status_nfe' => $venda->venda_status_nfe,
                    'pagamentos' => $pagamentos,
                    ...$this->formasDoPedido($venda),
                    'correcoes' => $venda->correcoesPagamento
                        ->sortBy('created_at')
                        ->map(fn (CorrecaoPagamento $correcao): string => $correcao->tipo->getLabel()
                            .' — '.($correcao->user?->name ?? 'Sistema')
                            .' em '.$correcao->created_at->format('d/m H:i'))
                        ->values()
                        ->all(),
                ];

                $linha['avisos'] = $this->avisos($linha);

                return $linha;
            });
    }

    /**
     * Formas combinadas nos pedidos da venda: as estruturadas
     * (pagamentos_pedidos) e, só sem elas, a descrição livre do pedido.
     *
     * @return array{formas_pedido: array<int, string>, formas_pedido_nomes: array<int, string>, formas_pedido_ids: array<int, int>, formas_pedido_estruturadas: bool, observacoes_pedido: array<int, string>}
     */
    private function formasDoPedido(Venda $venda): array
    {
        $combinados = $venda->pedidos->flatMap->pagamentosCombinados;

        $formas = $combinados->isNotEmpty()
            ? $combinados->map(fn ($combinado): string => trim((string) $combinado->pg_pedido_opcaopagamento_nome)
                .' R$ '.number_format((float) $combinado->pg_pedido_valor, 2, ',', '.')
                .($combinado->pg_pedido_pago ? ' (pago no pedido)' : ''))
            : $venda->pedidos->pluck('pedido_descricao_pagamento')->map(fn ($descricao): string => trim((string) $descricao));

        return [
            'formas_pedido' => $formas->filter()->unique()->values()->all(),
            'formas_pedido_nomes' => ($combinados->isNotEmpty()
                ? $combinados->pluck('pg_pedido_opcaopagamento_nome')
                : $venda->pedidos->pluck('pedido_descricao_pagamento'))
                ->map(fn ($nome): string => mb_strtolower(trim((string) $nome)))
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'formas_pedido_ids' => $combinados->pluck('pg_pedido_opcaopagamento_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all(),
            'formas_pedido_estruturadas' => $combinados->isNotEmpty(),
            'observacoes_pedido' => $venda->pedidos->pluck('pedido_observacao_pagamento')
                ->map(fn ($observacao): string => trim((string) $observacao))
                ->filter()
                ->unique()
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $linha
     * @return array<int, array{tipo: string, texto: string}>
     */
    private function avisos(array $linha): array
    {
        $avisos = [];
        $pagamentos = collect($linha['pagamentos']);

        if ($pagamentos->isNotEmpty() && $linha['formas_pedido_nomes'] !== []) {
            $bateId = $pagamentos->contains(fn (array $pagamento): bool => in_array($pagamento['opcao_id'], $linha['formas_pedido_ids'], true));
            $bateNome = $pagamentos->contains(fn (array $pagamento): bool => in_array(mb_strtolower(trim($pagamento['forma'])), $linha['formas_pedido_nomes'], true));

            if (! $bateId && ! $bateNome) {
                $avisos[] = [
                    'tipo' => self::AVISO_FORMA_DIVERGENTE,
                    'texto' => 'Pedido combinou '.implode(', ', $linha['formas_pedido'])
                        .'; venda lançada em '.$pagamentos->pluck('forma')->unique()->implode(', '),
                ];
            }
        }

        foreach ($pagamentos as $pagamento) {
            if ($pagamento['troco'] > 0 && $pagamento['desc_nfe'] !== 'cash') {
                $avisos[] = [
                    'tipo' => self::AVISO_TROCO_FORA_DINHEIRO,
                    'texto' => 'Troco de R$ '.number_format($pagamento['troco'], 2, ',', '.').' em '.$pagamento['forma']
                        .' — saiu em '.$pagamento['forma_troco'],
                ];
            }
        }

        $pedidoDizPago = collect($linha['observacoes_pedido'])->contains(fn (string $observacao): bool => (bool) preg_match('/\bPAGO\b/iu', $observacao));

        if ($linha['fiado'] > 0 && ($linha['formas_pedido_estruturadas'] || $pedidoDizPago)) {
            $avisos[] = [
                'tipo' => self::AVISO_FIADO_COM_FORMA_NO_PEDIDO,
                'texto' => 'Foi para o fiado, mas o pedido tinha '
                    .($linha['formas_pedido_estruturadas'] ? 'forma combinada ('.implode(', ', $linha['formas_pedido']).')' : 'observação "pago"'),
            ];
        }

        return $avisos;
    }

    /** @param  array<string, mixed>  $linha */
    private function passaNosFiltros(array $linha): bool
    {
        $opcoes = array_map('intval', array_filter((array) ($this->filtros['opcoes'] ?? [])));
        if ($opcoes !== [] && ! collect($linha['pagamentos'])->contains(fn (array $pagamento): bool => in_array((int) $pagamento['opcao_id'], $opcoes, true))) {
            return false;
        }

        $maquininhaId = ! empty($this->filtros['maquininha_id']) ? (int) $this->filtros['maquininha_id'] : null;
        if ($maquininhaId && ! collect($linha['pagamentos'])->contains(fn (array $pagamento): bool => $pagamento['maquininha_id'] === $maquininhaId)) {
            return false;
        }

        $origem = $this->filtros['origem'] ?? null;
        if (filled($origem)) {
            $passa = $origem === self::ORIGEM_DIRETO
                ? $linha['origens_valores'] === []
                : in_array($origem, $linha['origens_valores'], true);

            if (! $passa) {
                return false;
            }
        }

        $clienteId = ! empty($this->filtros['cliente_id']) ? (int) $this->filtros['cliente_id'] : null;
        if ($clienteId && $linha['cliente_id'] !== $clienteId) {
            return false;
        }

        return match ($this->filtros['situacao'] ?? null) {
            self::SITUACAO_FIADO => $linha['fiado'] > 0,
            self::SITUACAO_DIVERGENCIA => $linha['avisos'] !== [],
            default => true,
        };
    }

    /** Mesmo recorte de RelatorioFechamentoCaixaService::vendasQuery() para vendas FINALIZADA. */
    private function vendasQuery(): Builder
    {
        $fechamento = $this->fechamento();
        $query = Venda::query()->where('venda_status', 'FINALIZADA');

        if ($fechamento->filtrandoPorSessao()) {
            return $query->whereIn('venda_sessao_caixa_id', $fechamento->sessoesSelecionadas());
        }

        $caixaId = ! empty($this->filtros['caixa_id']) ? (int) $this->filtros['caixa_id'] : null;

        return $query
            ->whereBetween('venda_datahora_finalizada', $fechamento->periodo())
            ->when($caixaId, fn (Builder $query, int $caixaId) => $query->whereHas(
                'sessaoCaixa',
                fn (Builder $sessao) => $sessao->where('sessaocaixa_caixa_id', $caixaId),
            ));
    }

    /**
     * @return array{vendas: int, total: float, recebido: float, fiado_gerado: float, fiado_recebido: float, troco_fora_dinheiro: float, com_aviso: int}
     */
    public function resumo(): array
    {
        $vendas = $this->vendas();

        return [
            'vendas' => $vendas->count(),
            'total' => round($vendas->sum('total'), 2),
            'recebido' => round($vendas->sum('pago'), 2),
            'fiado_gerado' => round($vendas->sum('fiado'), 2),
            'fiado_recebido' => round($this->fiadoRecebido()->sum('valor'), 2),
            'troco_fora_dinheiro' => round($vendas->sum(fn (array $linha): float => collect($linha['pagamentos'])
                ->filter(fn (array $pagamento): bool => $pagamento['desc_nfe'] !== 'cash')
                ->sum('troco')), 2),
            'com_aviso' => $vendas->filter(fn (array $linha): bool => $linha['avisos'] !== [])->count(),
        ];
    }

    /**
     * Fiado gerado: um título por venda do recorte (com os filtros).
     *
     * @return Collection<int, array{key: string, lancamento_id: int, venda_id: int, finalizada_em: ?Carbon, cliente: string, valor: float, recebido: float, status: string}>
     */
    public function fiadoGerado(): Collection
    {
        $ids = $this->vendas()->filter(fn (array $linha): bool => $linha['fiado'] > 0)->pluck('venda_id');

        if ($ids->isEmpty()) {
            return collect();
        }

        return Venda::query()
            ->whereKey($ids)
            ->with(['lancamentos.pagamentos', 'lancamentos.cliente'])
            ->orderBy('venda_datahora_finalizada')
            ->get()
            ->flatMap(fn (Venda $venda) => $venda->lancamentos->map(fn ($lancamento): array => [
                'key' => (string) $lancamento->id,
                'lancamento_id' => $lancamento->id,
                'venda_id' => $venda->id,
                'finalizada_em' => $venda->venda_datahora_finalizada,
                'cliente' => (string) ($lancamento->cliente?->cliente_nome ?? ''),
                'valor' => (float) $lancamento->valor,
                'recebido' => round((float) $lancamento->pagamentos->sum('valor'), 2),
                'status' => (string) ($lancamento->status?->getLabel() ?? $lancamento->status),
            ]))
            ->values();
    }

    /**
     * Fiado recebido nas sessões do recorte (pode ser de venda de outro dia).
     *
     * @return Collection<int, array{key: string, id: int, sessao_id: int, data: ?Carbon, cliente: string, venda_id: ?int, forma: string, forma_valor: ?string, entra_no_caixa: bool, maquininha: ?string, valor: float}>
     */
    public function fiadoRecebido(): Collection
    {
        if (isset($this->cache['fiado_recebido'])) {
            return $this->cache['fiado_recebido'];
        }

        $clienteId = ! empty($this->filtros['cliente_id']) ? (int) $this->filtros['cliente_id'] : null;

        return $this->cache['fiado_recebido'] = LancamentoPagamento::query()
            ->whereIn('sessao_caixa_id', $this->fechamento()->sessoes()->modelKeys())
            ->whereHas('lancamento', fn (Builder $query) => $query
                ->where('tipo', 'receber')
                ->when($clienteId, fn (Builder $query, int $clienteId) => $query->where('cliente_id', $clienteId)))
            ->with(['lancamento.cliente', 'maquininha' => fn ($query) => $query->withTrashed()])
            ->orderBy('id')
            ->get()
            ->map(fn (LancamentoPagamento $recebimento): array => [
                'key' => (string) $recebimento->id,
                'id' => $recebimento->id,
                'sessao_id' => (int) $recebimento->sessao_caixa_id,
                'data' => $recebimento->created_at,
                'cliente' => (string) ($recebimento->lancamento?->cliente?->cliente_nome ?? ''),
                'venda_id' => $recebimento->lancamento?->venda_id,
                'forma' => $recebimento->forma_pagamento?->getLabel() ?? 'Não informada',
                'forma_valor' => $recebimento->forma_pagamento?->value,
                'entra_no_caixa' => $recebimento->forma_pagamento?->entraNoCaixa() ?? true,
                'maquininha' => $recebimento->maquininha?->nome,
                'valor' => (float) $recebimento->valor,
            ]);
    }

    /** @return array<string, string> */
    public static function opcoesOrigem(): array
    {
        return collect(PedidoOrigemEnum::cases())
            ->mapWithKeys(fn (PedidoOrigemEnum $origem): array => [$origem->value => $origem->label()])
            ->put(self::ORIGEM_DIRETO, 'Direto no PDV (sem pedido)')
            ->all();
    }
}
