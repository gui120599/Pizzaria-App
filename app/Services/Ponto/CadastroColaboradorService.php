<?php

namespace App\Services\Ponto;

use App\Exceptions\PontoException;
use App\Models\Colaborador;
use App\Models\ColaboradorEscala;
use App\Models\ColaboradorRemuneracao;
use App\Models\Escala;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Vigências de remuneração e escala (no máximo uma em aberto por
 * colaborador: a nova encerra a anterior na véspera) e desligamento.
 */
class CadastroColaboradorService
{
    /**
     * @param  array<string, mixed>  $dados  colunas de colaborador_remuneracoes (sem colaborador_id/vigências)
     *
     * @throws PontoException
     */
    public function novaRemuneracao(Colaborador $colaborador, array $dados, CarbonInterface $desde): ColaboradorRemuneracao
    {
        return DB::transaction(function () use ($colaborador, $dados, $desde): ColaboradorRemuneracao {
            $desde = $this->encerrarVigenciaAberta($colaborador, $colaborador->remuneracoes(), $desde);

            return $colaborador->remuneracoes()->create([...$dados, 'vigente_desde' => $desde->toDateString(), 'vigente_ate' => null]);
        });
    }

    /** @throws PontoException */
    public function novaEscala(Colaborador $colaborador, Escala $escala, CarbonInterface $desde): ColaboradorEscala
    {
        return DB::transaction(function () use ($colaborador, $escala, $desde): ColaboradorEscala {
            $desde = $this->encerrarVigenciaAberta($colaborador, $colaborador->escalas(), $desde);

            return $colaborador->escalas()->create(['escala_id' => $escala->id, 'vigente_desde' => $desde->toDateString(), 'vigente_ate' => null]);
        });
    }

    /**
     * Grava a data de desligamento e encerra as vigências nela; vigências que
     * começariam depois são excluídas.
     *
     * @throws PontoException
     */
    public function desligar(Colaborador $colaborador, CarbonInterface $data): void
    {
        $data = CarbonImmutable::instance($data)->startOfDay();

        if ($data->lt($colaborador->data_admissao)) {
            throw new PontoException('O desligamento não pode ser antes da admissão.');
        }

        DB::transaction(function () use ($colaborador, $data): void {
            foreach ([$colaborador->remuneracoes(), $colaborador->escalas()] as $vigencias) {
                foreach ((clone $vigencias)->get() as $vigencia) {
                    if ($vigencia->vigente_desde->gt($data)) {
                        $vigencia->delete();
                    } elseif ($vigencia->vigente_ate === null || $vigencia->vigente_ate->gt($data)) {
                        $vigencia->update(['vigente_ate' => $data->toDateString()]);
                    }
                }
            }

            $colaborador->update(['data_desligamento' => $data->toDateString()]);
        });
    }

    /**
     * @param  HasMany<Model>  $vigencias
     *
     * @throws PontoException
     */
    private function encerrarVigenciaAberta(Colaborador $colaborador, HasMany $vigencias, CarbonInterface $desde): CarbonImmutable
    {
        $desde = CarbonImmutable::instance($desde)->startOfDay();

        if ($desde->lt($colaborador->data_admissao)) {
            throw new PontoException('A vigência não pode começar antes da admissão.');
        }

        if ($colaborador->data_desligamento !== null && $desde->gt($colaborador->data_desligamento)) {
            throw new PontoException('O colaborador já está desligado nesta data.');
        }

        $sobrepoe = (clone $vigencias)
            ->where(fn ($query) => $query
                ->whereDate('vigente_desde', '>=', $desde->toDateString())
                ->orWhereDate('vigente_ate', '>=', $desde->toDateString()))
            ->exists();

        if ($sobrepoe) {
            throw new PontoException('Já existe vigência começando nesta data ou depois. Para corrigir, exclua a vigência mais nova.');
        }

        (clone $vigencias)->whereNull('vigente_ate')->get()
            ->each(fn (Model $aberta) => $aberta->update(['vigente_ate' => $desde->subDay()->toDateString()]));

        return $desde;
    }
}
