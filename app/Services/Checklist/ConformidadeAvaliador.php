<?php

namespace App\Services\Checklist;

use App\Enums\Checklist\TipoResposta;
use App\Models\ChecklistExecucaoItem;

/**
 * Decide se a resposta de um item é conforme, a partir das regras congeladas
 * no snapshot (`config`) do item. null = sem resposta / não avaliável.
 */
class ConformidadeAvaliador
{
    public function avaliar(ChecklistExecucaoItem $item): ?bool
    {
        if ($item->tipo_resposta === TipoResposta::Foto) {
            return $item->anexos()->exists() ? true : null;
        }

        if (! $item->foiRespondido()) {
            return null;
        }

        return match ($item->tipo_resposta) {
            TipoResposta::Check => $item->valor_booleano === true,
            TipoResposta::SimNao => $this->avaliarSimNao($item),
            TipoResposta::Numero, TipoResposta::Temperatura => $this->avaliarFaixa($item),
            TipoResposta::Selecao => $this->avaliarSelecao($item),
            TipoResposta::Texto => trim((string) $item->valor_texto) !== '' ? true : null,
        };
    }

    private function avaliarSimNao(ChecklistExecucaoItem $item): ?bool
    {
        if ($item->valor_booleano === null) {
            return null;
        }

        $esperada = $item->regra('resposta_esperada');

        if ($esperada === null) {
            return true;
        }

        return $item->valor_booleano === ($esperada === 'sim');
    }

    /** Limites nulos são abertos. */
    private function avaliarFaixa(ChecklistExecucaoItem $item): ?bool
    {
        if ($item->valor_numero === null) {
            return null;
        }

        $valor = (float) $item->valor_numero;
        $minimo = $item->regra('valor_minimo');
        $maximo = $item->regra('valor_maximo');

        return ($minimo === null || $valor >= (float) $minimo)
            && ($maximo === null || $valor <= (float) $maximo);
    }

    private function avaliarSelecao(ChecklistExecucaoItem $item): ?bool
    {
        $opcao = collect($item->regra('opcoes', []))->firstWhere('valor', $item->valor_opcao);

        return $opcao === null ? null : (bool) ($opcao['conforme'] ?? false);
    }
}
