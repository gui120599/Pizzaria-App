<?php

namespace Database\Seeders;

use App\Enums\Ponto\ModalidadeRemuneracao;
use App\Enums\Ponto\PeriodicidadeRepasse;
use App\Enums\Ponto\TipoEscala;
use App\Enums\Ponto\VinculoColaborador;
use App\Models\Colaborador;
use App\Models\Escala;
use Illuminate\Database\Seeder;

/**
 * Dados de exemplo do ponto (spec §10, Fase 6), para homologação e
 * treinamento: escala "Noite pizzaria" (qua–dom 17:30–00:30, 1h de
 * intervalo), escala livre de entregas e três colaboradores, um de cada
 * modalidade: diarista, entregador por entrega e mensalista CLT.
 *
 * Idempotente: procura pelo nome e não altera o que já existe. Não usa
 * factories, porque o Faker não vai para produção. Os colaboradores ficam
 * sem login e sem PIN; cadastre pelo admin se quiser testar o ponto.
 *
 * php artisan db:seed --class=PontoExemploSeeder
 */
class PontoExemploSeeder extends Seeder
{
    /** Quarta a domingo (ISO: 1 = segunda … 7 = domingo). */
    private const DIAS_NOITE = [3, 4, 5, 6, 7];

    public function run(): void
    {
        $noite = $this->escalaNoitePizzaria();
        $entregas = Escala::query()->firstOrCreate(
            ['nome' => 'Entregas (livre)'],
            ['tipo' => TipoEscala::Livre, 'tolerancia_marcacao_min' => 5, 'tolerancia_diaria_min' => 10, 'intervalo_minimo_min' => 0],
        );
        $admissao = now()->startOfMonth()->toDateString();

        $this->colaborador(
            ['nome' => 'Exemplo — Diarista', 'cargo' => 'Pizzaiolo', 'setor' => 'Cozinha', 'vinculo' => VinculoColaborador::Diarista, 'periodicidade_repasse' => PeriodicidadeRepasse::Semanal],
            ['modalidade' => ModalidadeRemuneracao::PorDiaria, 'valor_base' => 120, 'carga_diaria_minutos' => 360],
            $noite,
            $admissao,
        );

        $this->colaborador(
            ['nome' => 'Exemplo — Entregador', 'cargo' => 'Entregador', 'setor' => 'Entrega', 'vinculo' => VinculoColaborador::Freelancer, 'periodicidade_repasse' => PeriodicidadeRepasse::Semanal, 'entregador' => true],
            ['modalidade' => ModalidadeRemuneracao::SomenteEntregas, 'valor_base' => 0, 'valor_por_entrega' => 7],
            $entregas,
            $admissao,
        );

        $this->colaborador(
            ['nome' => 'Exemplo — Mensalista CLT', 'cargo' => 'Atendente', 'setor' => 'Salão', 'vinculo' => VinculoColaborador::Clt, 'periodicidade_repasse' => PeriodicidadeRepasse::Mensal],
            ['modalidade' => ModalidadeRemuneracao::Mensal, 'valor_base' => 1800, 'carga_diaria_minutos' => 360, 'carga_semanal_minutos' => 1800],
            $noite,
            $admissao,
        );
    }

    private function escalaNoitePizzaria(): Escala
    {
        $escala = Escala::query()->firstOrCreate(
            ['nome' => 'Noite pizzaria'],
            ['tipo' => TipoEscala::Fixa, 'tolerancia_marcacao_min' => 5, 'tolerancia_diaria_min' => 10, 'intervalo_minimo_min' => 60],
        );

        foreach (range(1, 7) as $diaSemana) {
            $trabalha = in_array($diaSemana, self::DIAS_NOITE, true);

            $escala->dias()->firstOrCreate(['dia_semana' => $diaSemana], [
                'folga' => ! $trabalha,
                'entrada' => $trabalha ? '17:30' : null,
                'saida' => $trabalha ? '00:30' : null,
                'intervalo_minutos' => $trabalha ? 60 : 0,
            ]);
        }

        return $escala;
    }

    /**
     * @param  array<string, mixed>  $dados
     * @param  array<string, mixed>  $remuneracao
     */
    private function colaborador(array $dados, array $remuneracao, Escala $escala, string $admissao): void
    {
        if (Colaborador::withTrashed()->where('nome', $dados['nome'])->exists()) {
            return;
        }

        $colaborador = Colaborador::create([...$dados, 'data_admissao' => $admissao]);
        $colaborador->remuneracoes()->create([...$remuneracao, 'vigente_desde' => $admissao]);
        $colaborador->escalas()->create(['escala_id' => $escala->id, 'vigente_desde' => $admissao]);
    }
}
