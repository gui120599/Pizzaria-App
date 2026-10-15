<?php

namespace Tests\Feature;

use App\Models\Feriado;
use Database\Seeders\FeriadosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FeriadoTest extends TestCase
{
    use RefreshDatabase;

    public function test_feriado_recorrente_vale_em_qualquer_ano(): void
    {
        Feriado::factory()->recorrente()->create(['data' => '2026-12-25', 'nome' => 'Natal']);

        $this->assertTrue(Feriado::ehFeriado(Carbon::parse('2031-12-25')));
        $this->assertFalse(Feriado::ehFeriado(Carbon::parse('2031-12-24')));
    }

    public function test_ponto_facultativo_nao_conta_como_feriado(): void
    {
        Feriado::factory()->facultativo()->create(['data' => '2026-02-17', 'nome' => 'Carnaval']);

        $this->assertFalse(Feriado::ehFeriado(Carbon::parse('2026-02-17')));
    }

    public function test_seeder_de_feriados_pode_rodar_de_novo_sem_duplicar(): void
    {
        $this->travelTo('2026-10-13 12:00:00');

        $this->seed(FeriadosSeeder::class);
        $this->seed(FeriadosSeeder::class);

        $this->assertSame(9, Feriado::where('recorrente_anual', true)->count());
        $this->assertSame(8, Feriado::where('recorrente_anual', false)->count());
        $this->assertTrue(Feriado::ehFeriado(Carbon::parse('2027-03-26')));
    }
}
