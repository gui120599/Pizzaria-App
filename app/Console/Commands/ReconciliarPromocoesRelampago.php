<?php

namespace App\Console\Commands;

use App\Models\PromocaoConsumo;
use App\Models\PromocaoRelampago;
use App\Models\PromocaoRelampagoProduto;
use Illuminate\Console\Command;

/**
 * Recalcula promocao_qtd_vendida/prp_qtd_vendida a partir da soma dos
 * consumos ativos no ledger (promocao_consumos), que é a fonte da verdade.
 * Rede de segurança contra drift: fluxo de cancelamento/remoção esquecido de
 * chamar estornarItem/estornarPedido, tamperagem manual no banco, etc.
 */
class ReconciliarPromocoesRelampago extends Command
{
    protected $signature = 'promocoes:reconciliar
                            {--aplicar : Corrige as divergências encontradas (por padrão só reporta)}';

    protected $description = 'Recalcula o saldo vendido das promoções relâmpago a partir do ledger de consumo';

    public function handle(): int
    {
        $aplicar = (bool) $this->option('aplicar');
        $divergencias = 0;

        foreach (PromocaoRelampago::all() as $promocao) {
            $consumos = PromocaoConsumo::where('consumo_promocao_id', $promocao->id)
                ->whereNull('consumo_revertido_em');

            // Promoção recorrente já resetou pelo menos uma vez: consumos de
            // ocorrências anteriores ao último reset não contam mais no saldo
            // atual (o contador foi zerado quando a ocorrência virou).
            if ($promocao->promocao_recorrente && $promocao->promocao_ultimo_reset_em) {
                $consumos->where('created_at', '>=', $promocao->promocao_ultimo_reset_em);
            }

            $real = (float) $consumos->sum('consumo_quantidade');
            $registrado = (float) $promocao->promocao_qtd_vendida;

            if (abs($registrado - $real) > 0.001) {
                $divergencias++;
                $this->warn("Promoção #{$promocao->id} ({$promocao->promocao_nome}): registrado={$registrado} real={$real}");

                if ($aplicar) {
                    $promocao->update(['promocao_qtd_vendida' => $real]);
                }
            }
        }

        foreach (PromocaoRelampagoProduto::with('promocao')->get() as $prp) {
            $consumos = PromocaoConsumo::where('consumo_promocao_produto_id', $prp->id)
                ->whereNull('consumo_revertido_em');

            if ($prp->promocao?->promocao_recorrente && $prp->promocao->promocao_ultimo_reset_em) {
                $consumos->where('created_at', '>=', $prp->promocao->promocao_ultimo_reset_em);
            }

            $real = (float) $consumos->sum('consumo_quantidade');
            $registrado = (float) $prp->prp_qtd_vendida;

            if (abs($registrado - $real) > 0.001) {
                $divergencias++;
                $this->warn("Produto #{$prp->prp_produto_id} da promoção #{$prp->prp_promocao_id}: registrado={$registrado} real={$real}");

                if ($aplicar) {
                    $prp->update(['prp_qtd_vendida' => $real]);
                }
            }
        }

        if ($divergencias === 0) {
            $this->info('Nenhuma divergência encontrada.');

            return self::SUCCESS;
        }

        $this->line("{$divergencias} divergência(s) encontrada(s).");

        if (! $aplicar) {
            $this->line('Rode novamente com --aplicar para corrigir.');
        }

        return self::SUCCESS;
    }
}
