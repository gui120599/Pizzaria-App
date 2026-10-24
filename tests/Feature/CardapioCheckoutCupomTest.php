<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Enums\StatusInfluenciadorEnum;
use App\Enums\TipoDescontoEnum;
use App\Http\Controllers\ReferenciaInfluenciadorController;
use App\Http\Middleware\EncryptCookies;
use App\Models\Categoria;
use App\Models\Cupom;
use App\Models\HorarioFuncionamento;
use App\Models\Influenciador;
use App\Models\ItensPedido;
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
            ->assertJsonPath('message', 'Cupom inválido ou indisponível.');

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

    private function cupomDesc10(array $extra = []): Cupom
    {
        return Cupom::create(array_merge([
            'cupom_codigo' => 'DESC10',
            'cupom_tipo_desconto' => TipoDescontoEnum::PERCENTUAL->value,
            'cupom_valor_desconto' => 10,
            'cupom_ativo' => true,
        ], $extra));
    }

    private function validar(string $codigo): TestResponse
    {
        return $this->postJson(route('cardapio.cupom.validar'), [
            'codigo_cupom' => $codigo,
            'itens' => [['id' => $this->pizza->id, 'qty' => 2]],
        ]);
    }

    public function test_checkout_com_cupom_grava_o_desconto_nos_itens(): void
    {
        $this->cupomDesc10();

        $this->checkout(['codigo_cupom' => 'desc10'])
            ->assertOk()
            ->assertJsonPath('total_final', 90)
            ->assertJsonPath('valor_desconto_cupom', 10);

        $item = ItensPedido::sole();
        $this->assertSame('10.00', $item->item_pedido_desconto_cupom);
        $this->assertSame('90.00', $item->item_pedido_valor);
        $this->assertSame('100.00', Pedido::sole()->pedido_valor_itens);
    }

    public function test_validar_cupom_devolve_o_desconto_calculado_no_servidor(): void
    {
        $this->cupomDesc10();

        // Dois itens de 100: o desconto vem dos itens precificados, não do navegador.
        $this->validar('desc10')
            ->assertOk()
            ->assertJsonPath('codigo', 'DESC10')
            ->assertJsonPath('valor_desconto', 20);

        $this->assertSame(0, Pedido::count());
    }

    public function test_validar_cupom_usa_mensagem_generica_para_qualquer_motivo(): void
    {
        $this->cupomDesc10(['cupom_codigo' => 'INATIVO', 'cupom_ativo' => false]);
        $this->cupomDesc10(['cupom_codigo' => 'EXPIRADO', 'cupom_data_expiracao' => now()->subDay()->toDateString()]);

        foreach (['NAOEXISTE', 'INATIVO', 'EXPIRADO'] as $codigo) {
            $this->validar($codigo)
                ->assertStatus(422)
                ->assertExactJson(['message' => 'Cupom inválido ou indisponível.']);
        }
    }

    public function test_validar_cupom_tem_limite_de_tentativas(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->validar('CHUTE'.$i)->assertStatus(422);
        }

        $this->validar('CHUTE11')->assertStatus(429);
    }

    public function test_cardapio_aceita_cupom_pela_query_e_expoe_a_rota_de_validacao(): void
    {
        $this->get(route('cardapio', ['cupom' => 'JOAO10']))
            ->assertOk()
            ->assertSee('const _cupomRoute', false)
            ->assertSee("get('cupom')", false);
    }

    public function test_cupom_de_influenciador_inativo_e_recusado_no_checkout(): void
    {
        $influenciador = Influenciador::create([
            'influenciador_nome' => 'Fulano',
            'email' => uniqid().'@teste.com',
            'password' => 'senha-segura',
        ]);
        $this->cupomDesc10(['cupom_codigo' => 'FULANO10', 'cupom_influenciador_id' => $influenciador->id]);

        $influenciador->forceFill(['influenciador_status' => StatusInfluenciadorEnum::ATIVO])->save();
        $this->checkout(['codigo_cupom' => 'FULANO10'])->assertOk();

        $influenciador->forceFill(['influenciador_status' => StatusInfluenciadorEnum::INATIVO])->save();
        $this->checkout(['codigo_cupom' => 'FULANO10'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cupom inválido ou indisponível.');

        $influenciador->forceFill(['influenciador_status' => StatusInfluenciadorEnum::ATIVO])->save();
        $influenciador->delete();
        $this->checkout(['codigo_cupom' => 'FULANO10'])->assertStatus(422);
    }

    public function test_referencia_do_link_e_gravada_no_pedido_sem_gerar_desconto(): void
    {
        $influenciador = Influenciador::create([
            'influenciador_nome' => 'Fulano',
            'email' => uniqid().'@teste.com',
            'password' => 'senha-segura',
        ]);
        $influenciador->forceFill(['influenciador_status' => StatusInfluenciadorEnum::ATIVO])->save();

        // Cookie passado direto na requisição (sem a camada de criptografia do harness).
        $this->withoutMiddleware(EncryptCookies::class)
            ->withHeader('Accept', 'application/json')
            ->call('POST', route('cardapio.checkout'), [
                'nome' => 'Cliente Teste',
                'telefone' => '11999998888',
                'opcao_entrega_id' => $this->entrega->id,
                'pagamento_nome' => 'Dinheiro',
                'itens' => [['id' => $this->pizza->id, 'qty' => 1]],
            ], [ReferenciaInfluenciadorController::COOKIE => (string) $influenciador->id])
            ->assertOk();

        $pedido = Pedido::sole();
        $this->assertSame($influenciador->id, $pedido->pedido_influenciador_ref_id);
        $this->assertNull($pedido->pedido_cupom_id);
        $this->assertSame('100.00', $pedido->pedido_valor_total);
    }
}
