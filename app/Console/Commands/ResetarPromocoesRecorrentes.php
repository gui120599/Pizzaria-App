<?php

namespace App\Console\Commands;

use App\Models\PromocaoAdicional;
use App\Models\PromocaoCombo;
use App\Models\PromocaoRelampago;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Zera o contador (pool + sublimite por produto) das promoções relâmpago
 * recorrentes assim que uma nova ocorrência começa (dia da semana certo, já
 * passou da hora de início, ainda não resetou hoje). Pensado para rodar a
 * cada minuto via schedule — ver App\Console\Kernel.
 *
 * Também zera o teto de brindes (promoad_qtd_vendida) das campanhas de
 * promoção adicional recorrentes com quantidade limitada. O saldo por oferta
 * (pao_qtd_vendida) continua como sempre foi: não reseta.
 *
 * Na Promoção Combo recorrente com quantidade limitada não há contador: o
 * reset só marca promocombo_ultimo_reset_em, e os combos usados passam a
 * contar a partir dele (PromocaoCombo::combosUsados).
 */
class ResetarPromocoesRecorrentes extends Command
{
    protected $signature = 'promocoes:resetar-recorrentes';

    protected $description = 'Zera o saldo das promoções relâmpago recorrentes no início de cada ocorrência';

    public function handle(): int
    {
        $agora = now();
        $resetadas = 0;

        $promocoes = PromocaoRelampago::where('promocao_ativa', true)
            ->where('promocao_recorrente', true)
            ->get();

        foreach ($promocoes as $promocao) {
            if (! $promocao->deveResetarAgora($agora)) {
                continue;
            }

            DB::transaction(function () use ($promocao, $agora) {
                $promocao->update([
                    'promocao_qtd_vendida' => 0,
                    'promocao_ultimo_reset_em' => $agora,
                ]);

                $promocao->promocaoProdutos()->update(['prp_qtd_vendida' => 0]);
            });

            $resetadas++;
            $this->info("Promoção #{$promocao->id} ({$promocao->promocao_nome}) resetada para nova ocorrência.");
        }

        $campanhas = PromocaoAdicional::where('promoad_ativa', true)
            ->where('promoad_recorrente', true)
            ->whereNotNull('promoad_qtd_total')
            ->get();

        foreach ($campanhas as $campanha) {
            if (! $campanha->deveResetarAgora($agora)) {
                continue;
            }

            $campanha->update([
                'promoad_qtd_vendida' => 0,
                'promoad_ultimo_reset_em' => $agora,
            ]);

            $resetadas++;
            $this->info("Promoção adicional #{$campanha->id} ({$campanha->promoad_nome}) resetada para nova ocorrência.");
        }

        $combos = PromocaoCombo::where('promocombo_ativa', true)
            ->where('promocombo_recorrente', true)
            ->whereNotNull('promocombo_qtd_total')
            ->get();

        foreach ($combos as $combo) {
            if (! $combo->deveResetarAgora($agora)) {
                continue;
            }

            $combo->update(['promocombo_ultimo_reset_em' => $agora]);

            $resetadas++;
            $this->info("Promoção combo #{$combo->id} ({$combo->promocombo_nome}) resetada para nova ocorrência.");
        }

        if ($resetadas === 0) {
            $this->line('Nenhuma promoção recorrente para resetar agora.');
        }

        return self::SUCCESS;
    }
}
