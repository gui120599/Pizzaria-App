<?php

namespace App\Console\Commands;

use App\Support\Telefone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sem opções: (re)calcula só a chave cliente_celular_normalizado de todos os
 * clientes (inclusive na lixeira). Não mexe no celular gravado — é o que a
 * migration da coluna roda, e pode ser repetido à vontade.
 *
 * --canonizar: mostra quantos celulares antigos (com máscara, +55, zero do
 * DDD, sem o nono dígito) seriam regravados na forma canônica, sem gravar.
 * --canonizar --aplicar: regrava. É a etapa opcional de limpeza dos dados
 * históricos; só rodar em produção depois de conferir o relatório. Número não
 * reconhecido nunca é alterado.
 */
class NormalizarTelefonesClientes extends Command
{
    protected $signature = 'clientes:normalizar-telefones
        {--canonizar : Relata os celulares antigos que seriam regravados na forma canônica}
        {--aplicar : Com --canonizar, grava a forma canônica}';

    protected $description = 'Recalcula a chave de busca do celular dos clientes e, opcionalmente, regrava celulares antigos na forma canônica';

    public function handle(): int
    {
        $chavesAtualizadas = 0;
        $aCanonizar = [];
        $naoReconhecidos = 0;

        DB::table('clientes')
            ->select(['id', 'cliente_celular', 'cliente_celular_normalizado'])
            ->orderBy('id')
            ->chunkById(500, function ($clientes) use (&$chavesAtualizadas, &$aCanonizar, &$naoReconhecidos) {
                foreach ($clientes as $cliente) {
                    $chave = Telefone::normalizar($cliente->cliente_celular);

                    if ($chave !== $cliente->cliente_celular_normalizado) {
                        DB::table('clientes')->where('id', $cliente->id)->update(['cliente_celular_normalizado' => $chave]);
                        $chavesAtualizadas++;
                    }

                    $canonico = Telefone::canonico($cliente->cliente_celular);

                    if ($canonico === null && filled($cliente->cliente_celular)) {
                        $naoReconhecidos++;
                    } elseif ($canonico !== null && $canonico !== $cliente->cliente_celular) {
                        $aCanonizar[$cliente->id] = [$cliente->cliente_celular, $canonico];
                    }
                }
            });

        $this->info("Chave de busca atualizada em {$chavesAtualizadas} cliente(s).");
        $this->line("{$naoReconhecidos} celular(es) não reconhecido(s) como número brasileiro (ficam como estão).");

        if (! $this->option('canonizar')) {
            return self::SUCCESS;
        }

        $this->line(count($aCanonizar).' celular(es) antigo(s) fora da forma canônica.');
        $this->table(
            ['id', 'gravado', 'canônico'],
            collect($aCanonizar)->take(20)->map(fn (array $par, int $id): array => [$id, ...$par])->values()->all(),
        );

        if (! $this->option('aplicar')) {
            $this->warn('Nada foi regravado. Rode com --canonizar --aplicar para gravar.');

            return self::SUCCESS;
        }

        foreach ($aCanonizar as $id => [, $canonico]) {
            DB::table('clientes')->where('id', $id)->update(['cliente_celular' => $canonico]);
        }

        Log::info('clientes.telefones_canonizados', ['total' => count($aCanonizar), 'antes' => $aCanonizar]);
        $this->info(count($aCanonizar).' celular(es) regravado(s) na forma canônica (valores anteriores no log).');

        return self::SUCCESS;
    }
}
