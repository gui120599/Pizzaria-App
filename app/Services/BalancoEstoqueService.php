<?php

namespace App\Services;

use App\Enums\MovimentacaoOrigemEnum;
use App\Models\MovimentacaoBalanco;
use App\Models\Produto;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BalancoEstoqueService
{
    public function __construct(private readonly EstoqueService $estoqueService) {}

    /**
     * Realiza o balanço físico de um produto.
     *
     * Compara o saldo do sistema com a quantidade física informada, registra
     * o balanço em MovimentacaoBalanco e, se houver diferença, delega o ajuste
     * ao EstoqueService (que atualiza o saldo e cria a MovimentacaoProduto com
     * origem BALANCO e referência morphic apontando para o balanço).
     *
     * Retorna null quando não há diferença (sem movimentação criada).
     */
    public function realizarBalanco(
        Produto $produto,
        float $quantidadeFisica,
        ?string $observacao = null,
    ): ?MovimentacaoBalanco {
        try {
            $balanco = DB::transaction(function () use ($produto, $quantidadeFisica, $observacao) {
                $produto = Produto::lockForUpdate()->findOrFail($produto->id);

                $saldoSistema = (float) $produto->produto_saldo_estoque;
                $diferenca    = round($quantidadeFisica - $saldoSistema, 3);

                if ($diferenca === 0.0) {
                    return null;
                }

                $tipo = $diferenca > 0
                    ? \App\Enums\MovimentacaoTipoEnum::ENTRADA
                    : \App\Enums\MovimentacaoTipoEnum::SAIDA;

                $balanco = MovimentacaoBalanco::create([
                    'mbal_produto_id'         => $produto->id,
                    'mbal_usuario_id'         => Auth::id(),
                    'mbal_quantidade_sistema' => round($saldoSistema, 3),
                    'mbal_quantidade_balanco' => round($quantidadeFisica, 3),
                    'mbal_quantidade_ajuste'  => round(abs($diferenca), 3),
                    'mbal_tipo_movimentacao'  => $tipo,
                    'mbal_observacao'         => $observacao,
                    'mbal_data_balanco'       => now(),
                ]);

                $this->estoqueService->registrarAjuste($produto, $quantidadeFisica, [
                    'referencia' => $balanco,
                    'motivo'     => "Balanço físico — #{$balanco->id}",
                    'origem'     => MovimentacaoOrigemEnum::BALANCO,
                ]);

                return $balanco;
            });

            if ($balanco) {
                Log::info('Balanço físico realizado.', [
                    'produto_id' => $produto->id,
                    'balanco_id' => $balanco->id,
                    'ajuste'     => (string) $balanco->mbal_quantidade_ajuste,
                    'tipo'       => $balanco->mbal_tipo_movimentacao->value,
                ]);
            }

            return $balanco;
        } catch (\Throwable $e) {
            Log::error('Falha ao realizar balanço físico.', [
                'produto_id' => $produto->id,
                'erro'       => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
