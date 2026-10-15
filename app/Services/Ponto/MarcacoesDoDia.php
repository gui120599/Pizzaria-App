<?php

namespace App\Services\Ponto;

use App\Enums\Ponto\TipoAjuste;
use App\Models\Colaborador;
use App\Models\PontoAjuste;
use App\Models\PontoMarcacao;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Fonte única das marcações de um dia para apuração, espelho e PDF:
 * originais − desconsiderações aprovadas + inclusões aprovadas, em ordem
 * cronológica. Nunca altera ponto_marcacoes.
 */
class MarcacoesDoDia
{
    /** @return Collection<int, MarcacaoEfetiva> */
    public function efetivas(Colaborador $colaborador, CarbonInterface $dia): Collection
    {
        $ajustes = $this->ajustesAprovados($colaborador, $dia);

        $desconsideradas = $ajustes
            ->where('tipo', TipoAjuste::Desconsideracao)
            ->pluck('ponto_marcacao_id')
            ->filter()
            ->all();

        $originais = $this->originais($colaborador, $dia)
            ->reject(fn (PontoMarcacao $marcacao): bool => in_array($marcacao->id, $desconsideradas, true))
            ->map(fn (PontoMarcacao $marcacao): MarcacaoEfetiva => MarcacaoEfetiva::original($marcacao));

        $incluidas = $ajustes
            ->where('tipo', TipoAjuste::Inclusao)
            ->map(fn (PontoAjuste $ajuste): MarcacaoEfetiva => MarcacaoEfetiva::incluida($ajuste));

        return $originais->concat($incluidas)
            ->sortBy(fn (MarcacaoEfetiva $efetiva): int => $efetiva->momento->getTimestamp())
            ->values();
    }

    /**
     * Originais desconsideradas por ajuste aprovado (o espelho as mostra riscadas).
     *
     * @return Collection<int, PontoMarcacao>
     */
    public function desconsideradas(Colaborador $colaborador, CarbonInterface $dia): Collection
    {
        $ids = $this->ajustesAprovados($colaborador, $dia)
            ->where('tipo', TipoAjuste::Desconsideracao)
            ->pluck('ponto_marcacao_id')
            ->filter()
            ->all();

        return $this->originais($colaborador, $dia)->whereIn('id', $ids)->values();
    }

    /** @return Collection<int, PontoMarcacao> */
    public function originais(Colaborador $colaborador, CarbonInterface $dia): Collection
    {
        return PontoMarcacao::query()
            ->where('colaborador_id', $colaborador->id)
            ->whereDate('data_referencia', $dia->toDateString())
            ->orderBy('registrada_em')
            ->orderBy('nsr')
            ->get();
    }

    /** @return Collection<int, PontoAjuste> */
    public function ajustesAprovados(Colaborador $colaborador, CarbonInterface $dia): Collection
    {
        return PontoAjuste::query()
            ->aprovados()
            ->where('colaborador_id', $colaborador->id)
            ->whereDate('data_referencia', $dia->toDateString())
            ->orderBy('id')
            ->get();
    }
}
