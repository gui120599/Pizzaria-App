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
     * Gera um título a pagar (Lançamento) a partir de uma compra confirmada.
     *
     * O valor total do título é rateado entre planos de despesa conforme a classificação
     * de cada produto (produto_plano_despesa_id): insumos e produtos de revenda podem cair
     * em planos diferentes, então o título tem N linhas de despesa (soma = valor do título).
     * Produtos sem plano vão para uma linha remanescente "sem classificação" (plano nulo).
     *
     * @param  array{vencimento?: string|\DateTimeInterface|null, forma_pagamento?: FormaPagamento|string|null}  $dados
     */
    public function gerarContaPagar(Compra $compra, array $dados = []): Lancamento
    {
        return DB::transaction(function () use ($compra, $dados) {
            $compra->loadMissing(['itens.insumo.planoDespesa', 'prestador']);

            if ($compra->compra_status !== CompraStatusEnum::CONFIRMADA) {
                throw ValidationException::withMessages(['compra' => 'A compra precisa estar confirmada para gerar a conta a pagar.']);
            }

            // Idempotência: uma compra gera no máximo uma conta a pagar.
            if ($compra->contaPagar()->exists()) {
                throw ValidationException::withMessages(['compra' => 'Esta compra já possui uma conta a pagar.']);
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
            // e a classificação vive nas linhas de rateio.
            $planosClassificados = array_values(array_filter($porPlano, fn ($l) => $l['plano_despesa_id'] !== null));
            $planoUnico = (count($planosClassificados) === 1 && ! array_key_exists('', $porPlano))
                ? $planosClassificados[0]['plano_despesa_id']
                : null;

            $formaPagamento = $dados['forma_pagamento'] ?? null;
            if (is_string($formaPagamento)) {
                $formaPagamento = FormaPagamento::tryFrom($formaPagamento);
            }

            $lancamento = new Lancamento([
                'tipo' => TipoLancamento::Pagar,
                'compra_id' => $compra->id,
                'plano_despesa_id' => $planoUnico,
                'favorecido_id' => $compra->compra_prestador_id,
                'descricao' => $this->descricaoContaPagar($compra),
                'numero_documento' => $compra->compra_numero ?: $compra->compra_chave_nfe,
                'valor' => $compra->compra_valor_total,
                'vencimento' => $dados['vencimento']
                    ?? $compra->compra_data_entrada
                    ?? $compra->compra_data_emissao
                    ?? now()->toDateString(),
                'status' => StatusLancamento::Pendente,
                'forma_pagamento' => $formaPagamento,
            ]);
            $lancamento->save();

            $this->gravarRateio($lancamento, $porPlano);

            return $lancamento->refresh();
        });
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

    /** Descrição legível do título a pagar gerado pela compra. */
    private function descricaoContaPagar(Compra $compra): string
    {
        $numero = $compra->compra_numero ? "Compra Nº {$compra->compra_numero}" : "Compra #{$compra->id}";
        $fornecedor = $compra->prestador?->nome_exibicao;

        return $fornecedor ? "{$numero} - {$fornecedor}" : $numero;
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
