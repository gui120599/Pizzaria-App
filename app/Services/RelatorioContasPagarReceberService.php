<?php

namespace App\Services;

use App\Enums\StatusLancamento;
use App\Enums\TipoLancamento;
use App\Models\Cliente;
use App\Models\Lancamento;
use App\Models\Prestador;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Centraliza filtro + indicadores do Relatório de Contas a Pagar/Receber.
 * Usado tanto pelos Widgets do Filament (via $pageFilters) quanto pela view de
 * impressão/PDF (via query string), pra garantir exatamente o mesmo cálculo
 * nos dois lugares — ver App\Filament\Widgets\Concerns\InteractsComFiltrosContasPagarReceber
 * e App\Http\Controllers\RelatorioContasPagarReceberController.
 *
 * Shape esperado de $filtros (todas as chaves são opcionais):
 * tipo            => 'pagar' | 'receber' (ausente/null = Ambos)
 * status          => 'pago' | 'em_aberto' | 'vencido' | 'a_vencer'
 * a_vencer_dias   => 7 | 15 | 30 | 60 (janela do status 'a_vencer')
 * data_campo      => 'vencimento' | 'created_at' | 'data_pagamento' (default: vencimento)
 * modo_periodo    => 'livre' | 'mensal' (default: livre)
 * data_de/data_ate  (modo livre, formato Y-m-d)
 * mes             => 'Y-m' (modo mensal)
 * planos_despesa  => int[]
 * planos_receita  => int[]
 * favorecido_id, cliente_id => int
 * formas_pagamento => string[] (valores do enum FormaPagamento)
 */
class RelatorioContasPagarReceberService
{
    private const CAMPOS_DATA_VALIDOS = ['vencimento', 'created_at', 'data_pagamento'];

    private const FAIXAS_A_VENCER = [7, 15, 30, 60];

    public function __construct(private readonly array $filtros = []) {}

    public function filtros(): array
    {
        return $this->filtros;
    }

    // ─────────────────────────────────────────────────────────────
    // Query base (aplica todos os filtros da tela)
    // ─────────────────────────────────────────────────────────────

    /** Tipo forçado > tipo escolhido no filtro > null (Ambos). */
    public function query(?TipoLancamento $tipo = null): Builder
    {
        $tipo ??= $this->tipoFiltro();

        $query = $tipo ? Lancamento::query()->where('tipo', $tipo) : Lancamento::query();

        $this->aplicarCategoria($query);
        $this->aplicarFavorecido($query);
        $this->aplicarFormaPagamento($query);
        $this->aplicarStatus($query);
        $this->aplicarPeriodo($query);

        return $query;
    }

    private function tipoFiltro(): ?TipoLancamento
    {
        $tipo = $this->filtros['tipo'] ?? null;

        return $tipo ? TipoLancamento::from($tipo) : null;
    }

    private function aplicarStatus(Builder $query): void
    {
        $status = $this->filtros['status'] ?? null;
        $hoje = now()->toDateString();
        $abertos = [StatusLancamento::Pendente, StatusLancamento::Parcial];

        match ($status) {
            'pago' => $query->where('status', StatusLancamento::Pago),
            'em_aberto' => $query->whereIn('status', $abertos),
            'vencido' => $query->vencidos(),
            'a_vencer' => $query->whereIn('status', $abertos)
                ->whereDate('vencimento', '>=', $hoje)
                ->when(
                    $this->filtros['a_vencer_dias'] ?? null,
                    fn (Builder $q, $dias) => $q->whereDate('vencimento', '<=', now()->addDays((int) $dias)->toDateString())
                ),
            default => null,
        };
    }

    private function campoData(): string
    {
        $campo = $this->filtros['data_campo'] ?? 'vencimento';

        return in_array($campo, self::CAMPOS_DATA_VALIDOS, true) ? $campo : 'vencimento';
    }

    /** @return array{0: ?Carbon, 1: ?Carbon} */
    public function periodo(): array
    {
        if (($this->filtros['modo_periodo'] ?? 'livre') === 'mensal') {
            $mes = $this->filtros['mes'] ?? null;
            $referencia = $mes ? Carbon::createFromFormat('Y-m', $mes) : now();

            return [$referencia->copy()->startOfMonth(), $referencia->copy()->endOfMonth()];
        }

        $de = $this->filtros['data_de'] ?? null;
        $ate = $this->filtros['data_ate'] ?? null;

        return [
            $de ? Carbon::parse($de)->startOfDay() : null,
            $ate ? Carbon::parse($ate)->endOfDay() : null,
        ];
    }

    private function aplicarPeriodo(Builder $query): void
    {
        [$inicio, $fim] = $this->periodo();
        $campo = $this->campoData();

        $query
            ->when($inicio, fn (Builder $q) => $q->whereDate($campo, '>=', $inicio->toDateString()))
            ->when($fim, fn (Builder $q) => $q->whereDate($campo, '<=', $fim->toDateString()));
    }

    private function aplicarCategoria(Builder $query): void
    {
        $planosDespesa = array_values(array_filter((array) ($this->filtros['planos_despesa'] ?? [])));
        $planosReceita = array_values(array_filter((array) ($this->filtros['planos_receita'] ?? [])));

        if ($planosDespesa === [] && $planosReceita === []) {
            return;
        }

        $query->where(function (Builder $q) use ($planosDespesa, $planosReceita): void {
            if ($planosDespesa !== []) {
                $q->orWhereIn('plano_despesa_id', $planosDespesa);
            }
            if ($planosReceita !== []) {
                $q->orWhereIn('plano_receita_id', $planosReceita);
            }
        });
    }

    private function aplicarFavorecido(Builder $query): void
    {
        $query
            ->when($this->filtros['favorecido_id'] ?? null, fn (Builder $q, $id) => $q->where('favorecido_id', $id))
            ->when($this->filtros['cliente_id'] ?? null, fn (Builder $q, $id) => $q->where('cliente_id', $id));
    }

    private function aplicarFormaPagamento(Builder $query): void
    {
        $formas = array_values(array_filter((array) ($this->filtros['formas_pagamento'] ?? [])));

        $query->when($formas !== [], fn (Builder $q) => $q->whereIn('forma_pagamento', $formas));
    }

    // ─────────────────────────────────────────────────────────────
    // Valor restante agregado no banco (sem N+1, sem somar Collection em PHP)
    // ─────────────────────────────────────────────────────────────

    /**
     * Envolve query() numa subquery com o valor restante já calculado
     * (valor - soma dos pagamentos), pra poder agregar (SUM/CASE) por cima
     * sem trazer os registros pro PHP.
     */
    private function comRestanteQuery(?TipoLancamento $tipo = null): QueryBuilder
    {
        $interno = $this->query($tipo)
            ->select('lancamentos.id', 'lancamentos.status', 'lancamentos.vencimento', 'lancamentos.valor', 'lancamentos.favorecido_id', 'lancamentos.cliente_id')
            ->selectSub(
                fn ($q) => $q->selectRaw('COALESCE(SUM(valor), 0)')
                    ->from('lancamento_pagamentos')
                    ->whereColumn('lancamento_id', 'lancamentos.id'),
                'valor_pago_sub'
            );

        return DB::query()->fromSub($interno, 't');
    }

    // ─────────────────────────────────────────────────────────────
    // Indicadores: totais em aberto / vencido
    // ─────────────────────────────────────────────────────────────

    /**
     * @return array{total_aberto: float, total_vencido: float, percentual_vencido: float, indice_inadimplencia: ?float}
     */
    public function totaisAbertoEVencido(TipoLancamento $tipo): array
    {
        $hoje = now()->toDateString();

        $linha = $this->comRestanteQuery($tipo)
            ->whereIn('status', [StatusLancamento::Pendente->value, StatusLancamento::Parcial->value])
            ->selectRaw('
                COALESCE(SUM(valor - valor_pago_sub), 0) as total_aberto,
                COALESCE(SUM(CASE WHEN vencimento < ? THEN valor - valor_pago_sub ELSE 0 END), 0) as total_vencido
            ', [$hoje])
            ->first();

        $totalAberto = (float) $linha->total_aberto;
        $totalVencido = (float) $linha->total_vencido;

        return [
            'total_aberto' => $totalAberto,
            'total_vencido' => $totalVencido,
            'percentual_vencido' => $totalAberto > 0 ? $totalVencido / $totalAberto * 100 : 0.0,
            'indice_inadimplencia' => $tipo === TipoLancamento::Receber
                ? ($totalAberto > 0 ? $totalVencido / $totalAberto * 100 : 0.0)
                : null,
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // Aging
    // ─────────────────────────────────────────────────────────────

    /** Faixas de atraso (dias vencidos), exclusivas: 1–15 / 16–30 / 31–60 / 61+. */
    public function agingVencidos(TipoLancamento $tipo): array
    {
        $hoje = now()->toDateString();

        $linha = $this->comRestanteQuery($tipo)
            ->whereIn('status', [StatusLancamento::Pendente->value, StatusLancamento::Parcial->value])
            ->whereRaw('vencimento < ?', [$hoje])
            ->selectRaw('
                COALESCE(SUM(CASE WHEN DATEDIFF(?, vencimento) BETWEEN 1 AND 15 THEN valor - valor_pago_sub ELSE 0 END), 0) as faixa_1_15,
                COALESCE(SUM(CASE WHEN DATEDIFF(?, vencimento) BETWEEN 16 AND 30 THEN valor - valor_pago_sub ELSE 0 END), 0) as faixa_16_30,
                COALESCE(SUM(CASE WHEN DATEDIFF(?, vencimento) BETWEEN 31 AND 60 THEN valor - valor_pago_sub ELSE 0 END), 0) as faixa_31_60,
                COALESCE(SUM(CASE WHEN DATEDIFF(?, vencimento) > 60 THEN valor - valor_pago_sub ELSE 0 END), 0) as faixa_61_mais
            ', [$hoje, $hoje, $hoje, $hoje])
            ->first();

        return [
            '1-15' => (float) $linha->faixa_1_15,
            '16-30' => (float) $linha->faixa_16_30,
            '31-60' => (float) $linha->faixa_31_60,
            '61+' => (float) $linha->faixa_61_mais,
        ];
    }

    /** Faixas "a vencer" (dias até o vencimento), cumulativas: próximos 7/15/30/60 dias. */
    public function agingAVencer(TipoLancamento $tipo): array
    {
        $hoje = now()->toDateString();
        $casos = collect(self::FAIXAS_A_VENCER)
            ->map(fn (int $dias) => "COALESCE(SUM(CASE WHEN DATEDIFF(vencimento, ?) <= {$dias} THEN valor - valor_pago_sub ELSE 0 END), 0) as faixa_{$dias}")
            ->implode(', ');

        $linha = $this->comRestanteQuery($tipo)
            ->whereIn('status', [StatusLancamento::Pendente->value, StatusLancamento::Parcial->value])
            ->whereRaw('vencimento >= ?', [$hoje])
            ->selectRaw($casos, array_fill(0, count(self::FAIXAS_A_VENCER), $hoje))
            ->first();

        return collect(self::FAIXAS_A_VENCER)
            ->mapWithKeys(fn (int $dias) => [(string) $dias => (float) $linha->{"faixa_{$dias}"}])
            ->all();
    }

    /**
     * (Receber − Pagar) em aberto por faixa de vencimento (faixas EXCLUSIVAS, diferente
     * de agingAVencer() que é cumulativa) — base para o fluxo de caixa projetado.
     *
     * @return array<string, float>
     */
    public function saldoProjetadoPorFaixa(): array
    {
        $receber = $this->totaisPorFaixaExclusiva(TipoLancamento::Receber);
        $pagar = $this->totaisPorFaixaExclusiva(TipoLancamento::Pagar);

        return collect(array_keys($receber))
            ->mapWithKeys(fn (string $faixa) => [$faixa => $receber[$faixa] - $pagar[$faixa]])
            ->all();
    }

    /** @return array<string, float> */
    private function totaisPorFaixaExclusiva(TipoLancamento $tipo): array
    {
        $hoje = now()->toDateString();

        $linha = $this->comRestanteQuery($tipo)
            ->whereIn('status', [StatusLancamento::Pendente->value, StatusLancamento::Parcial->value])
            ->selectRaw('
                COALESCE(SUM(CASE WHEN vencimento < ? THEN valor - valor_pago_sub ELSE 0 END), 0) as vencido,
                COALESCE(SUM(CASE WHEN vencimento >= ? AND DATEDIFF(vencimento, ?) <= 7 THEN valor - valor_pago_sub ELSE 0 END), 0) as ate_7,
                COALESCE(SUM(CASE WHEN vencimento >= ? AND DATEDIFF(vencimento, ?) BETWEEN 8 AND 15 THEN valor - valor_pago_sub ELSE 0 END), 0) as de_8_15,
                COALESCE(SUM(CASE WHEN vencimento >= ? AND DATEDIFF(vencimento, ?) BETWEEN 16 AND 30 THEN valor - valor_pago_sub ELSE 0 END), 0) as de_16_30,
                COALESCE(SUM(CASE WHEN vencimento >= ? AND DATEDIFF(vencimento, ?) BETWEEN 31 AND 60 THEN valor - valor_pago_sub ELSE 0 END), 0) as de_31_60,
                COALESCE(SUM(CASE WHEN vencimento >= ? AND DATEDIFF(vencimento, ?) > 60 THEN valor - valor_pago_sub ELSE 0 END), 0) as mais_60
            ', [$hoje, $hoje, $hoje, $hoje, $hoje, $hoje, $hoje, $hoje, $hoje, $hoje, $hoje])
            ->first();

        return [
            'vencido' => (float) $linha->vencido,
            'ate_7' => (float) $linha->ate_7,
            'de_8_15' => (float) $linha->de_8_15,
            'de_16_30' => (float) $linha->de_16_30,
            'de_31_60' => (float) $linha->de_31_60,
            'mais_60' => (float) $linha->mais_60,
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // Top favorecidos
    // ─────────────────────────────────────────────────────────────

    /** @return Collection<int, array{id: int, nome: string, saldo: float}> */
    public function topFavorecidos(TipoLancamento $tipo, int $limite = 10): Collection
    {
        $coluna = $tipo === TipoLancamento::Pagar ? 'favorecido_id' : 'cliente_id';

        $linhas = $this->comRestanteQuery($tipo)
            ->whereIn('status', [StatusLancamento::Pendente->value, StatusLancamento::Parcial->value])
            ->whereNotNull($coluna)
            ->selectRaw("{$coluna} as favorecido_ref, SUM(valor - valor_pago_sub) as saldo")
            ->groupBy('favorecido_ref')
            ->orderByDesc('saldo')
            ->limit($limite)
            ->get();

        $ids = $linhas->pluck('favorecido_ref')->all();
        $nomes = $tipo === TipoLancamento::Pagar
            ? Prestador::whereIn('id', $ids)->get()->keyBy('id')
            : Cliente::whereIn('id', $ids)->get()->keyBy('id');

        return $linhas->map(fn ($linha) => [
            'id' => (int) $linha->favorecido_ref,
            'nome' => $tipo === TipoLancamento::Pagar
                ? ($nomes[$linha->favorecido_ref]->nome_exibicao ?? '—')
                : ($nomes[$linha->favorecido_ref]->cliente_nome ?? '—'),
            'saldo' => (float) $linha->saldo,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // PMP / PMR / Ciclo financeiro
    // ─────────────────────────────────────────────────────────────

    /**
     * Média ponderada por valor de dias entre o lançamento do título (created_at,
     * proxy de "emissão" — não existe coluna de emissão explícita neste projeto) e o
     * pagamento efetivo (data_pagamento), sobre os títulos totalmente pagos no
     * conjunto filtrado.
     */
    public function prazoMedio(TipoLancamento $tipo): float
    {
        $linha = $this->query($tipo)
            ->where('status', StatusLancamento::Pago)
            ->whereNotNull('data_pagamento')
            ->selectRaw('SUM(DATEDIFF(data_pagamento, created_at) * valor) as soma_ponderada, SUM(valor) as soma_valor')
            ->first();

        $somaValor = (float) ($linha->soma_valor ?? 0);

        return $somaValor > 0 ? (float) $linha->soma_ponderada / $somaValor : 0.0;
    }

    public function pmp(): float
    {
        return $this->prazoMedio(TipoLancamento::Pagar);
    }

    public function pmr(): float
    {
        return $this->prazoMedio(TipoLancamento::Receber);
    }

    /** PMR − PMP: quantos dias a operação precisa financiar sozinha. */
    public function cicloFinanceiro(): float
    {
        return $this->pmr() - $this->pmp();
    }

    // ─────────────────────────────────────────────────────────────
    // Realizado x previsto no período
    // ─────────────────────────────────────────────────────────────

    /**
     * "Realizado" soma os PAGAMENTOS (lancamento_pagamentos.valor) com data dentro do
     * período — não o valor do título, que pode estar só parcialmente pago.
     * "Previsto" soma o valor do título cujo vencimento cai no período, com os
     * demais filtros (categoria/favorecido/forma) aplicados.
     *
     * @return array{realizado: float, previsto: float, percentual: float}
     */
    public function realizadoVsPrevisto(TipoLancamento $tipo): array
    {
        [$inicio, $fim] = $this->periodo();

        $realizado = (float) DB::table('lancamento_pagamentos')
            ->join('lancamentos', 'lancamentos.id', '=', 'lancamento_pagamentos.lancamento_id')
            ->where('lancamentos.tipo', $tipo->value)
            ->when($inicio, fn ($q) => $q->whereDate('lancamento_pagamentos.data_pagamento', '>=', $inicio->toDateString()))
            ->when($fim, fn ($q) => $q->whereDate('lancamento_pagamentos.data_pagamento', '<=', $fim->toDateString()))
            ->sum('lancamento_pagamentos.valor');

        $previsto = (float) $this->query($tipo)
            ->when($inicio, fn (Builder $q) => $q->whereDate('vencimento', '>=', $inicio->toDateString()))
            ->when($fim, fn (Builder $q) => $q->whereDate('vencimento', '<=', $fim->toDateString()))
            ->sum('valor');

        return [
            'realizado' => $realizado,
            'previsto' => $previsto,
            'percentual' => $previsto > 0 ? $realizado / $previsto * 100 : 0.0,
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // Listagem detalhada (tela + impressão)
    // ─────────────────────────────────────────────────────────────

    /**
     * Lançamentos do conjunto filtrado, agrupados pelo mesmo grupo lógico derivado
     * usado no resto do relatório (Pago / Vencido / A vencer — via status +
     * Lancamento::estaVencido). Eager load evita N+1 nas colunas de favorecido/conta.
     *
     * @return Collection<string, Collection<int, Lancamento>>
     */
    public function lancamentosDetalhados(): Collection
    {
        return $this->query()
            ->with(['favorecido', 'cliente', 'planoDespesa', 'planoReceita'])
            ->withSum('pagamentos', 'valor')
            ->orderBy('vencimento')
            ->get()
            ->groupBy(fn (Lancamento $l): string => match (true) {
                $l->status === StatusLancamento::Cancelado => 'Cancelado',
                $l->status === StatusLancamento::Pago => 'Pago',
                $l->estaVencido => 'Vencido',
                default => 'A vencer',
            });
    }

    // ─────────────────────────────────────────────────────────────
    // Comparação com período anterior
    // ─────────────────────────────────────────────────────────────

    /** Clona o Service com o período deslocado para o "anterior" equivalente. */
    public function periodoAnterior(): self
    {
        $filtros = $this->filtros;

        if (($filtros['modo_periodo'] ?? 'livre') === 'mensal') {
            $mes = $filtros['mes'] ?? now()->format('Y-m');
            $filtros['mes'] = Carbon::createFromFormat('Y-m', $mes)->subMonthNoOverflow()->format('Y-m');

            return new self($filtros);
        }

        [$inicio, $fim] = $this->periodo();

        if (! $inicio || ! $fim) {
            return new self($filtros);
        }

        $dias = $inicio->diffInDays($fim) + 1;
        $filtros['data_ate'] = $inicio->copy()->subDay()->toDateString();
        $filtros['data_de'] = $inicio->copy()->subDays($dias)->toDateString();

        return new self($filtros);
    }

    /** Variação percentual de $atual sobre $anterior. Null quando não dá pra calcular (base zero). */
    public static function variacao(float $atual, float $anterior): ?float
    {
        if ($anterior == 0.0) {
            return null;
        }

        return ($atual - $anterior) / abs($anterior) * 100;
    }
}
