<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\ItensPedido;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Endereço do cliente na view de pedido (create/edit): resolverCliente()
 * salva/atualiza cliente_endereco a partir de pedido_endereco_entrega,
 * igual ao que o checkout público do cardápio já fazia.
 */
class PedidoClienteEnderecoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
    }

    /** Pedido rascunho com pelo menos 1 item — store() exige itens não vazios. */
    private function pedidoComItem(): Pedido
    {
        $categoria = Categoria::create(['categoria_nome' => 'Pizzas']);
        $produto = Produto::create([
            'produto_descricao' => 'Calabresa',
            'produto_categoria_id' => $categoria->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 55.00,
        ]);

        $pedido = Pedido::create(['pedido_status' => 'INICIADO']);

        ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $produto->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => 55.00,
            'item_pedido_valor' => 55.00,
            'item_pedido_desconto' => 0,
            'item_pedido_valor_adicionais' => 0,
            'item_pedido_status' => 'INSERIDO',
        ]);

        return $pedido;
    }

    public function test_novo_cliente_salva_endereco_no_cadastro(): void
    {
        $pedido = $this->pedidoComItem();

        $this->post(route('pedido.store'), [
            'pedido_id' => $pedido->id,
            'cliente_nome_novo' => 'João da Silva',
            'cliente_celular_novo' => '11999998888',
            'pedido_endereco_entrega' => 'Rua das Flores, 123, Centro',
        ])->assertRedirect();

        $cliente = Cliente::where('cliente_celular', '11999998888')->firstOrFail();

        $this->assertSame('João da Silva', $cliente->cliente_nome);
        $this->assertSame('Rua das Flores, 123, Centro', $cliente->cliente_endereco);
        $this->assertSame($cliente->id, $pedido->fresh()->pedido_cliente_id);
    }

    public function test_cliente_existente_encontrado_por_telefone_atualiza_endereco(): void
    {
        $cliente = Cliente::create([
            'cliente_nome' => 'Maria Souza',
            'cliente_celular' => '11988887777',
            'cliente_endereco' => 'Endereço antigo, 1',
            'cliente_tipo' => 'Física',
        ]);

        $pedido = $this->pedidoComItem();

        $this->post(route('pedido.store'), [
            'pedido_id' => $pedido->id,
            'cliente_nome_novo' => 'Maria Souza',
            'cliente_celular_novo' => '11988887777',
            'pedido_endereco_entrega' => 'Endereço novo, 2',
        ])->assertRedirect();

        $this->assertSame($cliente->id, $pedido->fresh()->pedido_cliente_id);
        $this->assertSame('Endereço novo, 2', $cliente->fresh()->cliente_endereco);
    }

    public function test_sem_endereco_no_request_nao_apaga_endereco_ja_salvo(): void
    {
        $cliente = Cliente::create([
            'cliente_nome' => 'Maria Souza',
            'cliente_celular' => '11988887777',
            'cliente_endereco' => 'Endereço que deve permanecer',
            'cliente_tipo' => 'Física',
        ]);

        $pedido = $this->pedidoComItem();

        $this->post(route('pedido.store'), [
            'pedido_id' => $pedido->id,
            'cliente_nome_novo' => 'Maria Souza',
            'cliente_celular_novo' => '11988887777',
        ])->assertRedirect();

        $this->assertSame('Endereço que deve permanecer', $cliente->fresh()->cliente_endereco);
    }

    public function test_cliente_ja_selecionado_por_id_nao_mexe_no_endereco(): void
    {
        $cliente = Cliente::create([
            'cliente_nome' => 'Pedro Lima',
            'cliente_endereco' => 'Endereço fixo do cadastro',
            'cliente_tipo' => 'Física',
        ]);

        $pedido = $this->pedidoComItem();

        $this->post(route('pedido.store'), [
            'pedido_id' => $pedido->id,
            'pedido_cliente_id' => $cliente->id,
            'pedido_endereco_entrega' => 'Endereço só desta entrega',
        ])->assertRedirect();

        $this->assertSame($cliente->id, $pedido->fresh()->pedido_cliente_id);
        $this->assertSame('Endereço fixo do cadastro', $cliente->fresh()->cliente_endereco);
        $this->assertSame('Endereço só desta entrega', $pedido->fresh()->pedido_endereco_entrega);
    }
}
