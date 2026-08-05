<?php

namespace App\Console\Commands;

use App\Models\Lancamento;
use App\Services\ContratoService;
use Illuminate\Console\Command;

/**
 * Gera o lançamento mensal (título a pagar) de cada Contrato ativo/vigente.
 * Pensado para rodar diariamente (não só no dia 1) via schedule — ver
 * App\Console\Kernel. A idempotência por competência (unique ['contrato_id',
 * 'competencia'] em lancamentos) garante que rodar mais de uma vez no mês, ou
 * recuperar uma falha pontual do schedule, nunca duplica o lançamento.
 */
class ContratosGerarLancamentosMensais extends Command
{
    protected $signature = 'contratos:gerar-lancamentos';

    protected $description = 'Gera o lançamento mensal de cada contrato ativo/vigente no contas a pagar';

    public function handle(ContratoService $service): int
    {
        $encerrados = $service->encerrarVencidos();

        if ($encerrados > 0) {
            $this->info("{$encerrados} contrato(s) encerrado(s) automaticamente por fim de vigência.");
        }

        $gerados = $service->gerarLancamentosDoMes(now());

        if ($gerados === []) {
            $this->line('Nenhum lançamento novo a gerar para a competência atual.');

            return self::SUCCESS;
        }

        $this->table(
            ['Contrato', 'Descrição', 'Valor', 'Vencimento'],
            collect($gerados)->map(fn (Lancamento $l): array => [
                $l->contrato_id,
                $l->descricao,
                'R$ '.number_format((float) $l->valor, 2, ',', '.'),
                $l->vencimento->format('d/m/Y'),
            ])->all(),
        );

        return self::SUCCESS;
    }
}
