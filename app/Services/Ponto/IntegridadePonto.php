<?php

namespace App\Services\Ponto;

use App\Models\PontoMarcacao;
use App\Models\PontoSequencia;

/**
 * Confere a cadeia de marcações: NSR contínuo, hash_anterior igual ao hash
 * da marcação anterior e hash recalculado igual ao gravado. Qualquer
 * alteração direta no banco quebra a cadeia a partir do ponto alterado.
 */
class IntegridadePonto
{
    /** @return array<int, array{nsr: int|null, problema: string}> */
    public function verificar(): array
    {
        $problemas = [];
        $nsrEsperado = 1;
        $hashAnterior = null;

        foreach (PontoMarcacao::query()->lazyById(500, 'nsr') as $marcacao) {
            if ($marcacao->nsr !== $nsrEsperado) {
                $problemas[] = ['nsr' => $marcacao->nsr, 'problema' => "NSR fora de sequência (esperado {$nsrEsperado})."];
            }

            if ($marcacao->hash_anterior !== $hashAnterior) {
                $problemas[] = ['nsr' => $marcacao->nsr, 'problema' => 'hash_anterior não confere com a marcação anterior.'];
            }

            if (! $marcacao->hashConfere()) {
                $problemas[] = ['nsr' => $marcacao->nsr, 'problema' => 'Hash não confere: os dados da marcação foram alterados.'];
            }

            $nsrEsperado = $marcacao->nsr + 1;
            $hashAnterior = $marcacao->hash;
        }

        $sequencia = PontoSequencia::query()->where('nome', PontoSequencia::MARCACOES)->first();

        $ultimoNsr = $nsrEsperado - 1;

        if ($sequencia !== null && $sequencia->ultimo_nsr !== $ultimoNsr) {
            $problemas[] = ['nsr' => null, 'problema' => "A sequência registra o NSR {$sequencia->ultimo_nsr}, mas a última marcação é a {$ultimoNsr}."];
        }

        return $problemas;
    }
}
