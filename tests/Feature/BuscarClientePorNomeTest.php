<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuscarClientePorNomeTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create(['name_first' => 'Garçom']);
    }

    private function cliente(array $attrs = []): Cliente
    {
        return Cliente::create(array_merge([
            'cliente_nome' => 'Cliente Teste',
            'cliente_tipo' => 'Pessoa Física',
        ], $attrs));
    }

    public function test_rota_exige_autenticacao(): void
    {
        $this->getJson(route('cliente.buscar_nome', ['nome' => 'João']))
            ->assertUnauthorized();
    }

    public function test_encontra_clientes_pelo_nome(): void
    {
        $this->cliente([
            'cliente_nome' => 'João da Silva',
            'cliente_celular' => '11999998888',
            'cliente_endereco' => 'Rua A',
            'cliente_numero_endereco' => '100',
            'cliente_bairro' => 'Centro',
        ]);
        $this->cliente(['cliente_nome' => 'Maria Souza']);

        $response = $this->actingAs($this->user())
            ->getJson(route('cliente.buscar_nome', ['nome' => 'João']));

        $response->assertOk()
            ->assertJsonCount(1, 'clientes')
            ->assertJsonPath('clientes.0.nome', 'João da Silva')
            ->assertJsonPath('clientes.0.celular', '11999998888')
            ->assertJsonPath('clientes.0.endereco', 'Rua A, 100, Centro');
    }

    public function test_busca_parcial_e_case_insensitive(): void
    {
        $this->cliente(['cliente_nome' => 'João da Silva']);
        $this->cliente(['cliente_nome' => 'Joana Silveira']);
        $this->cliente(['cliente_nome' => 'Pedro Alves']);

        $response = $this->actingAs($this->user())
            ->getJson(route('cliente.buscar_nome', ['nome' => 'silv']));

        $response->assertOk()->assertJsonCount(2, 'clientes');
    }

    public function test_termo_curto_retorna_lista_vazia(): void
    {
        $this->cliente(['cliente_nome' => 'João da Silva']);

        $this->actingAs($this->user())
            ->getJson(route('cliente.buscar_nome', ['nome' => 'J']))
            ->assertOk()
            ->assertExactJson(['clientes' => []]);
    }

    public function test_limita_resultados_em_quinze(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->cliente(['cliente_nome' => "Cliente Repetido {$i}"]);
        }

        $this->actingAs($this->user())
            ->getJson(route('cliente.buscar_nome', ['nome' => 'Repetido']))
            ->assertOk()
            ->assertJsonCount(15, 'clientes');
    }
}
