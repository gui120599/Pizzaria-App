<?php

namespace App\Services;

use App\Enums\CompraStatusEnum;
use App\Enums\MovimentacaoOrigemEnum;
use App\Models\Compra;
use App\Models\CompraDevolucao;
use App\Models\CompraDevolucaoItem;
use App\Models\CompraItem;
use App\Models\PrestadorCredito;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Devolução de produtos de uma compra já confirmada ao fornecedor. Baixa o
 * estoque (mesma lógica valorada de EstoqueService::registrarSaida) e gera
 * um crédito com o fornecedor (PrestadorCredito) em vez de reembolso em
 * dinheiro — o crédito é abatido automaticamente da próxima conta a pagar
 * desse fornecedor (ver CompraService::gerarContaPagar). Nunca abate a
 * própria compra que originou a devolução: o crédito só existe depois que a
 * devolução é registrada, e a conta a pagar dessa mesma compra (se ainda não
 * gerada) segue seu fluxo normal via ConfirmarCompraAction.
 */
class CompraDevolucaoService
{
    public function __construct(private EstoqueService $estoque) {}

    /**
     * @param  array<int, array{compra_item_id: int, quantidade: float}>  $itens
     */
    public function registrar(Compra $compra, array $itens, ?string $motivo = null): CompraDevolucao
    {
        return DB::transaction(function () use ($compra, $itens, $motivo) {
            if ($compra->compra_status !== CompraStatusEnum::CONFIRMADA) {
                throw ValidationException::withMessages(['compra' => 'Só é possível devolver itens de uma compra confirmada.']);
            }

            if ($itens === []) {
                throw ValidationException::withMessages(['itens' => 'Informe ao menos um item a devolver.']);
            }

            $devolucao = CompraDevolucao::create([
                'compra_id' => $compra->id,
                'motivo' => $motivo,
                'valor_total' => 0,
                'user_id' => Auth::id(),
            ]);

            $valorTotal = 0.0;

            foreach ($itens as $linha) {
                $quantidade = (float) ($linha['quantidade'] ?? 0);
                if ($quantidade <= 0) {
                    continue;
                }

                $compraItem = CompraItem::where('id', $linha['compra_item_id'])
                    ->where('ci_compra_id', $compra->id)
                    ->firstOrFail();

                $jaDevolvida = CompraDevolucaoItem::where('compra_item_id', $compraItem->id)->sum('quantidade');
                $disponivel = (float) $compraItem->ci_quantidade_compra - (float) $jaDevolvida;

                if ($quantidade > $disponivel + 0.0001) {
                    throw ValidationException::withMessages([
                        'itens' => "Quantidade a devolver do item '{$compraItem->ci_descricao_fornecedor}' excede o disponível ({$disponivel}).",
                    ]);
                }

                $valorUnitario = $compraItem->custoUnitarioEstoque();
                $valorItem = round($quantidade * $valorUnitario, 2);

                CompraDevolucaoItem::create([
                    'compra_devolucao_id' => $devolucao->id,
                    'compra_item_id' => $compraItem->id,
                    'quantidade' => $quantidade,
                    'valor_unitario' => $valorUnitario,
                    'valor_total' => $valorItem,
                ]);

                $quantidadeEstoque = $quantidade * ((float) $compraItem->ci_fator_conversao ?: 1);

                $this->estoque->registrarSaida(
                    $compraItem->insumo,
                    $quantidadeEstoque,
                    MovimentacaoOrigemEnum::DEVOLUCAO_COMPRA,
                    [
                        'motivo' => $motivo,
                        'referencia' => $devolucao,
                        'user_id' => Auth::id(),
                    ],
                );

                $valorTotal += $valorItem;
            }

            if ($valorTotal <= 0) {
                throw ValidationException::withMessages(['itens' => 'Nenhuma quantidade válida informada para devolução.']);
            }

            $devolucao->update(['valor_total' => round($valorTotal, 2)]);

            if ($compra->compra_prestador_id) {
                PrestadorCredito::create([
                    'prestador_id' => $compra->compra_prestador_id,
                    'origem_tipo' => 'devolucao_compra',
                    'origem_id' => $devolucao->id,
                    'valor' => round($valorTotal, 2),
                ]);
            }

            return $devolucao->refresh();
        });
    }
}
