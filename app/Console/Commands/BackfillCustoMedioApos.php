<?php

namespace App\Console\Commands;

use App\Enums\MovimentacaoTipoEnum;
use App\Models\MovimentacaoProduto;
use App\Models\Produto;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Preenche mov_custo_medio_apos no histórico já existente de movimentações,
 * replicando o mesmo cálculo de custo médio ponderado (WAC) usado em
 * EstoqueService::registrarEntrada — daqui pra frente, esse campo já é
 * gravado na hora por EstoqueService/CorrecaoEstoqueService; este comando é
 * só para os dados anteriores à coluna existir. Rodar uma vez, como passo de
 * deploy, depois de aplicar a migration.
 */
class BackfillCustoMedioApos extends Command
{
    protected $signature = 'estoque:backfill-custo-medio';

    protected $description = 'Preenche mov_custo_medio_apos no histórico de movimentações já existente';

    public function handle(): int
    {
        $produtoIds = MovimentacaoProduto::query()->distinct()->pluck('mov_produto_id');

        if ($produtoIds->isEmpty()) {
            $this->warn('Nenhuma movimentação encontrada.');

            return self::SUCCESS;
        }

        $this->withProgressBar($produtoIds, function (int $produtoId) {
            $this->recalcularProduto($produtoId);
        });

        $this->newLine();
        $this->info("✓ {$produtoIds->count()} produto(s) processados.");

        return self::SUCCESS;
    }

    private function recalcularProduto(int $produtoId): void
    {
        DB::transaction(function () use ($produtoId) {
            if (! Produto::where('id', $produtoId)->lockForUpdate()->exists()) {
                return;
            }

            $movimentacoes = MovimentacaoProduto::where('mov_produto_id', $produtoId)
                ->orderBy('mov_data')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $saldo = 0.0;
            $medio = 0.0;
            $medioInicializado = false;

            foreach ($movimentacoes as $mov) {
                if ($mov->mov_tipo === MovimentacaoTipoEnum::ENTRADA) {
                    $qtd = (float) $mov->mov_quantidade;
                    $custo = (float) $mov->mov_custo_unitario;
                    $novoSaldo = $saldo + $qtd;
                    $medio = $novoSaldo > 0 ? (($saldo * $medio) + ($qtd * $custo)) / $novoSaldo : $custo;
                    $saldo = $novoSaldo;
                    $medioInicializado = true;
                } else {
                    // Histórico começa direto numa saída, sem entrada antes
                    // (produto cujo custo médio veio de outra fonte, nunca de
                    // uma entrada real) — usa o próprio custo gravado nela.
                    if (! $medioInicializado) {
                        $medio = (float) $mov->mov_custo_unitario;
                        $medioInicializado = true;
                    }
                    $saldo -= (float) $mov->mov_quantidade;
                }

                $mov->forceFill(['mov_custo_medio_apos' => round($medio, 8)])->save();
            }
        });
    }
}
