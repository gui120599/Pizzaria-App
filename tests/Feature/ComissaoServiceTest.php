<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Enums\StatusComissaoEnum;
use App\Enums\TipoDescontoEnum;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Comissao;
use App\Models\Cupom;
use App\Models\Influenciador;
use App\Models\ItensPedido;
use App\Models\Pedido;
use App\Models\Produto;
use App\Services\ComissaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComissaoServiceTest extends TestCase
{
    use RefreshDatabase;

    private ComissaoService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ComissaoService;
    }

    private function influenciador(array $attrs = []): Influenciador
    {
        return Influenciador::create(array_merge([
            'influenciador_nome' => 'Fulano',
            'email' => uniqid().'@teste.com',
            'password' => 'senha-segura',
            'influenciador_tipo_comissao' => TipoDescontoEnum::PERCENTUAL->value,
            'influenciador_valor_comissao' => 10,
        ], $attrs));
    }

    private function pedidoComCupom(Influenciador $influenciador, float $valorTotal = 100.0): Pedido
    {
        $cliente = Cliente::create(['cliente_nome' => 'Cliente Teste', 'cliente_tipo' => 'PF']);

        $cupom = Cupom::create([
            'cupom_influenciador_id' => $influenciador->id,
            'cupom_codigo' => 'PROMO'.uniqid(),
            'cupom_tipo_desconto' => TipoDescontoEnum::PERCENTUAL->value,
            'cupom_valor_desconto' => 10,
        ]);

        $pedido = Pedido::create([
            'pedido_cliente_id' => $cliente->id,
            'pedido_cupom_id' => $cupom->id,
            'pedido_valor_total' => $valorTotal,
            'pedido_status' => 'INICIADO',
        ]);

        // A base do crédito é a soma dos itens líquidos do pedido.
        ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => Produto::create([
                'produto_descricao' => 'Calabresa',
                'produto_categoria_id' => Categoria::firstOrCreate(['categoria_nome' => 'Pizzas'])->id,
                'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
                'produto_preco_venda' => $valorTotal,
            ])->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => $valorTotal,
            'item_pedido_valor' => $valorTotal,
            'item_pedido_desconto' => 0,
            'item_pedido_status' => 'INSERIDO',
        ]);

        return $pedido;
    }

    public function test_criar_se_aplicavel_cria_comissao_pendente_para_pedido_com_cupom_de_influenciador(): void
    {
        $influenciador = $this->influenciador();
        $pedido = $this->pedidoComCupom($influenciador, 100.0);

        $comissao = Comissao::where('comissao_pedido_id', $pedido->id)->first();

        $this->assertNotNull($comissao);
        $this->assertSame(StatusComissaoEnum::PENDENTE, $comissao->comissao_status);
        $this->assertSame($influenciador->id, $comissao->comissao_influenciador_id);
        $this->assertSame(10.0, (float) $comissao->comissao_valor);
    }

    public function test_criar_se_aplicavel_nao_cria_comissao_para_cupom_sem_influenciador(): void
    {
        $cliente = Cliente::create(['cliente_nome' => 'Cliente Teste', 'cliente_tipo' => 'PF']);
        $cupom = Cupom::create([
            'cupom_codigo' => 'INTERNO'.uniqid(),
            'cupom_tipo_desconto' => TipoDescontoEnum::PERCENTUAL->value,
            'cupom_valor_desconto' => 10,
        ]);

        Pedido::create([
            'pedido_cliente_id' => $cliente->id,
            'pedido_cupom_id' => $cupom->id,
            'pedido_valor_total' => 100.0,
            'pedido_status' => 'INICIADO',
        ]);

        $this->assertSame(0, Comissao::count());
    }

    public function test_criar_se_aplicavel_e_idempotente(): void
    {
        $influenciador = $this->influenciador();
        $pedido = $this->pedidoComCupom($influenciador);

        $this->service->criarSeAplicavel($pedido);
        $this->service->criarSeAplicavel($pedido);

        $this->assertSame(1, Comissao::where('comissao_pedido_id', $pedido->id)->count());
    }

    public function test_creditar_promove_pendente_para_creditada_e_lanca_na_carteira(): void
    {
        $influenciador = $this->influenciador();
        $pedido = $this->pedidoComCupom($influenciador);

        $this->service->creditar($pedido);

        $comissao = Comissao::where('comissao_pedido_id', $pedido->id)->first();
        $this->assertSame(StatusComissaoEnum::CREDITADA, $comissao->comissao_status);
        $this->assertNotNull($comissao->comissao_creditada_em);
        $this->assertSame(10.0, $influenciador->fresh()->saldoCarteira());
    }

    public function test_creditar_duas_vezes_gera_um_unico_movimento(): void
    {
        $influenciador = $this->influenciador();
        $pedido = $this->pedidoComCupom($influenciador);

        $this->service->creditar($pedido);
        $this->service->creditar($pedido);

        $this->assertSame(1, $influenciador->movimentosCarteira()->count());
        $this->assertSame(10.0, $influenciador->fresh()->saldoCarteira());
    }

    public function test_cancelar_marca_comissao_como_cancelada(): void
    {
        $influenciador = $this->influenciador();
        $pedido = $this->pedidoComCupom($influenciador);

        $this->service->cancelar($pedido);

        $comissao = Comissao::where('comissao_pedido_id', $pedido->id)->first();
        $this->assertSame(StatusComissaoEnum::CANCELADA, $comissao->comissao_status);
    }

    public function test_cancelar_estorna_comissao_ja_creditada(): void
    {
        $influenciador = $this->influenciador();
        $pedido = $this->pedidoComCupom($influenciador);
        $this->service->creditar($pedido);

        $this->service->cancelar($pedido);

        $comissao = Comissao::where('comissao_pedido_id', $pedido->id)->first();
        $this->assertSame(StatusComissaoEnum::ESTORNADA, $comissao->comissao_status);
        $this->assertSame(0.0, $influenciador->fresh()->saldoCarteira());
        $this->assertSame(2, $influenciador->movimentosCarteira()->count());

        // Idempotente: cancelar de novo não estorna em dobro.
        $this->service->cancelar($pedido);
        $this->assertSame(2, $influenciador->movimentosCarteira()->count());
    }
}
