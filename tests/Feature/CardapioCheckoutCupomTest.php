<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Enums\TipoDescontoEnum;
use App\Models\Categoria;
use App\Models\Cupom;
use App\Models\HorarioFuncionamento;
use App\Models\OpcoesEntregas;
use App\Models\Pedido;
use App\Models\Produto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CardapioCheckoutCupomTest extends TestCase
{
    use RefreshDatabase;

    private Produto $pizza;

    private OpcoesEntregas $entrega;

    protected function setUp(): void
    {
        parent::setUp();

        HorarioFuncionamento::create([
            'horario_dia_semana' => now()->dayOfWeek,
            'horario_ativo' => true,
            'horario_abertura' => '00:00:00',
            'horario_fechamento' => '23:59:59',
        ]);

        $categoria = Categoria::create(['categoria_nome' => 'Pizzas', 'categoria_cardapio' => true]);

        $this->pizza = Produto::create([
            'produto_descricao' => 'Calabresa',
            'produto_categoria_id' => $categoria->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 100.00,
            'produto_cardapio' => true,
        ]);

        $this->entrega = OpcoesEntregas::create([
            'opcaoentrega_nome' => 'Retirada',
            'opcaoentrega_valor_frete' => 0,
            'opcaoentrega_min_valor_frete' => 0,
        ]);
    }

    private function checkout(array $extra = []): TestResponse
    {
        return $this->postJson(route('cardapio.checkout'), array_merge([
            'nome' => 'Cliente Teste',
            'telefone' => '11999998888',
            'opcao_entrega_id' => $this->entrega->id,
            'pagamento_nome' => 'Dinheiro',
            'itens' => [
                ['id' => $this->pizza->id, 'qty' => 1],
            ],
        ], $extra));
    }

    public function test_cupom_valido_e_aplicado_e_gravado_no_pedido(): void
    {
        Cupom::create([
            'cupom_codigo' => 'DESC10',
            'cupom_tipo_desconto' => TipoDescontoEnum::PERCENTUAL->value,
            'cupom_valor_desconto' => 10,
            'cupom_ativo' => true,
        ]);

        $this->checkout(['codigo_cupom' => 'DESC10'])->assertOk();

        $pedido = Pedido::firstOrFail();

        $this->assertNotNull($pedido->pedido_cupom_id);
        $this->assertSame('10.00', $pedido->pedido_valor_desconto_cupom);
        $this->assertSame('90.00', $pedido->pedido_valor_total);
    }

    public function test_cupom_inexistente_retorna_422_com_mensagem(): void
    {
        $this->checkout(['codigo_cupom' => 'NAO-EXISTE'])
            ->assertStatus(422)
            ->assertJsonStructure(['message']);

        $this->assertSame(0, Pedido::count());
    }

    public function test_cupom_inativo_impede_checkout(): void
    {
        Cupom::create([
            'cupom_codigo' => 'DESC10',
            'cupom_tipo_desconto' => TipoDescontoEnum::PERCENTUAL->value,
            'cupom_valor_desconto' => 10,
            'cupom_ativo' => false,
        ]);

        $this->checkout(['codigo_cupom' => 'DESC10'])->assertStatus(422);

        $this->assertSame(0, Pedido::count());
    }

    public function test_checkout_sem_cupom_nao_altera_total(): void
    {
        $this->checkout()->assertOk();

        $pedido = Pedido::firstOrFail();

        $this->assertNull($pedido->pedido_cupom_id);
        $this->assertSame('100.00', $pedido->pedido_valor_total);
    }
}
