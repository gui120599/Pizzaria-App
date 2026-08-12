<?php

namespace App\Services;

use App\Enums\CompraStatusEnum;
use App\Enums\FormaPagamento;
use App\Enums\MovimentacaoOrigemEnum;
use App\Enums\StatusLancamento;
use App\Enums\TipoLancamento;
use App\Models\Compra;
use App\Models\CompraItem;
use App\Models\FornecedorProduto;
use App\Models\Lancamento;
use App\Models\LancamentoDespesa;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompraService
{
    public function __construct(private EstoqueService $estoque) {}

    /** Recalcula os totais da compra a partir dos itens. */
    public function recalcularTotais(Compra $compra): void
    {
        $produtos = $compra->itens->sum(fn (CompraItem $item) => $item->valorProdutos());

        $compra->forceFill([
            'compra_valor_produtos' => round($produtos, 2),
            'compra_valor_total' => round($produtos + $compra->valorRateavel(), 2),
        ])->save();
    }

    /**
     * Confirma a compra: rateia acréscimos, gera as entradas de estoque
     * (recalculando o custo médio) e atualiza o de-para de fornecedor.
     */
    public function confirmar(Compra $compra): Compra
    {
        return DB::transaction(function () use ($compra) {
            $compra->loadMissing('itens.insumo');

            if (! $compra->isRascunho()) {
                throw ValidationException::withMessages(['compra' => 'A compra não está em rascunho.']);
            }

            $itens = $compra->itens;
            if ($itens->isEmpty()) {
                throw ValidationException::withMessages(['itens' => 'A compra não possui itens.']);
            }
            if ($itens->contains(fn (CompraItem $item) => empty($item->ci_produto_id))) {
                throw ValidationException::withMessages(['itens' => 'Todos os itens precisam estar vinculados a um produto do estoque.']);
            }

            $baseRateio = $itens->sum(fn (CompraItem $item) => $item->valorProdutos());
            $rateavel = $compra->valorRateavel();

            foreach ($itens as $item) {
                // Rateio proporcional ao valor do item.
                $rateio = ($baseRateio > 0) ? $rateavel * ($item->valorProdutos() / $baseRateio) : 0.0;
                $item->forceFill(['ci_valor_rateio' => round($rateio, 4)])->save();

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

            return $compra->refresh();
        });
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
     *     parcelas: array<int, array{vencimento: string|\DateTimeInterface, valor: float|string, forma_pagamento?: FormaPagamento|string|null, ja_pago?: bool}>,
     * }  $dados
     * @return Collection<int, Lancamento>
     */
    public function gerarContaPagar(Compra $compra, array $dados = []): Collection
    {
        return DB::transaction(function () use ($compra, $dados) {
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

            // Agrega o valor por plano de despesa. Chave '' = produtos sem plano.
            $porPlano = [];
            foreach ($compra->itens as $item) {
                $plano = $item->insumo?->planoDespesa;
                $chave = $plano?->id ?? '';
                $porPlano[$chave] ??= [
                    'plano_despesa_id' => $plano?->id,
                    'comportamento' => $plano?->comportamento,
                    'valor' => 0.0,
                ];
                // Contribuição do item ao título: produtos + rateio (frete/outros − desconto).
                $porPlano[$chave]['valor'] += $item->valorProdutos() + (float) $item->ci_valor_rateio;
            }

            // Cabeçalho: se houver exatamente um plano (sem remanescente), usa-o no título
            // (o hook do model faz o snapshot do comportamento). Caso contrário, fica nulo
            // e a classificação vive nas linhas de rateio. É o mesmo para todas as parcelas.
            $planosClassificados = array_values(array_filter($porPlano, fn ($l) => $l['plano_despesa_id'] !== null));
            $planoUnico = (count($planosClassificados) === 1 && ! array_key_exists('', $porPlano))
                ? $planosClassificados[0]['plano_despesa_id']
                : null;

            $prazoPagamentoId = $dados['prazo_pagamento_id'] ?? null;
            $total = count($parcelas);
            $valorTotalCompra = (float) $compra->compra_valor_total;

            $lancamentos = new Collection;

            foreach ($parcelas as $indice => $parcela) {
                $valorParcela = round((float) $parcela['valor'], 2);

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

        FornecedorProduto::updateOrCreate(
            [
                'fp_prestador_id' => $compra->compra_prestador_id,
                'fp_codigo_fornecedor' => $item->ci_codigo_fornecedor,
            ],
            [
                'fp_produto_id' => $item->ci_produto_id,
                'fp_descricao_fornecedor' => $item->ci_descricao_fornecedor,
                'fp_unidade_compra' => $item->ci_unidade_compra,
                'fp_fator_conversao' => $item->ci_fator_conversao,
            ],
        );
    }
}
