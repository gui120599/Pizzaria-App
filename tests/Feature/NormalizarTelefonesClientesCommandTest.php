<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NormalizarTelefonesClientesCommandTest extends TestCase
{
    use RefreshDatabase;

    private function clienteAntigo(?string $celular): int
    {
        return DB::table('clientes')->insertGetId([
            'cliente_nome' => 'Cliente',
            'cliente_tipo' => 'Física',
            'cliente_celular' => $celular,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_sem_opcoes_preenche_so_a_chave_e_nao_altera_o_celular_gravado(): void
    {
        $antigo = $this->clienteAntigo('(62) 8123-4567');
        $semDdd = $this->clienteAntigo('981234567');

        $this->artisan('clientes:normalizar-telefones')->assertSuccessful();

        $this->assertDatabaseHas('clientes', ['id' => $antigo, 'cliente_celular' => '(62) 8123-4567', 'cliente_celular_normalizado' => '62981234567']);
        $this->assertDatabaseHas('clientes', ['id' => $semDdd, 'cliente_celular' => '981234567', 'cliente_celular_normalizado' => '981234567']);
    }

    public function test_canonizar_sem_aplicar_so_relata(): void
    {
        $antigo = $this->clienteAntigo('(62) 8123-4567');

        $this->artisan('clientes:normalizar-telefones', ['--canonizar' => true])
            ->expectsOutputToContain('1 celular(es) antigo(s) fora da forma canônica.')
            ->expectsOutputToContain('Nada foi regravado.')
            ->assertSuccessful();

        $this->assertDatabaseHas('clientes', ['id' => $antigo, 'cliente_celular' => '(62) 8123-4567']);
    }

    public function test_canonizar_e_aplicar_regrava_os_reconhecidos_e_preserva_os_nao_reconhecidos(): void
    {
        $antigo = $this->clienteAntigo('+55 (62) 8123-4567');
        $estrangeiro = $this->clienteAntigo('+44 7931 223635');

        $this->artisan('clientes:normalizar-telefones', ['--canonizar' => true, '--aplicar' => true])->assertSuccessful();

        $this->assertDatabaseHas('clientes', ['id' => $antigo, 'cliente_celular' => '62981234567']);
        $this->assertDatabaseHas('clientes', ['id' => $estrangeiro, 'cliente_celular' => '+44 7931 223635']);
    }
}
