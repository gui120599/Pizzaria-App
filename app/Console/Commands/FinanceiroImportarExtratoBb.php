<?php

namespace App\Console\Commands;

use App\Exceptions\FinanceiroException;
use App\Models\ContaFinanceira;
use App\Services\Financeiro\BancoBrasil\ImportacaoExtratoService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Importa o extrato do Banco do Brasil das contas com integração ativa
 * (D-3 até hoje por padrão) e concilia os boletos. Ver ImportacaoExtratoService.
 */
class FinanceiroImportarExtratoBb extends Command
{
    protected $signature = 'financeiro:importar-extrato-bb {--de= : Data inicial (AAAA-MM-DD)} {--ate= : Data final (AAAA-MM-DD)}';

    protected $description = 'Importa o extrato do Banco do Brasil pela API e concilia os boletos pagos';

    public function handle(ImportacaoExtratoService $importacao): int
    {
        $contas = ContaFinanceira::query()->where('integracao', ImportacaoExtratoService::INTEGRACAO)->get();

        if ($contas->isEmpty()) {
            $this->line('Nenhuma conta com integração do Banco do Brasil — nada a fazer.');

            return self::SUCCESS;
        }

        $de = $this->option('de') ? Carbon::parse((string) $this->option('de')) : null;
        $ate = $this->option('ate') ? Carbon::parse((string) $this->option('ate')) : null;
        $falhou = false;

        foreach ($contas as $conta) {
            try {
                $resultado = $importacao->importar($conta, $de, $ate);
                $this->info("{$conta->nome}: {$resultado['novas']} linha(s) nova(s), {$resultado['existentes']} já importada(s), {$resultado['conciliadas']} boleto(s) conciliado(s).");
            } catch (FinanceiroException $e) {
                $this->error("{$conta->nome}: {$e->getMessage()}");
                $falhou = true;
            }
        }

        return $falhou ? self::FAILURE : self::SUCCESS;
    }
}
