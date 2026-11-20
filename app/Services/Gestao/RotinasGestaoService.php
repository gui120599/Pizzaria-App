<?php

namespace App\Services\Gestao;

use App\Enums\CompraStatusEnum;
use App\Enums\StatusLancamento;
use App\Enums\StonePedidoStatus;
use App\Filament\Pages\PagamentosAConfirmar;
use App\Models\CompraItem;
use App\Models\CompraPendencia;
use App\Models\Lancamento;
use App\Models\MovimentacaoProduto;
use App\Models\Produto;
use App\Models\StonePedido;
use App\Models\Venda;
use App\Services\RelatorioExcecoesPedidosService;
use App\Services\RelatorioFechamentoCaixaService;
use App\Support\IndicadoresVendas;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * Pacotes de dados das rotinas de gestão do Claude (G2): resumo do dia,
 * sugestão de compras e monitor de anomalias. Cada seção usa o mesmo serviço
 * da tela correspondente; quem chama decide quais seções o usuário pode ver.
 */
class RotinasGestaoService
{
    /** @return array<string, mixed> */
    public function vendasDoDia(Carbon $dia): array
    {
        $indicadores = new IndicadoresVendas;
        $semanaPassada = $dia->copy()->subWeek();

        $atual = $this->arredondar($indicadores->indicadores($dia->copy()->startOfDay(), $dia->copy()->endOfDay()));
        $anterior = $this->arredondar($indicadores->indicadores($semanaPassada->copy()->startOfDay(), $semanaPassada->copy()->endOfDay()));

        return [
            'indicadores' => $atual,
            'mesmo_dia_semana_passada' => ['data' => $semanaPassada->toDateString(), 'indicadores' => $anterior],
            'variacao_faturamento_pct' => $anterior['faturamento'] > 0 ? round(($atual['faturamento'] - $anterior['faturamento']) / $anterior['faturamento'] * 100, 1) : null,
            'top_produtos' => $indicadores->rankingProdutos($dia->copy()->startOfDay(), $dia->copy()->endOfDay(), 5)->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function caixaDoDia(Carbon $dia): array
    {
        $relatorio = $this->fechamento($dia);

        return [
            'resumo' => Arr::only($relatorio->resumo(), ['recebido', 'fiado', 'fiado_recebido', 'troco', 'volume_maquininha', 'mdr', 'mdr_efetivo']),
            'sessoes' => $relatorio->conferencia()->map(fn (array $linha): array => Arr::only($linha, ['caixa', 'operador', 'status', 'esperado', 'apurado', 'diferenca']))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function excecoesDoDia(Carbon $dia): array
    {
        return (new RelatorioExcecoesPedidosService($dia->copy()->startOfDay(), $dia->copy()->endOfDay()))->resumo();
    }

    /** @return array<string, int> */
    public function fiscalDoDia(Carbon $dia): array
    {
        $doDia = fn () => Venda::query()->whereBetween('venda_datahora_finalizada', [$dia->copy()->startOfDay(), $dia->copy()->endOfDay()]);

        return [
            'autorizadas' => $doDia()->situacaoNfe('autorizada')->count(),
            'com_falha' => $doDia()->situacaoNfe('falha')->count(),
            'pendentes_de_envio' => $doDia()->situacaoNfe('pendente')->count(),
        ];
    }

    public function stoneTravadas(): int
    {
        return StonePedido::query()
            ->where('created_at', '>=', now()->subDays(7))
            ->where(fn ($q) => $q
                ->whereIn('stp_status', [StonePedidoStatus::Falha, StonePedidoStatus::Indeterminado])
                ->orWhere(fn ($a) => $a->where('stp_status', StonePedidoStatus::Aguardando)->where('created_at', '<', now()->subMinutes(30))))
            ->count();
    }

    /** @return array<string, int|float> */
    public function comprasEmAberto(): array
    {
        $pagamentos = PagamentosAConfirmar::consulta()->with('pagamentos')->get();

        return [
            'pendencias_abertas' => CompraPendencia::query()->emAberto()->count(),
            'pagamentos_a_confirmar' => $pagamentos->count(),
            'valor_a_confirmar' => round($pagamentos->sum(fn (Lancamento $t): float => (float) $t->valorRestante), 2),
        ];
    }

    /** @return array<string, int|float> */
    public function contasProximas(): array
    {
        $abertos = fn () => Lancamento::query()->pagar()->whereIn('status', [StatusLancamento::Pendente, StatusLancamento::Parcial])->with('pagamentos');

        $vencidos = $abertos()->whereDate('vencimento', '<', today())->get();
        $hojeAmanha = $abertos()->whereBetween('vencimento', [today()->toDateString(), today()->addDay()->toDateString()])->get();

        return [
            'a_pagar_vencidos' => $vencidos->count(),
            'valor_vencido' => round($vencidos->sum(fn (Lancamento $t): float => (float) $t->valorRestante), 2),
            'a_pagar_hoje_e_amanha' => $hojeAmanha->count(),
            'valor_hoje_e_amanha' => round($hojeAmanha->sum(fn (Lancamento $t): float => (float) $t->valorRestante), 2),
        ];
    }

    /**
     * Quanto comprar de cada insumo para cobrir N dias, pelo consumo médio
     * diário (saídas de estoque) da janela, agrupado pelo fornecedor da
     * última compra confirmada — com a quantidade na unidade de compra e o
     * custo estimado pelo último custo de aquisição.
     *
     * @return array<string, mixed>
     */
    public function sugestaoCompras(int $coberturaDias, int $janelaDias): array
    {
        $consumos = MovimentacaoProduto::query()
            ->where('mov_tipo', 'SAIDA')
            ->whereIn('mov_origem', (array) config('gestao.sugestao_compras.origens_consumo'))
            ->where('mov_data', '>=', now()->subDays($janelaDias)->startOfDay())
            ->groupBy('mov_produto_id')
            ->selectRaw('mov_produto_id, SUM(mov_quantidade) as consumo')
            ->pluck('consumo', 'mov_produto_id');

        $produtos = Produto::query()
            ->whereIn('id', $consumos->keys())
            ->where('produto_controla_estoque', true)
            ->get();

        $itens = $produtos->map(function (Produto $produto) use ($consumos, $coberturaDias, $janelaDias): ?array {
            $consumoDiario = (float) $consumos[$produto->id] / max(1, $janelaDias);
            $saldo = (float) $produto->produto_saldo_estoque;
            $necessario = $consumoDiario * $coberturaDias - $saldo;

            if ($consumoDiario <= 0 || $necessario <= 0) {
                return null;
            }

            $ultima = $this->ultimaCompra($produto->id);
            $fator = (float) ($ultima?->ci_fator_conversao ?: 1);
            $custoEstoque = (float) ($ultima?->ci_custo_unitario_aquisicao ?: ($produto->produto_custo_medio ?: $produto->produto_preco_custo));

            return [
                'fornecedor_id' => $ultima?->compra?->compra_prestador_id,
                'fornecedor' => $ultima?->compra?->prestador?->nome_exibicao ?? 'Sem compra anterior',
                'produto_id' => $produto->id,
                'produto' => $produto->produto_descricao,
                'unidade_estoque' => $produto->produto_unidade_estoque,
                'saldo' => round($saldo, 3),
                'consumo_diario' => round($consumoDiario, 3),
                'dias_restantes' => round($saldo / $consumoDiario, 1),
                'comprar_em_estoque' => round($necessario, 3),
                'comprar' => $ultima !== null ? ceil($necessario / $fator) : null,
                'unidade_compra' => $ultima?->ci_unidade_compra,
                'fator_conversao' => $fator,
                'custo_estimado' => round($necessario * $custoEstoque, 2),
                'ultima_compra' => $ultima?->compra?->compra_data_entrada?->toDateString(),
            ];
        })->filter()->sortBy('dias_restantes')->values();

        return [
            'cobertura_dias' => $coberturaDias,
            'janela_consumo_dias' => $janelaDias,
            'total_estimado' => round($itens->sum('custo_estimado'), 2),
            'por_fornecedor' => $itens->groupBy('fornecedor')->map(fn (Collection $grupo, string $fornecedor): array => [
                'fornecedor' => $fornecedor,
                'fornecedor_id' => $grupo->first()['fornecedor_id'],
                'total_estimado' => round($grupo->sum('custo_estimado'), 2),
                'itens' => $grupo->map(fn (array $item): array => Arr::except($item, ['fornecedor', 'fornecedor_id']))->values()->all(),
            ])->sortByDesc('total_estimado')->values()->all(),
        ];
    }

    /**
     * Exceções do dia comparadas com a média do mesmo dia da semana nas
     * semanas anteriores.
     *
     * @return list<array{codigo: string, severidade: string, mensagem: string, dados: array<string, mixed>}>
     */
    public function anomaliasDeExcecoes(Carbon $dia): array
    {
        $semanas = (int) config('gestao.anomalias.semanas_base', 4);
        $fator = (float) config('gestao.anomalias.fator', 2.0);
        $valores = fn (Carbon $d): array => (fn (array $r): array => [
            'cancelamentos' => (float) $r['cancelados']['valor'],
            'cortesias' => (float) $r['cortesias']['valor'],
            'descontos' => (float) $r['descontos']['valor'],
        ])($this->excecoesDoDia($d));

        $atual = $valores($dia);
        $base = collect(range(1, $semanas))->map(fn (int $n): array => $valores($dia->copy()->subWeeks($n)));
        $alertas = [];

        foreach ($atual as $chave => $valor) {
            $media = round((float) $base->avg($chave), 2);
            $minimo = (float) config("gestao.anomalias.minimos.{$chave}", 0);

            if ($valor > max($media * $fator, $minimo)) {
                $alertas[] = $this->alerta("{$chave}_acima_do_normal", $media > 0 && $valor > $media * 3 ? 'alta' : 'media',
                    ucfirst($chave).' de R$ '.number_format($valor, 2, ',', '.').' — a média dos últimos '.$semanas.' '.$dia->locale('pt_BR')->dayName.'s foi R$ '.number_format($media, 2, ',', '.').'.',
                    ['valor' => $valor, 'media' => $media]);
            }
        }

        return $alertas;
    }

    /** @return list<array{codigo: string, severidade: string, mensagem: string, dados: array<string, mixed>}> */
    public function anomaliasDeCaixa(Carbon $dia): array
    {
        $limite = (float) config('gestao.anomalias.diferenca_caixa', 20);

        return $this->fechamento($dia)->conferencia()
            ->filter(fn (array $linha): bool => $linha['diferenca'] !== null && abs((float) $linha['diferenca']) > $limite)
            ->map(fn (array $linha): array => $this->alerta('diferenca_de_caixa', abs((float) $linha['diferenca']) > $limite * 5 ? 'alta' : 'media',
                "{$linha['caixa']} ({$linha['operador']}): ".((float) $linha['diferenca'] < 0 ? 'falta' : 'sobra').' de R$ '.number_format(abs((float) $linha['diferenca']), 2, ',', '.').' no fechamento.',
                Arr::only($linha, ['sessao_id', 'esperado', 'apurado', 'diferenca'])))
            ->values()
            ->all();
    }

    /** @return list<array{codigo: string, severidade: string, mensagem: string, dados: array<string, mixed>}> */
    public function anomaliasDeEstoque(): array
    {
        $negativos = Produto::query()->where('produto_controla_estoque', true)->where('produto_saldo_estoque', '<', 0)->orderBy('produto_saldo_estoque')->limit(15)->get();

        return $negativos->isEmpty() ? [] : [$this->alerta('estoque_negativo', 'media',
            $negativos->count().' produto(s) com saldo negativo — falta lançar compra ou a ficha técnica está errada.',
            ['produtos' => $negativos->map(fn (Produto $p): array => ['produto' => $p->produto_descricao, 'saldo' => round((float) $p->produto_saldo_estoque, 3)])->all()])];
    }

    /** @return array{codigo: string, severidade: string, mensagem: string, dados: array<string, mixed>} */
    public function alerta(string $codigo, string $severidade, string $mensagem, array $dados = []): array
    {
        return ['codigo' => $codigo, 'severidade' => $severidade, 'mensagem' => $mensagem, 'dados' => $dados];
    }

    private function ultimaCompra(int $produtoId): ?CompraItem
    {
        return CompraItem::query()
            ->with('compra.prestador')
            ->where('ci_produto_id', $produtoId)
            ->whereHas('compra', fn ($q) => $q->where('compra_status', CompraStatusEnum::CONFIRMADA))
            ->join('compras', 'compras.id', '=', 'compra_itens.ci_compra_id')
            ->orderByDesc('compras.compra_data_entrada')
            ->orderByDesc('compra_itens.id')
            ->select('compra_itens.*')
            ->first();
    }

    private function fechamento(Carbon $dia): RelatorioFechamentoCaixaService
    {
        return new RelatorioFechamentoCaixaService(['inicio' => $dia->toDateString(), 'fim' => $dia->toDateString()]);
    }

    /**
     * @param  array<string, float|int>  $indicadores
     * @return array<string, float|int>
     */
    private function arredondar(array $indicadores): array
    {
        return collect($indicadores)->map(fn (float|int $v): float|int => is_float($v) ? round($v, 2) : $v)->all();
    }
}
