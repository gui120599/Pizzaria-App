<?php

namespace Tests\Feature;

use App\Livewire\ClientePicker;
use App\Models\Cliente;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Réplica dos cenários de PedidoClienteEnderecoTest.php (resolverCliente()
 * legado), agora contra o componente ClientePicker usado na tela unificada
 * "Atender Pedido". O picker só entrega dados normalizados via evento — aqui
 * testamos o estado do componente em si, não a resolução final do Cliente
 * (isso acontece no save() da Page, coberto em AtenderPedidoCriacaoTest).
 */
class ClientePickerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
    }

    public function test_encontra_cliente_existente_por_telefone_e_preenche_dados(): void
    {
        $cliente = Cliente::create([
            'cliente_nome' => 'Maria Souza',
            'cliente_celular' => '11988887777',
            'cliente_endereco' => 'Rua Antiga, 1',
            'cliente_tipo' => 'Física',
        ]);

        Livewire::test(ClientePicker::class)
            ->set('celular', '11988887777')
            ->assertSet('clienteEncontrado', true)
            ->assertSet('clienteId', $cliente->id)
            ->assertSet('nome', 'Maria Souza')
            ->assertSet('enderecoRua', 'Rua Antiga, 1');
    }

    public function test_telefone_nao_cadastrado_nao_marca_cliente_encontrado(): void
    {
        Livewire::test(ClientePicker::class)
            ->set('celular', '11999990000')
            ->assertSet('clienteEncontrado', false)
            ->assertSet('clienteId', null);
    }

    public function test_endereco_ja_digitado_na_tela_nao_e_sobrescrito_pelo_cadastro(): void
    {
        Cliente::create([
            'cliente_nome' => 'Maria Souza',
            'cliente_celular' => '11988887777',
            'cliente_endereco' => 'Endereço do cadastro',
            'cliente_tipo' => 'Física',
        ]);

        Livewire::test(ClientePicker::class)
            ->set('enderecoRua', 'Endereço digitado agora')
            ->set('celular', '11988887777')
            ->assertSet('enderecoRua', 'Endereço digitado agora');
    }

    public function test_marcar_sem_cliente_limpa_selecao(): void
    {
        $cliente = Cliente::create([
            'cliente_nome' => 'Pedro Lima',
            'cliente_celular' => '11977776666',
            'cliente_tipo' => 'Física',
        ]);

        Livewire::test(ClientePicker::class)
            ->set('celular', '11977776666')
            ->assertSet('clienteId', $cliente->id)
            ->set('semCliente', true)
            ->assertSet('clienteId', null)
            ->assertSet('clienteEncontrado', false);
    }

    public function test_selecionar_da_busca_preenche_dados_do_cliente(): void
    {
        $cliente = Cliente::create([
            'cliente_nome' => 'João da Silva',
            'cliente_celular' => '11966665555',
            'cliente_endereco' => 'Rua das Flores, 123',
            'cliente_tipo' => 'Física',
        ]);

        Livewire::test(ClientePicker::class)
            ->call('selecionarDaBusca', $cliente->id)
            ->assertSet('clienteId', $cliente->id)
            ->assertSet('nome', 'João da Silva')
            ->assertSet('celular', '11966665555')
            ->assertSet('buscaModalAberta', false);
    }
}
