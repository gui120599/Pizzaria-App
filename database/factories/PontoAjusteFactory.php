<?php

namespace Database\Factories;

use App\Enums\Ponto\MotivoAjuste;
use App\Enums\Ponto\OrigemAjuste;
use App\Enums\Ponto\StatusAjuste;
use App\Enums\Ponto\TipoAjuste;
use App\Models\Colaborador;
use App\Models\PontoAjuste;
use App\Models\PontoMarcacao;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @extends Factory<PontoAjuste>
 */
class PontoAjusteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'colaborador_id' => Colaborador::factory(),
            'data_referencia' => now()->toDateString(),
            'tipo' => TipoAjuste::Inclusao,
            'data_hora' => now()->startOfMinute(),
            'motivo' => MotivoAjuste::Esquecimento,
            'justificativa' => 'Esqueceu de marcar a entrada.',
            'status' => StatusAjuste::Pendente,
            'origem' => OrigemAjuste::Colaborador,
        ];
    }

    public function inclusao(string $dataHora): static
    {
        return $this->state(['tipo' => TipoAjuste::Inclusao, 'data_hora' => Carbon::parse($dataHora), 'ponto_marcacao_id' => null]);
    }

    public function desconsideracao(PontoMarcacao $marcacao): static
    {
        return $this->state([
            'tipo' => TipoAjuste::Desconsideracao,
            'colaborador_id' => $marcacao->colaborador_id,
            'data_referencia' => $marcacao->data_referencia,
            'ponto_marcacao_id' => $marcacao->id,
            'data_hora' => null,
        ]);
    }

    public function abono(?int $minutos = null): static
    {
        return $this->state(['tipo' => TipoAjuste::Abono, 'minutos' => $minutos, 'data_hora' => null, 'motivo' => MotivoAjuste::Atestado]);
    }

    public function aprovado(): static
    {
        return $this->state(['status' => StatusAjuste::Aprovado, 'decidido_em' => now()]);
    }

    public function doGestor(?string $loteUuid = null): static
    {
        return $this->state([
            'origem' => OrigemAjuste::Gestor,
            'status' => StatusAjuste::Aprovado,
            'decidido_em' => now(),
            'lote_uuid' => $loteUuid ?? (string) Str::uuid(),
            'justificativa' => 'Correção feita pelo gestor no espelho.',
        ]);
    }
}
