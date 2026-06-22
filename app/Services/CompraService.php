<?php

namespace App\Services;

use App\Enums\CompraStatusEnum;
use App\Enums\MovimentacaoOrigemEnum;
use App\Models\Compra;
use App\Models\CompraItem;
use App\Models\FornecedorProduto;
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
                        'centro_custo_id' => $compra->compra_centro_custo_id,
                        'referencia' => $compra,
                        'data' => $compra->compra_data_entrada,
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
