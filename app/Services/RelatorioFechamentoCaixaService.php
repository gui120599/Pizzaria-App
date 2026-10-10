<?php

namespace App\Services;

use App\Enums\FormaPagamento;
use App\Enums\MotivoSaidaCaixa;
use App\Enums\TipoPagamentoMaquininhaEnum;
use App\Models\CartoesPagamento;
use App\Models\Empresa;
use App\Models\LancamentoPagamento;
use App\Models\Maquininha;
use App\Models\NfEmissao;
use App\Models\PagamentosVenda;
use App\Models\SessaoCaixa;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Relatório de Fechamento de Caixa: DRE das vendas, fluxo de caixa por forma
 * de pagamento, o que passou em cada maquininha por bandeira com a taxa (MDR)
 * abatida, previsão de recebimento (D+N), movimentações e conferência.
 *
 * Recorte: vendas FINALIZADAS no período (por venda_datahora_finalizada),
 * opcionalmente de um caixa; com sessões selecionadas, valem só as vendas
 * delas e o período é ignorado. Fluxo, movimentações e conferência são por
 * sessão: as selecionadas ou as abertas no período.
 *
 * Imposto da NFC-e: só nas vendas com nota autorizada, pelo percentual
 * gravado na venda quando a nota foi autorizada (sem ele, o atual da empresa).
 *
 * Taxa da maquininha: o retrato gravado no pagamento; sem retrato, a taxa
 * atual (TaxaMaquininhaService); sem taxa cadastrada, entra como 0 e conta
 * em "sem taxa". O prazo D+N é sempre o da parametrização atual.
 *
 * Filtros (mesmo shape da página RelatorioFechamentoCaixa): inicio, fim,
 * caixa_id, sessoes.
 */
class RelatorioFechamentoCaixaService
{
    /** @var array<string, string> */
    public const CATEGORIAS = [
        'dinheiro' => 'Dinheiro',
        'debito' => 'Débito',
        'credito' => 'Crédito',
        'pix' => 'Pix (maquininhas)',
        'pix_cnpj' => 'PIX CNPJ',
        'outros' => 'Outros',
    ];

    /** @var array<string, mixed> */
    private array $cache = [];

    /**
     * @param  array{inicio?: ?string, fim?: ?string, caixa_id?: int|string|null, sessoes?: array<int, int|string>|null}  $filtros
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

    /** @return array<int, int> */
    public function sessoesSelecionadas(): array
    {
        return array_values(array_map('intval', array_filter((array) ($this->filtros['sessoes'] ?? []))));
    }

    public function filtrandoPorSessao(): bool
    {
        return $this->sessoesSelecionadas() !== [];
    }

    private function caixaId(): ?int
    {
        return ! empty($this->filtros['caixa_id']) ? (int) $this->filtros['caixa_id'] : null;
    }

    /**
     * Vendas do recorte. Canceladas não têm finalização: no modo período usam
     * a data de início (mesmo critério do FinanceiroStatsOverview).
     */
    private function vendasQuery(string $status = 'FINALIZADA'): Builder
    {
        $query = DB::table('vendas')->where('vendas.venda_status', $status);

        if ($this->filtrandoPorSessao()) {
            return $query->whereIn('vendas.venda_sessao_caixa_id', $this->sessoesSelecionadas());
        }

        $coluna = $status === 'CANCELADA' ? 'vendas.venda_datahora_iniciada' : 'vendas.venda_datahora_finalizada';

        return $query
            ->whereBetween($coluna, $this->periodo())
            ->when($this->caixaId(), fn (Builder $query, int $caixaId) => $query->whereIn(
                'vendas.venda_sessao_caixa_id',
                fn (Builder $sub) => $sub->select('id')->from('sessao_caixas')->where('sessaocaixa_caixa_id', $caixaId),
            ));
    }

    /**
     * Sessões do recorte, com o que a conferência precisa já carregado.
     *
     * @return Collection<int, SessaoCaixa>
     */
    public function sessoes(): Collection
    {
        return $this->cache['sessoes'] ??= SessaoCaixa::query()
            ->with(['caixa' => fn ($query) => $query->withTrashed(), 'user', 'maquininhas', 'fechamentoCaixa.maquininhas'])
            ->when(
                $this->filtrandoPorSessao(),
                fn ($query) => $query->whereIn('id', $this->sessoesSelecionadas()),
                fn ($query) => $query
                    ->whereBetween('sessaocaixa_data_hora_abertura', $this->periodo())
                    ->when($this->caixaId(), fn ($query, int $caixaId) => $query->where('sessaocaixa_caixa_id', $caixaId)),
            )
            ->orderBy('sessaocaixa_data_hora_abertura')
            ->get()
            ->each(function (SessaoCaixa $sessao): void {
                $sessao->fechamentoCaixa?->setRelation('sessaoCaixa', $sessao);
            });
    }

    // ── Pagamentos ───────────────────────────────────────────────────────────

    /**
     * Um item por pagamento das vendas do recorte e por recebimento de fiado
     * que entrou no caixa (origem "fiado").
     *
     * @return Collection<int, array{id: int, origem: string, venda_id: ?int, sessao_id: ?int, finalizada_em: Carbon, opcao_id: ?int, forma: string, categoria: string, tipo: ?TipoPagamentoMaquininhaEnum, pix_cnpj: bool, maquininha_id: ?int, maquininha: string, operadora: string, bandeira: string, valor: float, taxa_percentual: ?float, taxa: float, sem_taxa: bool, prazo_dias: ?int}>
     */
    public function pagamentos(): Collection
    {
        return $this->cache['pagamentos'] ??= $this->carregarPagamentos($this->vendasQuery())
            ->concat($this->carregarRecebimentos($this->recebimentosQuery()))
            ->values();
    }

    /**
     * Pagamentos das vendas FINALIZADAS e recebimentos de fiado das sessões do
     * recorte (base do fluxo e da conferência). Com sessões selecionadas é o
     * mesmo conjunto de pagamentos().
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function pagamentosDasSessoes(): Collection
    {
        if ($this->filtrandoPorSessao()) {
            return $this->pagamentos();
        }

        $ids = $this->sessoes()->modelKeys();

        return $this->cache['pagamentos_sessoes'] ??= $this->carregarPagamentos(
            DB::table('vendas')
                ->where('vendas.venda_status', 'FINALIZADA')
                ->whereIn('vendas.venda_sessao_caixa_id', $ids),
        )->concat($this->carregarRecebimentos(
            $this->recebimentosBaseQuery()->whereIn('lancamento_pagamentos.sessao_caixa_id', $ids),
        ))->values();
    }

    /**
     * Recebimentos de título a receber que entram no caixa (dinheiro, cartão,
     * Pix, PIX CNPJ). Por sessão quando há sessões selecionadas; senão pela
     * data do recebimento — com filtro de caixa, só os feitos nas sessões dele.
     */
    private function recebimentosQuery(): Builder
    {
        $query = $this->recebimentosBaseQuery();

        if ($this->filtrandoPorSessao()) {
            return $query->whereIn('lancamento_pagamentos.sessao_caixa_id', $this->sessoesSelecionadas());
        }

        [$inicio, $fim] = $this->periodo();

        return $query
            ->whereBetween('lancamento_pagamentos.data_pagamento', [$inicio->toDateString(), $fim->toDateString()])
            ->when($this->caixaId(), fn (Builder $query, int $caixaId) => $query->whereIn(
                'lancamento_pagamentos.sessao_caixa_id',
                fn (Builder $sub) => $sub->select('id')->from('sessao_caixas')->where('sessaocaixa_caixa_id', $caixaId),
            ));
    }

    private function recebimentosBaseQuery(): Builder
    {
        $formasDoCaixa = collect(FormaPagamento::cases())
            ->filter(fn (FormaPagamento $forma): bool => $forma->entraNoCaixa())
            ->map(fn (FormaPagamento $forma): string => $forma->value)
            ->values()
            ->all();

        return DB::table('lancamento_pagamentos')
            ->join('lancamentos', 'lancamentos.id', '=', 'lancamento_pagamentos.lancamento_id')
            ->where('lancamentos.tipo', 'receber')
            ->whereIn('lancamento_pagamentos.forma_pagamento', $formasDoCaixa);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function carregarPagamentos(Builder $vendasQuery): Collection
    {
        $vendas = (clone $vendasQuery)
            ->get(['vendas.id', 'vendas.venda_sessao_caixa_id', 'vendas.venda_datahora_finalizada'])
            ->keyBy('id');

        if ($vendas->isEmpty()) {
            return collect();
        }

        $taxas = app(TaxaMaquininhaService::class);
        $maquininhas = $this->maquininhasPorId();

        return PagamentosVenda::query()
            ->with([
                'opcaoPagamento' => fn ($query) => $query->withTrashed(),
                'cartao' => fn ($query) => $query->withTrashed(),
            ])
            ->whereIn('pg_venda_venda_id', (clone $vendasQuery)->select('vendas.id'))
            ->orderBy('id')
            ->get()
            ->map(function (PagamentosVenda $pagamento) use ($vendas, $taxas, $maquininhas): array {
                $venda = $vendas[$pagamento->pg_venda_venda_id];
                $opcao = $pagamento->opcaoPagamento;
                $pixCnpj = (bool) $opcao?->ehPixCnpj();
                $tipo = $opcao?->tipoMaquininha();
                $maquininhaId = $tipo ? $taxas->resolverMaquininhaId($pagamento) : null;

                [$percentual, $taxa] = $pagamento->pg_venda_taxa_maquininha_percentual !== null
                    ? [(float) $pagamento->pg_venda_taxa_maquininha_percentual, (float) $pagamento->pg_venda_taxa_maquininha_valor]
                    : self::taxaAtual($taxas->calcular($pagamento));

                return [
                    'id' => $pagamento->id,
                    'origem' => 'venda',
                    'venda_id' => (int) $pagamento->pg_venda_venda_id,
                    'sessao_id' => $venda->venda_sessao_caixa_id ? (int) $venda->venda_sessao_caixa_id : null,
                    'finalizada_em' => Carbon::parse($venda->venda_datahora_finalizada),
                    'opcao_id' => $opcao?->id,
                    'forma' => (string) ($opcao?->opcaopag_nome ?? 'Forma #'.$pagamento->pg_venda_opcaopagamento_id),
                    'categoria' => $pixCnpj ? 'pix_cnpj' : self::categoriaDaVenda($opcao?->opcaopag_desc_nfe),
                    'tipo' => $tipo,
                    'pix_cnpj' => $pixCnpj,
                    'maquininha_id' => $maquininhaId,
                    'maquininha' => self::nomeMaquininha($maquininhas->get($maquininhaId), padraoUsada: $tipo && ! $pagamento->pg_venda_maquininha_id),
                    'operadora' => self::nomeOperadora($maquininhas->get($maquininhaId)),
                    'bandeira' => self::nomeBandeira($pagamento->cartao, $tipo),
                    'valor' => (float) $pagamento->pg_venda_valor_pagamento,
                    'taxa_percentual' => $percentual,
                    'taxa' => $taxa,
                    'sem_taxa' => $tipo !== null && $percentual === null,
                    'prazo_dias' => $tipo ? $taxas->taxa($maquininhaId, $pagamento->pg_venda_cartao_id, $tipo)?->mt_prazo_recebimento_dias : null,
                ];
            });
    }

    /**
     * Recebimentos de fiado no mesmo formato de carregarPagamentos().
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function carregarRecebimentos(Builder $recebimentosQuery): Collection
    {
        $taxas = app(TaxaMaquininhaService::class);
        $maquininhas = $this->maquininhasPorId();

        return LancamentoPagamento::query()
            ->with(['cartao' => fn ($query) => $query->withTrashed(), 'lancamento'])
            ->whereIn('id', (clone $recebimentosQuery)->select('lancamento_pagamentos.id'))
            ->orderBy('id')
            ->get()
            ->map(function (LancamentoPagamento $recebimento) use ($taxas, $maquininhas): array {
                $forma = $recebimento->forma_pagamento;
                $tipo = $forma?->tipoMaquininha();
                $maquininhaId = $tipo ? $taxas->resolverMaquininhaIdRecebimento($recebimento) : null;

                [$percentual, $taxa] = $recebimento->taxa_percentual !== null
                    ? [(float) $recebimento->taxa_percentual, (float) $recebimento->taxa_valor]
                    : self::taxaAtual($taxas->calcularRecebimento($recebimento));

                return [
                    'id' => $recebimento->id,
                    'origem' => 'fiado',
                    'venda_id' => $recebimento->lancamento?->venda_id,
                    'sessao_id' => $recebimento->sessao_caixa_id ? (int) $recebimento->sessao_caixa_id : null,
                    'finalizada_em' => Carbon::parse($recebimento->data_pagamento),
                    'opcao_id' => null,
                    'forma' => 'Fiado recebido — '.($forma === FormaPagamento::Pix ? 'Pix (maquininha)' : $forma?->getLabel()),
                    'categoria' => self::categoriaDoFinanceiro($forma?->value),
                    'tipo' => $tipo,
                    'pix_cnpj' => $forma === FormaPagamento::PixCnpj,
                    'maquininha_id' => $maquininhaId,
                    'maquininha' => self::nomeMaquininha($maquininhas->get($maquininhaId), padraoUsada: $tipo && ! $recebimento->maquininha_id),
                    'operadora' => self::nomeOperadora($maquininhas->get($maquininhaId)),
                    'bandeira' => self::nomeBandeira($recebimento->cartao, $tipo),
                    'valor' => (float) $recebimento->valor,
                    'taxa_percentual' => $percentual,
                    'taxa' => $taxa,
                    'sem_taxa' => $tipo !== null && $percentual === null,
                    'prazo_dias' => $tipo ? $taxas->taxa($maquininhaId, $recebimento->cartao_id, $tipo)?->mt_prazo_recebimento_dias : null,
                ];
            });
    }

    /**
     * Sem retrato: taxa atual; sem taxa cadastrada, percentual nulo e valor 0.
     *
     * @param  array{percentual: ?float, valor: ?float}  $atual
     * @return array{0: ?float, 1: float}
     */
    private static function taxaAtual(array $atual): array
    {
        return [$atual['percentual'], (float) ($atual['valor'] ?? 0)];
    }

    /** @return Collection<int, Maquininha> */
    private function maquininhasPorId(): Collection
    {
        return $this->cache['maquininhas'] ??= Maquininha::withTrashed()->get()->keyBy('id');
    }

    private static function categoriaDaVenda(?string $descNfe): string
    {
        return match ($descNfe) {
            'cash' => 'dinheiro',
            'debitCard' => 'debito',
            'creditCard' => 'credito',
            'InstantPayment' => 'pix',
            default => 'outros',
        };
    }

    /** Mesmo mapeamento do FechamentoCaixaService para App\Enums\FormaPagamento. */
    private static function categoriaDoFinanceiro(?string $forma): string
    {
        return match ($forma) {
            'dinheiro' => 'dinheiro',
            'cartao_debito' => 'debito',
            'cartao_credito' => 'credito',
            'pix' => 'pix',
            'pix_cnpj' => 'pix_cnpj',
            default => 'outros',
        };
    }

    private static function nomeMaquininha(?Maquininha $maquininha, bool $padraoUsada): string
    {
        if (! $maquininha) {
            return 'Não informada';
        }

        return $padraoUsada ? "{$maquininha->nome} (padrão)" : $maquininha->nome;
    }

    private static function nomeOperadora(?Maquininha $maquininha): string
    {
        return $maquininha?->operadora?->getLabel() ?? 'Não informada';
    }

    private static function nomeBandeira(?CartoesPagamento $cartao, ?TipoPagamentoMaquininhaEnum $tipo): string
    {
        if ($tipo === TipoPagamentoMaquininhaEnum::Pix) {
            return 'Pix';
        }

        $bandeira = (string) $cartao?->getRawOriginal('cartao_bandeira');

        if ($bandeira === '') {
            return 'Não informada';
        }

        // A coluna já foi enum minúsculo e hoje guarda o valor de BANDEIRAS:
        // compara sem caixa para achar o rótulo nos dois casos.
        $rotulo = collect(CartoesPagamento::BANDEIRAS)->search(fn (string $valor): bool => strcasecmp($valor, $bandeira) === 0);

        return $rotulo !== false ? $rotulo : ucfirst($bandeira);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function pagamentosMaquininha(): Collection
    {
        return $this->pagamentos()->filter(fn (array $pagamento): bool => $pagamento['tipo'] !== null);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function pagamentosPixCnpj(): Collection
    {
        return $this->pagamentos()->filter(fn (array $pagamento): bool => $pagamento['pix_cnpj']);
    }

    // ── Resumo e DRE ─────────────────────────────────────────────────────────

    /**
     * @return array{vendas: int, produtos: float, descontos: float, frete: float, taxa_servico: float, faturamento: float, receita_operacional: float, ticket_medio: float, recebido: float, recebido_vendas: float, fiado_recebido: float, fiado: float, troco: float, volume_maquininha: float, volume_pix_cnpj: float, tarifa_pix_cnpj: float, mdr: float, mdr_efetivo: float, participacao_maquininha: float, sem_taxa: int, vendas_nfe: int, faturamento_nfe: float, faturamento_sem_nfe: float, cobertura_nfe: float, imposto_nfe: float, imposto_efetivo: float, cmv: float, cmv_percentual: float, cobertura_custo: float, margem_contribuicao: float, margem_percentual: float, canceladas: int, valor_cancelado: float, taxa_cancelamento: float}
     */
    public function resumo(): array
    {
        return $this->cache['resumo'] ??= $this->montarResumo();
    }

    /** @return array<string, int|float> */
    private function montarResumo(): array
    {
        $vendas = $this->vendasQuery()
            ->selectRaw('COUNT(*) AS qtd, SUM(venda_valor_itens) AS produtos, SUM(venda_valor_desconto) AS descontos,
                SUM(venda_valor_frete) AS frete, SUM(venda_valor_taxa_servico) AS taxa_servico,
                SUM(venda_valor_total) AS faturamento, SUM(venda_valor_troco) AS troco')
            ->first();

        $nfe = $this->vendasQuery()
            ->where('vendas.venda_status_nfe', NfEmissao::STATUS_AUTORIZADA)
            ->selectRaw('COUNT(*) AS qtd, SUM(venda_valor_total) AS total,
                SUM(venda_valor_total * COALESCE(venda_imposto_nfe_percentual, ?) / 100) AS imposto', [$this->percentualImpostoAtual()])
            ->first();

        $canceladas = $this->vendasQuery('CANCELADA')
            ->selectRaw('COUNT(*) AS qtd, SUM(venda_valor_total) AS total')
            ->first();

        $fiado = (float) DB::table('lancamentos')
            ->where('tipo', 'receber')
            ->whereIn('venda_id', $this->vendasQuery()->select('vendas.id'))
            ->sum('valor');

        $qtd = (int) $vendas->qtd;
        $faturamento = round((float) $vendas->faturamento, 2);
        $taxaServico = round((float) $vendas->taxa_servico, 2);
        $receitaOperacional = round($faturamento - $taxaServico, 2);
        $maquininha = $this->pagamentosMaquininha();
        $volumeMaquininha = round($maquininha->sum('valor'), 2);
        $recebido = round($this->pagamentos()->sum('valor'), 2);
        $fiadoRecebido = round($this->pagamentos()->where('origem', 'fiado')->sum('valor'), 2);
        $mdr = round($maquininha->sum('taxa'), 2);
        $tarifaPixCnpj = round($this->pagamentosPixCnpj()->sum('taxa'), 2);
        $faturamentoNfe = round((float) $nfe->total, 2);
        $impostoNfe = round((float) $nfe->imposto, 2);
        $cmv = $this->cmv();
        $margem = round($receitaOperacional - $mdr - $tarifaPixCnpj - $impostoNfe - $cmv['cmv'], 2);
        $qtdCanceladas = (int) $canceladas->qtd;

        return [
            'vendas' => $qtd,
            'produtos' => round((float) $vendas->produtos, 2),
            'descontos' => round((float) $vendas->descontos, 2),
            'frete' => round((float) $vendas->frete, 2),
            'taxa_servico' => $taxaServico,
            'faturamento' => $faturamento,
            'receita_operacional' => $receitaOperacional,
            'ticket_medio' => $qtd > 0 ? round($faturamento / $qtd, 2) : 0.0,
            'recebido' => $recebido,
            'recebido_vendas' => round($recebido - $fiadoRecebido, 2),
            'fiado_recebido' => $fiadoRecebido,
            'fiado' => round($fiado, 2),
            'troco' => round((float) $vendas->troco, 2),
            'volume_maquininha' => $volumeMaquininha,
            'mdr' => $mdr,
            'volume_pix_cnpj' => round($this->pagamentosPixCnpj()->sum('valor'), 2),
            'tarifa_pix_cnpj' => $tarifaPixCnpj,
            'mdr_efetivo' => self::percentual($mdr, $volumeMaquininha),
            'participacao_maquininha' => self::percentual($volumeMaquininha, $recebido),
            'sem_taxa' => $maquininha->where('sem_taxa', true)->count(),
            'vendas_nfe' => (int) $nfe->qtd,
            'faturamento_nfe' => $faturamentoNfe,
            'faturamento_sem_nfe' => round($faturamento - $faturamentoNfe, 2),
            'cobertura_nfe' => self::percentual($faturamentoNfe, $faturamento),
            'imposto_nfe' => $impostoNfe,
            'imposto_efetivo' => self::percentual($impostoNfe, $faturamentoNfe),
            'cmv' => $cmv['cmv'],
            'cmv_percentual' => $cmv['percentual'],
            'cobertura_custo' => $cmv['cobertura'],
            'margem_contribuicao' => $margem,
            'margem_percentual' => self::percentual($margem, $receitaOperacional),
            'canceladas' => $qtdCanceladas,
            'valor_cancelado' => round((float) $canceladas->total, 2),
            'taxa_cancelamento' => self::percentual($qtdCanceladas, $qtd + $qtdCanceladas),
        ];
    }

    /**
     * Percentual de imposto da NFC-e da empresa, usado quando a venda não tem
     * o retrato (autorizada antes de existir o campo).
     */
    private function percentualImpostoAtual(): float
    {
        return $this->cache['imposto_atual'] ??= (float) (Empresa::query()->value('empresa_percentual_imposto_nfe') ?? 0);
    }

    /**
     * CMV pelo custo congelado nos itens (item_venda_custo_unitario).
     * Cobertura = parte da receita de produtos cujos itens têm custo — abaixo
     * de 100% o CMV está subestimado (produto sem ficha técnica/custo).
     *
     * @return array{cmv: float, receita_itens: float, percentual: float, cobertura: float}
     */
    public function cmv(): array
    {
        return $this->cache['cmv'] ??= (function (): array {
            $linha = DB::table('itens_vendas')
                ->whereIn('item_venda_venda_id', $this->vendasQuery()->select('vendas.id'))
                ->where('item_venda_status', 'INSERIDO')
                ->selectRaw('SUM(item_venda_quantidade * item_venda_custo_unitario) AS cmv, SUM(item_venda_valor) AS receita,
                    SUM(CASE WHEN item_venda_custo_unitario > 0 THEN item_venda_valor ELSE 0 END) AS receita_com_custo')
                ->first();

            $cmv = round((float) $linha->cmv, 2);
            $receita = round((float) $linha->receita, 2);

            return [
                'cmv' => $cmv,
                'receita_itens' => $receita,
                'percentual' => self::percentual($cmv, $receita),
                'cobertura' => self::percentual((float) $linha->receita_com_custo, $receita),
            ];
        })();
    }

    /**
     * Demonstrativo do resultado das vendas, em % do faturamento.
     *
     * @return Collection<int, array{key: string, descricao: string, valor: float, percentual: float, destaque: bool}>
     */
    public function dre(): Collection
    {
        $resumo = $this->resumo();
        $base = $resumo['faturamento'];

        $linhas = [
            ['produtos_bruto', 'Produtos vendidos (bruto)', $resumo['produtos'] + $resumo['descontos'], false],
            ['descontos', '(−) Descontos concedidos', -$resumo['descontos'], false],
            ['produtos', '= Produtos (líquido de descontos)', $resumo['produtos'], true],
            ['frete', '(+) Taxas de entrega', $resumo['frete'], false],
            ['taxa_servico', '(+) Taxa de serviço', $resumo['taxa_servico'], false],
            ['faturamento', '= Faturamento total', $resumo['faturamento'], true],
            ['repasse', '(−) Taxa de serviço (repasse aos garçons)', -$resumo['taxa_servico'], false],
            ['receita_operacional', '= Receita operacional', $resumo['receita_operacional'], true],
            ['imposto_nfe', '(−) Imposto da NFC-e (vendas com nota autorizada)', -$resumo['imposto_nfe'], false],
            ['mdr', '(−) Taxas das maquininhas (MDR)', -$resumo['mdr'], false],
            ['tarifa_pix_cnpj', '(−) Tarifa bancária do PIX CNPJ', -$resumo['tarifa_pix_cnpj'], false],
            ['cmv', '(−) CMV (custo das mercadorias vendidas)', -$resumo['cmv'], false],
            ['margem', '= Margem de contribuição', $resumo['margem_contribuicao'], true],
        ];

        return collect($linhas)->map(fn (array $linha): array => [
            'key' => $linha[0],
            'descricao' => $linha[1],
            'valor' => round($linha[2], 2),
            'percentual' => self::percentual($linha[2], $base),
            'destaque' => $linha[3],
        ]);
    }

    // ── Formas de pagamento e maquininhas ────────────────────────────────────

    /**
     * @return Collection<int, array{key: string, forma: string, categoria: string, transacoes: int, bruto: float, participacao: float, taxa: float, liquido: float}>
     */
    public function porFormaPagamento(): Collection
    {
        $total = $this->pagamentos()->sum('valor');

        return $this->pagamentos()
            ->groupBy(fn (array $pagamento): string => (string) ($pagamento['opcao_id'] ?? 0))
            ->map(fn (Collection $pagamentos, string $key): array => [
                'key' => $key,
                'forma' => $pagamentos->first()['forma'],
                'categoria' => self::CATEGORIAS[$pagamentos->first()['categoria']],
                'transacoes' => $pagamentos->count(),
                ...self::somas($pagamentos),
                'participacao' => self::percentual($pagamentos->sum('valor'), $total),
            ])
            ->sortByDesc('bruto')
            ->values();
    }

    /**
     * Uma linha por maquininha × tipo × bandeira.
     *
     * @return Collection<int, array{key: string, maquininha: string, tipo: string, bandeira: string, transacoes: int, bruto: float, ticket_medio: float, taxa_percentual: float, taxa: float, liquido: float, prazo: string, sem_taxa: int}>
     */
    public function maquininhas(): Collection
    {
        return $this->pagamentosMaquininha()
            ->groupBy(fn (array $pagamento): string => $pagamento['maquininha'].'|'.$pagamento['tipo']->value.'|'.$pagamento['bandeira'])
            ->map(function (Collection $pagamentos, string $key): array {
                $primeiro = $pagamentos->first();
                $prazos = $pagamentos->pluck('prazo_dias')->unique()->values();

                return [
                    'key' => $key,
                    'maquininha' => $primeiro['maquininha'],
                    'tipo' => $primeiro['tipo']->getLabel(),
                    'bandeira' => $primeiro['bandeira'],
                    'transacoes' => $pagamentos->count(),
                    ...self::somas($pagamentos),
                    'ticket_medio' => round($pagamentos->sum('valor') / $pagamentos->count(), 2),
                    'prazo' => $prazos->count() === 1 && $prazos->first() !== null ? 'D+'.$prazos->first() : ($prazos->filter(fn ($prazo) => $prazo !== null)->isEmpty() ? '—' : 'Vários'),
                    'sem_taxa' => $pagamentos->where('sem_taxa', true)->count(),
                ];
            })
            ->sortBy([['maquininha', 'asc'], ['tipo', 'asc'], ['bandeira', 'asc']])
            ->values();
    }

    /**
     * @return Collection<int, array{key: string, maquininha: string, transacoes: int, debito: float, credito: float, pix: float, bruto: float, taxa_percentual: float, taxa: float, liquido: float}>
     */
    public function porMaquininha(): Collection
    {
        return $this->pagamentosMaquininha()
            ->groupBy('maquininha')
            ->map(fn (Collection $pagamentos, string $maquininha): array => [
                'key' => $maquininha,
                'maquininha' => $maquininha,
                'transacoes' => $pagamentos->count(),
                ...self::porTipo($pagamentos),
                ...self::somas($pagamentos),
            ])
            ->sortByDesc('bruto')
            ->values();
    }

    /**
     * Recebimentos por marca (operadora) da maquininha — Stone, Cielo... —
     * somando as maquininhas de cada uma e separando venda de fiado recebido.
     *
     * @return Collection<int, array{key: string, operadora: string, maquininhas: string, transacoes: int, vendas: float, fiado: float, debito: float, credito: float, pix: float, bruto: float, participacao: float, taxa_percentual: float, taxa: float, liquido: float}>
     */
    public function porOperadora(): Collection
    {
        $total = $this->pagamentosMaquininha()->sum('valor');

        return $this->pagamentosMaquininha()
            ->groupBy('operadora')
            ->map(fn (Collection $pagamentos, string $operadora): array => [
                'key' => $operadora,
                'operadora' => $operadora,
                'maquininhas' => $pagamentos->pluck('maquininha')->unique()->sort()->implode(', '),
                'transacoes' => $pagamentos->count(),
                'vendas' => round($pagamentos->where('origem', 'venda')->sum('valor'), 2),
                'fiado' => round($pagamentos->where('origem', 'fiado')->sum('valor'), 2),
                ...self::porTipo($pagamentos),
                ...self::somas($pagamentos),
                'participacao' => self::percentual($pagamentos->sum('valor'), $total),
            ])
            ->sortByDesc('bruto')
            ->values();
    }

    /**
     * Consolidado por bandeira entre todas as maquininhas (Pix vira "bandeira" própria).
     *
     * @return Collection<int, array{key: string, bandeira: string, transacoes: int, debito: float, credito: float, pix: float, bruto: float, participacao: float, taxa_percentual: float, taxa: float, liquido: float}>
     */
    public function porBandeira(): Collection
    {
        $total = $this->pagamentosMaquininha()->sum('valor');

        return $this->pagamentosMaquininha()
            ->groupBy('bandeira')
            ->map(fn (Collection $pagamentos, string $bandeira): array => [
                'key' => $bandeira,
                'bandeira' => $bandeira,
                'transacoes' => $pagamentos->count(),
                ...self::porTipo($pagamentos),
                ...self::somas($pagamentos),
                'participacao' => self::percentual($pagamentos->sum('valor'), $total),
            ])
            ->sortByDesc('bruto')
            ->values();
    }

    /**
     * Quando o líquido das maquininhas cai na conta: data da venda + D+N
     * (dias corridos) do cadastro de taxas. Sem prazo cadastrado, data nula.
     *
     * @return Collection<int, array{key: string, data: ?Carbon, maquininha: string, transacoes: int, bruto: float, taxa_percentual: float, taxa: float, liquido: float}>
     */
    public function previsaoRecebimento(): Collection
    {
        return $this->pagamentosMaquininha()
            ->map(fn (array $pagamento): array => [
                ...$pagamento,
                'data_prevista' => $pagamento['prazo_dias'] !== null
                    ? $pagamento['finalizada_em']->copy()->startOfDay()->addDays($pagamento['prazo_dias'])
                    : null,
            ])
            ->groupBy(fn (array $pagamento): string => ($pagamento['data_prevista']?->format('Y-m-d') ?? 'sem-prazo').'|'.$pagamento['maquininha'])
            ->map(fn (Collection $pagamentos, string $key): array => [
                'key' => $key,
                'data' => $pagamentos->first()['data_prevista'],
                'maquininha' => $pagamentos->first()['maquininha'],
                'transacoes' => $pagamentos->count(),
                ...self::somas($pagamentos),
            ])
            ->sortBy(fn (array $linha): string => ($linha['data']?->format('Y-m-d') ?? '9999-12-31').$linha['maquininha'])
            ->values();
    }

    // ── Fluxo de caixa, movimentações e conferência (por sessão) ─────────────

    /**
     * Componentes do esperado de cada sessão por categoria — mesmas regras de
     * FechamentoCaixaService::calcularEsperado(), separadas por origem.
     *
     * @return array<int, array<string, array{abertura: float, vendas: float, fiado: float, suprimentos: float, saidas: float}>>
     */
    private function componentesPorSessao(): array
    {
        if (isset($this->cache['componentes'])) {
            return $this->cache['componentes'];
        }

        $ids = $this->sessoes()->modelKeys();
        $vazio = ['abertura' => 0.0, 'vendas' => 0.0, 'fiado' => 0.0, 'suprimentos' => 0.0, 'saidas' => 0.0];
        $componentes = [];

        foreach ($ids as $id) {
            $componentes[$id] = array_fill_keys(array_keys(self::CATEGORIAS), $vazio);
        }

        foreach ($this->pagamentosDasSessoes()->where('origem', 'venda') as $pagamento) {
            if (isset($componentes[$pagamento['sessao_id']])) {
                $componentes[$pagamento['sessao_id']][$pagamento['categoria']]['vendas'] += $pagamento['valor'];
            }
        }

        $fiado = DB::table('lancamento_pagamentos')
            ->join('lancamentos', 'lancamentos.id', '=', 'lancamento_pagamentos.lancamento_id')
            ->whereIn('lancamento_pagamentos.sessao_caixa_id', $ids)
            ->where('lancamentos.tipo', 'receber')
            ->groupBy('lancamento_pagamentos.sessao_caixa_id', 'lancamento_pagamentos.forma_pagamento')
            ->selectRaw('lancamento_pagamentos.sessao_caixa_id AS sessao_id, lancamento_pagamentos.forma_pagamento AS forma, SUM(lancamento_pagamentos.valor) AS total')
            ->get();

        foreach ($fiado as $linha) {
            if (FormaPagamento::tryFrom((string) $linha->forma)?->entraNoCaixa() === false) {
                continue;
            }

            $componentes[$linha->sessao_id][self::categoriaDoFinanceiro($linha->forma)]['fiado'] += (float) $linha->total;
        }

        foreach ($this->movimentosManuaisEAbertura() as $movimento) {
            $categoria = self::categoriaDoFinanceiro($movimento->forma);
            $componente = match (true) {
                $movimento->mov_tipo === 'SAIDA' => 'saidas',
                $movimento->mov_motivo === null => 'abertura',
                default => 'suprimentos',
            };

            $componentes[$movimento->sessao_id][$categoria][$componente] += (float) $movimento->total;
        }

        return $this->cache['componentes'] = $componentes;
    }

    /**
     * Abertura (ENTRADA sem motivo, com forma), suprimentos (ENTRADA manual) e
     * saídas manuais (SAIDA com motivo; forma nula = dinheiro). Ficam de fora a
     * entrada de cada venda e o estorno Stone, como em calcularEsperado().
     *
     * @return Collection<int, object{sessao_id: int, mov_tipo: string, mov_motivo: ?string, forma: ?string, qtd: int, total: float}>
     */
    private function movimentosManuaisEAbertura(): Collection
    {
        return $this->cache['movimentos'] ??= DB::table('movimentacoes_sessao_caixas')
            ->whereIn('mov_sessaocaixa_id', $this->sessoes()->modelKeys())
            ->whereNull('deleted_at')
            ->where(fn ($query) => $query
                ->where(fn ($query) => $query->where('mov_tipo', 'ENTRADA')->whereNotNull('mov_forma_pagamento')->whereNull('mov_venda_id'))
                ->orWhere(fn ($query) => $query->where('mov_tipo', 'SAIDA')->whereNotNull('mov_motivo')))
            ->groupBy('mov_sessaocaixa_id', 'mov_tipo', 'mov_motivo', 'forma')
            ->selectRaw("mov_sessaocaixa_id AS sessao_id, mov_tipo, mov_motivo,
                CASE WHEN mov_tipo = 'SAIDA' THEN COALESCE(mov_forma_pagamento, 'dinheiro') ELSE mov_forma_pagamento END AS forma,
                COUNT(*) AS qtd, SUM(mov_valor) AS total")
            ->get();
    }

    /**
     * Apurado de uma sessão por categoria: dinheiro contado e leitura das
     * maquininhas menos o carryover da abertura (acessores do FechamentoCaixa).
     *
     * @return array<string, float>|null
     */
    private static function apuradoDaSessao(SessaoCaixa $sessao): ?array
    {
        $fechamento = $sessao->fechamentoCaixa;

        if (! $fechamento) {
            return null;
        }

        return [
            'dinheiro' => $fechamento->totalDinheiroContado,
            'debito' => $fechamento->totalDebito,
            'credito' => $fechamento->totalCredito,
            'pix' => $fechamento->totalPix,
            'pix_cnpj' => $fechamento->totalPixCnpj,
            'outros' => 0.0,
        ];
    }

    /**
     * Fluxo de caixa por categoria somando as sessões do recorte: abertura +
     * vendas + fiado recebido + suprimentos − saídas = esperado. O apurado e a
     * diferença consideram só as sessões que já têm fechamento.
     *
     * @return Collection<int, array{key: string, categoria: string, abertura: float, vendas: float, fiado: float, suprimentos: float, saidas: float, esperado: float, apurado: ?float, diferenca: ?float}>
     */
    public function fluxoCaixa(): Collection
    {
        $componentes = $this->componentesPorSessao();
        $sessoes = $this->sessoes()->keyBy('id');

        $linhas = collect(self::CATEGORIAS)->map(function (string $rotulo, string $categoria) use ($componentes, $sessoes): array {
            $linha = ['abertura' => 0.0, 'vendas' => 0.0, 'fiado' => 0.0, 'suprimentos' => 0.0, 'saidas' => 0.0];
            $apurado = null;
            $esperadoConferido = 0.0;

            foreach ($componentes as $sessaoId => $porCategoria) {
                $valores = $porCategoria[$categoria];

                foreach ($valores as $componente => $valor) {
                    $linha[$componente] += $valor;
                }

                $apuradoSessao = self::apuradoDaSessao($sessoes[$sessaoId]);

                if ($apuradoSessao !== null) {
                    $apurado = ($apurado ?? 0.0) + $apuradoSessao[$categoria];
                    $esperadoConferido += self::esperado($valores);
                }
            }

            $linha = array_map(fn (float $valor): float => round($valor, 2), $linha);

            return [
                'key' => $categoria,
                'categoria' => $rotulo,
                ...$linha,
                'esperado' => round(self::esperado($linha), 2),
                'apurado' => $apurado !== null ? round($apurado, 2) : null,
                'diferenca' => $apurado !== null ? round($apurado - $esperadoConferido, 2) : null,
            ];
        });

        return $linhas
            ->reject(fn (array $linha): bool => $linha['key'] === 'outros' && $linha['esperado'] == 0 && ! $linha['apurado'])
            ->values();
    }

    /** @param  array<string, float>  $componentes */
    private static function esperado(array $componentes): float
    {
        return $componentes['abertura'] + $componentes['vendas'] + $componentes['fiado'] + $componentes['suprimentos'] - $componentes['saidas'];
    }

    /**
     * Movimentações das sessões do recorte, uma linha por tipo × forma (valor
     * com sinal: saídas negativas). O estorno Stone entra só como informação —
     * o efeito dele já está nas vendas.
     *
     * @return Collection<int, array{key: string, movimento: string, forma: string, quantidade: int, valor: float}>
     */
    public function movimentacoes(): Collection
    {
        $ids = $this->sessoes()->modelKeys();

        $linhas = $this->movimentosManuaisEAbertura()
            ->groupBy(fn (object $movimento): string => $movimento->mov_tipo.'|'.($movimento->mov_motivo ?? 'abertura').'|'.$movimento->forma)
            ->map(function (Collection $movimentos, string $key): array {
                $primeiro = $movimentos->first();
                $saida = $primeiro->mov_tipo === 'SAIDA';

                return [
                    'key' => $key,
                    'movimento' => $primeiro->mov_motivo === null
                        ? 'Abertura (saldo inicial)'
                        : (MotivoSaidaCaixa::tryFrom($primeiro->mov_motivo)?->getLabel() ?? $primeiro->mov_motivo),
                    'forma' => self::CATEGORIAS[self::categoriaDoFinanceiro($primeiro->forma)],
                    'quantidade' => (int) $movimentos->sum('qtd'),
                    'valor' => round(($saida ? -1 : 1) * $movimentos->sum(fn (object $movimento): float => (float) $movimento->total), 2),
                ];
            });

        $fiado = DB::table('lancamento_pagamentos')
            ->join('lancamentos', 'lancamentos.id', '=', 'lancamento_pagamentos.lancamento_id')
            ->whereIn('lancamento_pagamentos.sessao_caixa_id', $ids)
            ->where('lancamentos.tipo', 'receber')
            ->groupBy('lancamento_pagamentos.forma_pagamento')
            ->selectRaw('lancamento_pagamentos.forma_pagamento AS forma, COUNT(*) AS qtd, SUM(lancamento_pagamentos.valor) AS total')
            ->get()
            ->map(function (object $linha): array {
                $forma = FormaPagamento::tryFrom((string) $linha->forma);

                return [
                    'key' => 'fiado|'.$linha->forma,
                    'movimento' => $forma?->entraNoCaixa() === false
                        ? 'Recebimento de fiado (sem entrada no caixa)'
                        : 'Recebimento de fiado',
                    'forma' => $forma?->getLabel() ?? 'Não informada',
                    'quantidade' => (int) $linha->qtd,
                    'valor' => round((float) $linha->total, 2),
                ];
            });

        $estornos = DB::table('movimentacoes_sessao_caixas')
            ->whereIn('mov_sessaocaixa_id', $ids)
            ->whereNull('deleted_at')
            ->where('mov_tipo', 'SAIDA')
            ->whereNull('mov_motivo')
            ->whereNotNull('mov_venda_id')
            ->selectRaw('COUNT(*) AS qtd, SUM(mov_valor) AS total')
            ->first();

        $ordem = ['Abertura (saldo inicial)' => 0, 'Recebimento de fiado' => 1];

        return $linhas->values()
            ->concat($fiado)
            ->when((int) $estornos->qtd > 0, fn (Collection $linhas) => $linhas->push([
                'key' => 'estorno-stone',
                'movimento' => 'Estorno Stone (já abatido das vendas)',
                'forma' => 'Cartão/Pix',
                'quantidade' => (int) $estornos->qtd,
                'valor' => round(-(float) $estornos->total, 2),
            ]))
            ->sortBy(fn (array $linha): string => ($ordem[$linha['movimento']] ?? ($linha['valor'] >= 0 ? 2 : 3)).$linha['movimento'].$linha['forma'])
            ->values();
    }

    /**
     * Uma linha por sessão: esperado (recalculado) × apurado no fechamento.
     *
     * @return Collection<int, array{key: string, sessao_id: int, caixa: string, operador: string, abertura: ?Carbon, fechamento: ?Carbon, status: string, esperado: float, apurado: ?float, diferenca: ?float, diferenca_dinheiro: ?float}>
     */
    public function conferencia(): Collection
    {
        $componentes = $this->componentesPorSessao();

        return $this->sessoes()->map(function (SessaoCaixa $sessao) use ($componentes): array {
            $esperadoPorCategoria = array_map(fn (array $valores): float => self::esperado($valores), $componentes[$sessao->id]);
            $apurado = self::apuradoDaSessao($sessao);
            $esperado = round(array_sum($esperadoPorCategoria), 2);

            return [
                'key' => (string) $sessao->id,
                'sessao_id' => $sessao->id,
                'caixa' => (string) ($sessao->caixa?->caixa_nome ?? '—'),
                'operador' => (string) ($sessao->user?->name ?? '—'),
                'abertura' => $sessao->sessaocaixa_data_hora_abertura,
                'fechamento' => $sessao->sessaocaixa_data_hora_fechamento,
                'status' => $sessao->fechamentoCaixa?->status?->getLabel() ?? ($sessao->sessaocaixa_status === 'ABERTA' ? 'Sessão aberta' : 'Sem fechamento'),
                'esperado' => $esperado,
                'apurado' => $apurado !== null ? round(array_sum($apurado), 2) : null,
                'diferenca' => $apurado !== null ? round(array_sum($apurado) - $esperado, 2) : null,
                'diferenca_dinheiro' => $apurado !== null ? round($apurado['dinheiro'] - $esperadoPorCategoria['dinheiro'], 2) : null,
            ];
        })->values();
    }

    /**
     * Sistema × leitura por maquininha em cada sessão com fechamento. Sistema =
     * pagamentos de venda e recebimentos de fiado atribuídos à maquininha (sem
     * maquininha = padrão); leitura = o informado no fechamento menos o
     * carryover da abertura. O PIX CNPJ entra numa linha própria contra o
     * extrato informado no fechamento.
     *
     * @return Collection<int, array{key: string, sessao_id: int, maquininha: string, sistema: float, leitura: float, diferenca: float, diferenca_debito: float, diferenca_credito: float, diferenca_pix: float}>
     */
    public function conferenciaMaquininhas(): Collection
    {
        $nomes = Maquininha::withTrashed()->pluck('nome', 'id');
        $sistema = $this->pagamentosDasSessoes()
            ->filter(fn (array $pagamento): bool => $pagamento['tipo'] !== null)
            ->groupBy(fn (array $pagamento): string => $pagamento['sessao_id'].'|'.($pagamento['maquininha_id'] ?? 0));

        return $this->sessoes()
            ->filter(fn (SessaoCaixa $sessao): bool => $sessao->fechamentoCaixa !== null)
            ->flatMap(function (SessaoCaixa $sessao) use ($sistema, $nomes): array {
                $carryover = $sessao->maquininhas->keyBy('maquininha_id');
                $leituras = $sessao->fechamentoCaixa->maquininhas->keyBy('maquininha_id');

                $maquininhaIds = $leituras->keys()
                    ->merge($sistema->keys()
                        ->filter(fn (string $key): bool => str_starts_with($key, $sessao->id.'|'))
                        ->map(fn (string $key): int => (int) explode('|', $key)[1]))
                    ->unique()
                    ->values();

                return $maquininhaIds->map(function (int $maquininhaId) use ($sessao, $sistema, $carryover, $leituras, $nomes): array {
                    $pagamentos = $sistema->get($sessao->id.'|'.$maquininhaId, collect());
                    $porTipoSistema = self::porTipo($pagamentos);
                    $porTipoLeitura = [];

                    foreach (['debito', 'credito', 'pix'] as $tipo) {
                        $porTipoLeitura[$tipo] = (float) ($leituras->get($maquininhaId)?->{'valor_'.$tipo} ?? 0)
                            - (float) ($carryover->get($maquininhaId)?->{'valor_'.$tipo} ?? 0);
                    }

                    $sistemaTotal = round(array_sum($porTipoSistema), 2);
                    $leituraTotal = round(array_sum($porTipoLeitura), 2);

                    return [
                        'key' => $sessao->id.'-'.$maquininhaId,
                        'sessao_id' => $sessao->id,
                        'maquininha' => (string) ($nomes[$maquininhaId] ?? 'Não informada'),
                        'sistema' => $sistemaTotal,
                        'leitura' => $leituraTotal,
                        'diferenca' => round($leituraTotal - $sistemaTotal, 2),
                        'diferenca_debito' => round($porTipoLeitura['debito'] - $porTipoSistema['debito'], 2),
                        'diferenca_credito' => round($porTipoLeitura['credito'] - $porTipoSistema['credito'], 2),
                        'diferenca_pix' => round($porTipoLeitura['pix'] - $porTipoSistema['pix'], 2),
                    ];
                })->push($this->conferenciaPixCnpj($sessao))->filter()->values()->all();
            })
            ->values();
    }

    /**
     * Linha "PIX CNPJ" da conferência: vendas + fiado em PIX CNPJ da sessão ×
     * valor do extrato informado no fechamento. Nula se não houve nenhum dos dois.
     *
     * @return array<string, mixed>|null
     */
    private function conferenciaPixCnpj(SessaoCaixa $sessao): ?array
    {
        $sistema = round($this->pagamentosDasSessoes()
            ->filter(fn (array $pagamento): bool => $pagamento['pix_cnpj'] && $pagamento['sessao_id'] === $sessao->id)
            ->sum('valor'), 2);
        $extrato = round($sessao->fechamentoCaixa->totalPixCnpj, 2);

        if ($sistema == 0.0 && $extrato == 0.0) {
            return null;
        }

        return [
            'key' => $sessao->id.'-pix-cnpj',
            'sessao_id' => $sessao->id,
            'maquininha' => 'PIX CNPJ (extrato)',
            'sistema' => $sistema,
            'leitura' => $extrato,
            'diferenca' => round($extrato - $sistema, 2),
            'diferenca_debito' => 0.0,
            'diferenca_credito' => 0.0,
            'diferenca_pix' => round($extrato - $sistema, 2),
        ];
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * @param  Collection<int, array<string, mixed>>  $pagamentos
     * @return array{bruto: float, taxa_percentual: float, taxa: float, liquido: float}
     */
    private static function somas(Collection $pagamentos): array
    {
        $bruto = round($pagamentos->sum('valor'), 2);
        $taxa = round($pagamentos->sum('taxa'), 2);

        return [
            'bruto' => $bruto,
            'taxa_percentual' => self::percentual($taxa, $bruto),
            'taxa' => $taxa,
            'liquido' => round($bruto - $taxa, 2),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $pagamentos
     * @return array{debito: float, credito: float, pix: float}
     */
    private static function porTipo(Collection $pagamentos): array
    {
        $soma = fn (TipoPagamentoMaquininhaEnum $tipo): float => round(
            $pagamentos->filter(fn (array $pagamento): bool => $pagamento['tipo'] === $tipo)->sum('valor'),
            2,
        );

        return [
            'debito' => $soma(TipoPagamentoMaquininhaEnum::Debito),
            'credito' => $soma(TipoPagamentoMaquininhaEnum::Credito),
            'pix' => $soma(TipoPagamentoMaquininhaEnum::Pix),
        ];
    }

    private static function percentual(float $parte, float $todo): float
    {
        return $todo != 0.0 ? round($parte / $todo * 100, 2) : 0.0;
    }
}
