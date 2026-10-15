<?php

namespace Tests\Feature;

use App\Enums\Ponto\ModalidadeRemuneracao;
use App\Models\Colaborador;
use App\Models\Escala;
use Database\Seeders\PontoExemploSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PontoExemploSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_cria_escala_e_um_colaborador_de_cada_modalidade_sem_duplicar(): void
    {
        $this->seed(PontoExemploSeeder::class);
        $this->seed(PontoExemploSeeder::class);

        $noite = Escala::query()->where('nome', 'Noite pizzaria')->sole();
        $this->assertSame([3, 4, 5, 6, 7], $noite->dias()->where('folga', false)->orderBy('dia_semana')->pluck('dia_semana')->all());
        $this->assertSame('17:30', substr((string) $noite->dias()->where('dia_semana', 3)->value('entrada'), 0, 5));

        $modalidades = Colaborador::query()->with('remuneracoes')->get()
            ->map(fn (Colaborador $colaborador) => $colaborador->remuneracoes->sole()->modalidade)
            ->sortBy->value->values()->all();

        $this->assertEquals([ModalidadeRemuneracao::Mensal, ModalidadeRemuneracao::PorDiaria, ModalidadeRemuneracao::SomenteEntregas], $modalidades);
        $this->assertSame(3, Colaborador::query()->whereHas('escalas')->count());
    }
}
