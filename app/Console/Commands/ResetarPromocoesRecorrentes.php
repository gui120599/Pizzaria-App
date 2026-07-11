<?php

namespace App\Console\Commands;

use App\Models\PromocaoRelampago;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Zera o contador (pool + sublimite por produto) das promoções relâmpago
 * recorrentes assim que uma nova ocorrência começa (dia da semana certo, já
 * passou da hora de início, ainda não resetou hoje). Pensado para rodar a
 * cada minuto via schedule — ver App\Console\Kernel.
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

        if ($resetadas === 0) {
            $this->line('Nenhuma promoção recorrente para resetar agora.');
        }

        return self::SUCCESS;
    }
}
