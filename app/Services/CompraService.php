<?php

namespace App\Services;

use App\Enums\Compras\OrigemVinculo;
use App\Enums\CompraStatusEnum;
use App\Enums\FormaPagamento;
use App\Enums\MovimentacaoOrigemEnum;
use App\Enums\StatusLancamento;
use App\Enums\TipoLancamento;
use App\Models\Compra;
use App\Models\CompraItem;
use App\Models\EstoqueLote;
use App\Models\FornecedorProduto;
use App\Models\Lancamento;
use App\Models\LancamentoDespesa;
use App\Models\PlanoDespesa;
use App\Models\PrestadorCredito;
use App\Services\Compras\CompraFiscalService;
use App\Services\Compras\CustoAquisicaoCalculator;
use App\Services\Compras\PendenciaService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompraService
{
    public function __construct(
        private EstoqueService $estoque,
        private CompraFiscalService $fiscal,
        private CustoAquisicaoCalculator $custos,
    ) {}

    /** Recalcula os totais da compra a partir dos itens. */
    public function recalcularTotais(Compra $compra): void
    {
        $produtos = $compra->itens->sum(fn (CompraItem $item) => $item->valorProdutos());

        $compra->forceFill([
            'compra_valor_produtos' => round($produtos, 2),
            'compra_valor_total' => round($produtos + $this->custos->acrescimosDaCompra($compra), 2),
        ])->save();
    }

    /**
     * Trava a linha da compra até o fim da transação e sincroniza a instância
     * recebida com o banco. O status só pode ser checado DEPOIS disto: duas
     * abas ou um duplo clique confirmariam a mesma compra duas vezes (o lock
     * do EstoqueService é só por produto).
     */
    private function travarCompra(Compra $compra): void
    {
        $travada = Compra::lockForUpdate()->findOrFail($compra->getKey());

        $compra->setRawAttributes($travada->getAttributes(), true);
        $compra->unsetRelations();
    }

    /**
     * Nota de devolução (ou documento não processável) nunca vira entrada de
     * estoque: o efeito certo é saída + crédito com o fornecedor (ver
     * DevolucaoPorNotaService). Compra importada antes da classificação é
     * classificada aqui, a partir do XML guardado.
     */
    private function garantirQueGeraEntrada(Compra $compra): void
    {
        $this->fiscal->classificarSeFaltar($compra);

        if (! $compra->operacaoImpedeEntrada()) {
            return;
        }

        $mensagem = $compra->ehNotaDeDevolucao()
            ? 'Esta nota é uma devolução ao fornecedor e não pode dar entrada no estoque. Use "Registrar devolução da nota": ela baixa o estoque e gera o crédito com o fornecedor.'
            : 'Esta nota foi classificada como não processável (documento de outra empresa ou sem efeito) e não pode ser confirmada como compra.';

        throw ValidationException::withMessages(['compra' => $mensagem]);
    }

    /**
     * Confirma a compra: rateia acréscimos, gera as entradas de estoque
     * (recalculando o custo médio) e atualiza o de-para de fornecedor.
     *
     * $dataEntrada, quando informada, substitui a compra_data_entrada antes de
     * gerar as movimentações — o importador de NF-e preenche com o dia da
     * importação, que nem sempre é o dia em que a mercadoria entrou.
     */
    public function confirmar(Compra $compra, ?Carbon $dataEntrada = null): Compra
    {
        return DB::transaction(function () use ($compra, $dataEntrada) {
            $this->travarCompra($compra);
            $compra->loadMissing('itens.insumo');

            if (! $compra->isRascunho()) {
                throw ValidationException::withMessages(['compra' => 'A compra não está em rascunho.']);
            }

            $this->garantirQueGeraEntrada($compra);

            if ($dataEntrada !== null) {
                $this->validarDataEntrada($compra, $dataEntrada);
                $compra->forceFill(['compra_data_entrada' => $dataEntrada->toDateString()])->save();
            }

            $itens = $compra->itens;
            if ($itens->isEmpty()) {
                throw ValidationException::withMessages(['itens' => 'A compra não possui itens.']);
            }
            if ($itens->contains(fn (CompraItem $item) => empty($item->ci_produto_id))) {
                throw ValidationException::withMessages(['itens' => 'Todos os itens precisam estar vinculados a um produto do estoque.']);
            }

            $custos = $this->custos->calcular($compra, $itens);

            foreach ($itens as $item) {
                $custo = $custos[$item->id];
                $item->forceFill([
                    'ci_valor_rateio' => $custo['rateio'],
                    'ci_custo_aquisicao_total' => $custo['total'],
                    'ci_custo_unitario_aquisicao' => $custo['unitario'],
                ])->save();

                $movimentacao = $this->estoque->registrarEntrada(
                    $item->insumo,
                    $item->quantidadeEstoque(),
                    $item->custoUnitarioEstoque(),
                    MovimentacaoOrigemEnum::COMPRA,
                    [
                        'validade' => $item->ci_validade,
                        'lote_codigo' => $item->ci_lote_codigo,
                        'marca_id' => $item->ci_marca_id,
                        'centro_custo_id' => $compra->compra_centro_custo_id,
                        'referencia' => $compra,
                        'data' => $compra->compra_data_entrada
                            ? $compra->compra_data_entrada->copy()->setTimeFrom(now())
                            : now(),
                        'user_id' => $compra->compra_user_id,
                    ],
                );

                // Vincula o lote criado ao item de compra (rastreabilidade).
                if ($movimentacao->mov_lote_id) {
                    $movimentacao->lote?->forceFill(['lote_compra_item_id' => $item->id])->save();
                }

                $this->atualizarDePara($compra, $item);
            }

            $this->recalcularTotais($compra);
            $compra->forceFill([
                'compra_status' => CompraStatusEnum::CONFIRMADA,
                'compra_confirmada_em' => now(),
            ])->save();

            // Títulos gerados na importação automática, antes dos vínculos finais.
            $this->reratearTitulosEmAberto($compra);

            return $compra->refresh();
        });
    }

    /**
     * Corrige a data de entrada de uma compra já confirmada: a própria compra,
     * as movimentações de entrada e os lotes gerados por ela (preservando o
     * horário original) e desloca os vencimentos dos títulos em aberto pela
     * mesma diferença de dias — títulos pagos/cancelados não mudam. Com
     * $deslocarVencimentos = false (vencimentos contados da nota/emissão, que não
     * dependem da entrada) os títulos ficam como estão.
     *
     * Não mexe em saldo nem custo médio: o WAC e o mov_saldo_apos seguem a
     * ordem de inserção (id), não mov_data — a data é só data de negócio.
     *
     * @return array{movimentacoes: int, lotes: int, titulos: int, dias: int}
     */
    public function corrigirDataEntrada(Compra $compra, Carbon $novaData, ?string $responsavel = null, bool $deslocarVencimentos = true): array
    {
        return DB::transaction(function () use ($compra, $novaData, $responsavel, $deslocarVencimentos) {
            /** @var Compra $compra */
            $compra = Compra::lockForUpdate()->findOrFail($compra->id);

            if ($compra->compra_status !== CompraStatusEnum::CONFIRMADA) {
                throw ValidationException::withMessages(['compra' => 'Só é possível corrigir a data de entrada de uma compra confirmada.']);
            }

            $dataAnterior = $compra->compra_data_entrada?->copy()->startOfDay();
            $novaData = $novaData->copy()->startOfDay();

            if ($dataAnterior?->equalTo($novaData)) {
                throw ValidationException::withMessages(['data_entrada' => 'A nova data é igual à data de entrada atual.']);
            }

            $this->validarDataEntrada($compra, $novaData);

            $primeiraDevolucao = $compra->devolucoes()->min('created_at');
            if ($primeiraDevolucao !== null && $novaData->greaterThan(Carbon::parse($primeiraDevolucao)->startOfDay())) {
                throw ValidationException::withMessages(['data_entrada' => 'A data de entrada não pode ser posterior à devolução já registrada ('.Carbon::parse($primeiraDevolucao)->format('d/m/Y').').']);
            }

            $dias = $dataAnterior ? (int) $dataAnterior->diffInDays($novaData, false) : 0;

            $movimentacoes = $this->movimentacoesDeEntrada($compra)->get();
            foreach ($movimentacoes as $movimentacao) {
                $movimentacao->forceFill(['mov_data' => $movimentacao->mov_data->copy()->setDateFrom($novaData)])->save();
            }

            $lotes = $this->lotesDaCompra($compra)->get();
            foreach ($lotes as $lote) {
                $lote->forceFill(['lote_data_entrada' => ($lote->lote_data_entrada ?? $novaData)->copy()->setDateFrom($novaData)])->save();
            }

            $titulos = $deslocarVencimentos && $dias !== 0 ? $this->titulosEmAberto($compra)->get() : new Collection;
            foreach ($titulos as $titulo) {
                $titulo->forceFill(['vencimento' => $titulo->vencimento->copy()->addDays($dias)])->save();
            }

            $registro = sprintf(
                'Data de entrada corrigida de %s para %s%s em %s.',
                $dataAnterior?->format('d/m/Y') ?? '—',
                $novaData->format('d/m/Y'),
                $responsavel ? " por {$responsavel}" : '',
                now()->format('d/m/Y H:i'),
            );

            $compra->forceFill([
                'compra_data_entrada' => $novaData->toDateString(),
                'compra_observacao' => trim(($compra->compra_observacao ? $compra->compra_observacao."\n" : '').$registro),
            ])->save();

            return [
                'movimentacoes' => $movimentacoes->count(),
                'lotes' => $lotes->count(),
                'titulos' => $titulos->count(),
                'dias' => $dias,
            ];
        });
    }

    /**
     * Prévia do que corrigirDataEntrada() vai ajustar — pra mostrar no modal antes de aplicar.
     *
     * @return array{movimentacoes: int, lotes: int, titulos: int}
     */
    public function previaCorrecaoDataEntrada(Compra $compra): array
    {
        return [
            'movimentacoes' => $this->movimentacoesDeEntrada($compra)->count(),
            'lotes' => $this->lotesDaCompra($compra)->count(),
            'titulos' => $this->titulosEmAberto($compra)->count(),
        ];
    }

    /** Entrada no estoque não pode ser futura nem anterior à emissão da nota. */
    private function validarDataEntrada(Compra $compra, Carbon $data): void
    {
        if ($data->copy()->startOfDay()->greaterThan(today())) {
            throw ValidationException::withMessages(['data_entrada' => 'A data de entrada não pode ser futura.']);
        }

        if ($compra->compra_data_emissao && $data->copy()->startOfDay()->lessThan($compra->compra_data_emissao->copy()->startOfDay())) {
            throw ValidationException::withMessages(['data_entrada' => 'A data de entrada não pode ser anterior à emissão da nota ('.$compra->compra_data_emissao->format('d/m/Y').').']);
        }
    }

    private function movimentacoesDeEntrada(Compra $compra): MorphMany
    {
        return $compra->movimentacoes()->where('mov_origem', MovimentacaoOrigemEnum::COMPRA);
    }

    private function lotesDaCompra(Compra $compra): Builder
    {
        return EstoqueLote::query()->whereIn('lote_compra_item_id', $compra->itens()->select('id'));
    }

    private function titulosEmAberto(Compra $compra): HasMany
    {
        return $compra->lancamentos()->whereIn('status', [StatusLancamento::Pendente, StatusLancamento::Parcial]);
    }

    /**
     * Gera os títulos a pagar (Lançamentos) a partir de uma compra confirmada — um por
     * parcela informada.
     *
     * O valor de cada parcela é rateado entre planos de despesa conforme a classificação
     * de cada produto (produto_plano_despesa_id): insumos e produtos de revenda podem cair
     * em planos diferentes, então cada título tem N linhas de despesa (soma = valor do
     * título). Produtos sem plano vão para uma linha remanescente "sem classificação"
     * (plano nulo). A proporção entre planos é a mesma em todas as parcelas.
     *
     * O serviço não conhece o cadastro de PrazoPagamento nem percentuais — recebe
     * vencimento/valor/forma já calculados por parcela; `prazo_pagamento_id` é só
     * rastreabilidade de qual prazo (se algum) originou o lote.
     *
     * @param  array{
     *     prazo_pagamento_id?: int|null,
     *     parcelas: array<int, array{vencimento: string|\DateTimeInterface, valor: float|string, forma_pagamento?: FormaPagamento|string|null, ja_pago?: bool, conta_financeira_id?: int|string|null, sessao_caixa_id?: int|string|null}>,
     * }  $dados
     * @return Collection<int, Lancamento>
     */
    public function gerarContaPagar(Compra $compra, array $dados = []): Collection
    {
        return DB::transaction(function () use ($compra, $dados) {
            $this->travarCompra($compra);
            $compra->loadMissing(['itens.insumo.planoDespesa', 'prestador']);

            if ($compra->compra_status !== CompraStatusEnum::CONFIRMADA) {
                throw ValidationException::withMessages(['compra' => 'A compra precisa estar confirmada para gerar a conta a pagar.']);
            }

            // Idempotência: uma compra gera seu lote de títulos uma única vez.
            if ($compra->lancamentos()->exists()) {
                throw ValidationException::withMessages(['compra' => 'Esta compra já possui conta(s) a pagar geradas.']);
            }

            $parcelas = array_values($dados['parcelas'] ?? []);
            if ($parcelas === []) {
                throw ValidationException::withMessages(['parcelas' => 'Informe ao menos uma parcela.']);
            }

            return $this->criarTitulos($compra, $parcelas, $dados['prazo_pagamento_id'] ?? null);
        });
    }

    /**
     * Ao excluir uma compra em rascunho: a importação automática já gera os
     * títulos no rascunho, então eles são cancelados junto (senão ficariam
     * em aberto no contas a pagar e, numa reimportação da nota, em dobro) e
     * as pendências em aberto são descartadas. Título com pagamento barra a
     * exclusão — estorne o pagamento antes.
     *
     * @return bool false quando a exclusão deve ser barrada
     */
    public function prepararExclusaoRascunho(Compra $compra): bool
    {
        if (! $compra->isRascunho()) {
            return true;
        }

        $titulos = $compra->lancamentos()->where('status', '!=', StatusLancamento::Cancelado)->get();

        if ($titulos->contains(fn (Lancamento $titulo): bool => $titulo->pagamentos()->exists())) {
            return false;
        }

        DB::transaction(function () use ($compra, $titulos): void {
            foreach ($titulos as $titulo) {
                $titulo->forceFill(['status' => StatusLancamento::Cancelado])->save();
            }

            app(PendenciaService::class)->descartarDaCompra($compra, 'Compra excluída.', auth()->user());
        });

        return true;
    }

    /**
     * Cria um título a pagar por parcela, rateado pelos planos de despesa dos
     * produtos, abatendo créditos de devolução do fornecedor. Usado por
     * gerarContaPagar() (compra confirmada) e pela automação de compras, que
     * gera os títulos ainda no rascunho: aí os itens sem plano (ou sem produto)
     * vão para o $planoPadraoId do fornecedor, e o rateio é refeito na
     * confirmação (reratearTitulosEmAberto).
     *
     * @param  list<array{vencimento: string|\DateTimeInterface, valor: float|string, forma_pagamento?: FormaPagamento|string|null, ja_pago?: bool, conta_financeira_id?: int|string|null, sessao_caixa_id?: int|string|null}>  $parcelas
     * @return Collection<int, Lancamento>
     */
    public function criarTitulos(Compra $compra, array $parcelas, ?int $prazoPagamentoId = null, ?int $planoPadraoId = null): Collection
    {
        return DB::transaction(function () use ($compra, $parcelas, $prazoPagamentoId, $planoPadraoId) {
            $compra->loadMissing(['itens.insumo.planoDespesa', 'prestador']);

            $porPlano = $this->rateioPorPlano($compra, $planoPadraoId);
            $planoUnico = $this->planoUnico($porPlano);

            $total = count($parcelas);
            $valorTotalCompra = (float) $compra->compra_valor_total;

            // Créditos disponíveis com este fornecedor (de devoluções de compras
            // anteriores) — nunca abatem a própria compra que os originou, porque
            // só existem depois que a devolução é registrada numa compra já
            // confirmada, e este método só roda uma vez por compra.
            $creditos = $compra->compra_prestador_id
                ? PrestadorCredito::doPrestador($compra->compra_prestador_id)->naoAplicados()->orderBy('id')->get()
                : new Collection;
            $creditoIndice = 0;

            $lancamentos = new Collection;

            foreach ($parcelas as $indice => $parcela) {
                $valorParcela = round((float) $parcela['valor'], 2);

                // Abate créditos disponíveis (sem fracionar linha) enquanto couberem
                // inteiros no valor restante desta parcela; o que não couber tenta na
                // próxima parcela do mesmo lote.
                $creditosAplicados = [];
                while ($creditoIndice < $creditos->count() && (float) $creditos[$creditoIndice]->valor <= $valorParcela) {
                    $credito = $creditos[$creditoIndice];
                    $valorParcela = round($valorParcela - (float) $credito->valor, 2);
                    $creditosAplicados[] = $credito;
                    $creditoIndice++;
                }

                $formaPagamento = $parcela['forma_pagamento'] ?? null;
                if (is_string($formaPagamento)) {
                    $formaPagamento = FormaPagamento::tryFrom($formaPagamento);
                }

                $lancamento = new Lancamento([
                    'tipo' => TipoLancamento::Pagar,
                    'compra_id' => $compra->id,
                    'prazo_pagamento_id' => $prazoPagamentoId,
                    'parcela_numero' => $indice + 1,
                    'parcela_total' => $total,
                    'plano_despesa_id' => $planoUnico,
                    'favorecido_id' => $compra->compra_prestador_id,
                    'descricao' => $this->descricaoContaPagar($compra, $indice + 1, $total),
                    'numero_documento' => $compra->compra_numero ?: $compra->compra_chave_nfe,
                    'valor' => $valorParcela,
                    'vencimento' => $parcela['vencimento'],
                    'status' => StatusLancamento::Pendente,
                    'forma_pagamento' => $formaPagamento,
                ]);
                $lancamento->save();

                foreach ($creditosAplicados as $credito) {
                    $credito->update(['aplicado_em_lancamento_id' => $lancamento->id]);
                }

                // "Já pago" na confirmação (ex.: compra à vista via PIX): registra o
                // pagamento integral desta parcela imediatamente — dispara
                // Lancamento::recalcularStatus() via evento saved do LancamentoPagamento,
                // nascendo o título já com status Pago. A instância separada carregada
                // internamente por esse evento ($pagamento->lancamento) é sincronizada de
                // volta pelo refresh() já existente no fim deste loop.
                if ((bool) ($parcela['ja_pago'] ?? false)) {
                    $lancamento->registrarPagamento(
                        valor: $valorParcela,
                        data: $lancamento->vencimento,
                        forma: $formaPagamento,
                        sessaoCaixaId: filled($parcela['sessao_caixa_id'] ?? null) ? (int) $parcela['sessao_caixa_id'] : null,
                        contaFinanceiraId: filled($parcela['conta_financeira_id'] ?? null) ? (int) $parcela['conta_financeira_id'] : null,
                    );
                }

                // Rateio proporcional ao peso desta parcela no total da compra. A soma dos
                // valores das parcelas não precisa fechar com compra_valor_total (parcelamento
                // com juros embutido soma mais) — a reconciliação de centavos só acontece
                // dentro da própria parcela, entre as linhas de plano de despesa.
                $fator = $valorTotalCompra > 0 ? ($valorParcela / $valorTotalCompra) : 0.0;
                $this->gravarRateio($lancamento, $this->escalarRateio($porPlano, $fator));

                $lancamentos->push($lancamento->refresh());
            }

            return $lancamentos;
        });
    }

    /**
     * Valor da compra por plano de despesa do produto (chave '' = sem plano).
     * Item sem plano ou sem produto vai para o plano padrão, quando houver.
     *
     * @return array<array-key, array{plano_despesa_id: ?int, comportamento: mixed, valor: float}>
     */
    private function rateioPorPlano(Compra $compra, ?int $planoPadraoId): array
    {
        $padrao = $planoPadraoId !== null ? PlanoDespesa::query()->find($planoPadraoId) : null;

        $porPlano = [];
        foreach ($compra->itens as $item) {
            $plano = $item->insumo?->planoDespesa ?? $padrao;
            $chave = $plano?->id ?? '';
            $porPlano[$chave] ??= [
                'plano_despesa_id' => $plano?->id,
                'comportamento' => $plano?->comportamento,
                'valor' => 0.0,
            ];
            // Contribuição do item ao título: produtos + rateio (frete/outros − desconto).
            $porPlano[$chave]['valor'] += $item->valorProdutos() + (float) $item->ci_valor_rateio;
        }

        return $porPlano;
    }

    /**
     * Cabeçalho do título: o plano, se houver exatamente um (sem remanescente);
     * senão nulo e a classificação vive nas linhas de rateio.
     *
     * @param  array<array-key, array{plano_despesa_id: ?int, comportamento: mixed, valor: float}>  $porPlano
     */
    private function planoUnico(array $porPlano): ?int
    {
        $classificados = array_values(array_filter($porPlano, fn ($linha) => $linha['plano_despesa_id'] !== null));

        return count($classificados) === 1 && ! array_key_exists('', $porPlano)
            ? $classificados[0]['plano_despesa_id']
            : null;
    }

    /**
     * Refaz o rateio por plano dos títulos ainda em aberto (pendente/parcial)
     * da compra — os gerados na importação automática, antes de todos os itens
     * terem produto. Títulos pagos, cancelados ou lançados à mão ficam como estão.
     */
    public function reratearTitulosEmAberto(Compra $compra): int
    {
        // Só títulos com rateio de compra: um título lançado à mão e vinculado
        // depois (FinanceiroCompraService) mantém o plano que o usuário escolheu.
        $titulos = $compra->lancamentos()
            ->whereIn('status', [StatusLancamento::Pendente, StatusLancamento::Parcial])
            ->whereHas('despesas')
            ->get();

        if ($titulos->isEmpty()) {
            return 0;
        }

        $compra->load(['itens.insumo.planoDespesa', 'prestador.condicaoCompra']);
        $porPlano = $this->rateioPorPlano($compra, $compra->prestador?->condicaoCompra?->fcc_plano_despesa_id);
        $planoUnico = $this->planoUnico($porPlano);
        $valorTotalCompra = (float) $compra->compra_valor_total;

        foreach ($titulos as $titulo) {
            $titulo->despesas()->delete();
            $fator = $valorTotalCompra > 0 ? ((float) $titulo->valor / $valorTotalCompra) : 0.0;
            $this->gravarRateio($titulo, $this->escalarRateio($porPlano, $fator));

            $titulo->forceFill([
                'plano_despesa_id' => $planoUnico,
                'comportamento' => $planoUnico !== null ? PlanoDespesa::query()->find($planoUnico)?->comportamento : null,
            ])->save();
        }

        return $titulos->count();
    }

    /**
     * Escala o rateio-por-plano da compra por um fator (valor_parcela/compra_valor_total),
     * gerando as linhas de despesa proporcionais de uma parcela específica.
     *
     * @param  array<array-key, array{plano_despesa_id: ?int, comportamento: mixed, valor: float}>  $porPlano
     * @return array<array-key, array{plano_despesa_id: ?int, comportamento: mixed, valor: float}>
     */
    private function escalarRateio(array $porPlano, float $fator): array
    {
        return array_map(
            fn (array $linha) => [
                'plano_despesa_id' => $linha['plano_despesa_id'],
                'comportamento' => $linha['comportamento'],
                'valor' => $linha['valor'] * $fator,
            ],
            $porPlano,
        );
    }

    /**
     * Cria as linhas de rateio arredondando a 2 casas e reconciliando eventuais
     * centavos de arredondamento na maior linha, para a soma bater com o título.
     *
     * @param  array<array-key, array{plano_despesa_id: ?int, comportamento: mixed, valor: float}>  $porPlano
     */
    private function gravarRateio(Lancamento $lancamento, array $porPlano): void
    {
        $linhas = array_values($porPlano);
        if ($linhas === []) {
            return;
        }

        foreach ($linhas as &$linha) {
            $linha['valor'] = round($linha['valor'], 2);
        }
        unset($linha);

        // Reconcilia o resíduo de arredondamento na maior linha.
        $diferenca = round((float) $lancamento->valor - array_sum(array_column($linhas, 'valor')), 2);
        if (abs($diferenca) >= 0.01) {
            $maiorIndice = collect($linhas)->sortByDesc('valor')->keys()->first();
            $linhas[$maiorIndice]['valor'] = round($linhas[$maiorIndice]['valor'] + $diferenca, 2);
        }

        foreach ($linhas as $linha) {
            LancamentoDespesa::create([
                'lancamento_id' => $lancamento->id,
                'plano_despesa_id' => $linha['plano_despesa_id'],
                'valor' => $linha['valor'],
                'comportamento' => $linha['comportamento'],
            ]);
        }
    }

    /** Descrição legível do título a pagar gerado pela compra (com sufixo de parcela quando houver mais de uma). */
    private function descricaoContaPagar(Compra $compra, int $numeroParcela, int $totalParcelas): string
    {
        $numero = $compra->compra_numero ? "Compra Nº {$compra->compra_numero}" : "Compra #{$compra->id}";
        $fornecedor = $compra->prestador?->nome_exibicao;

        $descricao = $fornecedor ? "{$numero} - {$fornecedor}" : $numero;

        return $totalParcelas > 1 ? "{$descricao} — Parcela {$numeroParcela}/{$totalParcelas}" : $descricao;
    }

    /** Grava/atualiza o de-para código do fornecedor ↔ insumo para futuras NFs/XML. */
    private function atualizarDePara(Compra $compra, CompraItem $item): void
    {
        if (! $compra->compra_prestador_id || blank($item->ci_codigo_fornecedor)) {
            return;
        }

        // withTrashed: a unique (fornecedor, código) vale também para vínculos
        // excluídos; confirmar a compra com esse código restaura o vínculo.
        $vinculo = FornecedorProduto::withTrashed()->firstOrNew([
            'fp_prestador_id' => $compra->compra_prestador_id,
            'fp_codigo_fornecedor' => $item->ci_codigo_fornecedor,
        ]);
        $vinculo->deleted_at = null;

        $vinculo->fill([
            'fp_produto_id' => $item->ci_produto_id,
            'fp_descricao_fornecedor' => $item->ci_descricao_fornecedor,
            'fp_unidade_compra' => $item->ci_unidade_compra,
            'fp_fator_conversao' => $item->ci_fator_conversao,
            'fp_ultimo_custo_unitario' => $item->ci_custo_unitario_aquisicao,
        ]);

        if (filled($item->ci_ean)) {
            $vinculo->fp_ean = $item->ci_ean;
        }

        if (! $vinculo->exists) {
            $vinculo->fp_origem = OrigemVinculo::Manual;
        }

        $vinculo->save();

        if ((int) $item->ci_vinculo_id !== (int) $vinculo->id) {
            $item->forceFill(['ci_vinculo_id' => $vinculo->id])->save();
        }
    }
}
