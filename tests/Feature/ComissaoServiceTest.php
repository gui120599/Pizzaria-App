<?php

namespace Tests\Feature;

use App\Enums\StatusComissaoEnum;
use App\Enums\TipoDescontoEnum;
use App\Models\Cliente;
use App\Models\Comissao;
use App\Models\Cupom;
use App\Models\Influenciador;
use App\Models\Pedido;
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

        return Pedido::create([
            'pedido_cliente_id' => $cliente->id,
            'pedido_cupom_id' => $cupom->id,
            'pedido_valor_total' => $valorTotal,
            'pedido_status' => 'INICIADO',
        ]);
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

    public function test_aprovar_promove_pendente_para_aprovada(): void
    {
        $influenciador = $this->influenciador();
        $pedido = $this->pedidoComCupom($influenciador);

        $this->service->aprovar($pedido);

        $comissao = Comissao::where('comissao_pedido_id', $pedido->id)->first();
        $this->assertSame(StatusComissaoEnum::APROVADA, $comissao->comissao_status);
        $this->assertNotNull($comissao->comissao_aprovada_em);
    }

    public function test_aprovar_nunca_reabre_comissao_paga(): void
    {
        $influenciador = $this->influenciador();
        $pedido = $this->pedidoComCupom($influenciador);

        $comissao = Comissao::where('comissao_pedido_id', $pedido->id)->first();
        $comissao->forceFill(['comissao_status' => StatusComissaoEnum::PAGA])->save();

        $this->service->aprovar($pedido);

        $this->assertSame(StatusComissaoEnum::PAGA, $comissao->fresh()->comissao_status);
    }

    public function test_cancelar_marca_comissao_como_cancelada(): void
    {
        $influenciador = $this->influenciador();
        $pedido = $this->pedidoComCupom($influenciador);

        $this->service->cancelar($pedido);

        $comissao = Comissao::where('comissao_pedido_id', $pedido->id)->first();
        $this->assertSame(StatusComissaoEnum::CANCELADA, $comissao->comissao_status);
    }

    public function test_cancelar_nunca_reabre_comissao_paga(): void
    {
        $influenciador = $this->influenciador();
        $pedido = $this->pedidoComCupom($influenciador);

        $comissao = Comissao::where('comissao_pedido_id', $pedido->id)->first();
        $comissao->forceFill(['comissao_status' => StatusComissaoEnum::PAGA])->save();

        $this->service->cancelar($pedido);

        $this->assertSame(StatusComissaoEnum::PAGA, $comissao->fresh()->comissao_status);
    }

    public function test_calculo_valor_fixo(): void
    {
        $influenciador = $this->influenciador([
            'influenciador_tipo_comissao' => TipoDescontoEnum::VALOR_FIXO->value,
            'influenciador_valor_comissao' => 15,
        ]);
        $pedido = $this->pedidoComCupom($influenciador, 200.0);

        $comissao = Comissao::where('comissao_pedido_id', $pedido->id)->first();

        $this->assertSame(15.0, (float) $comissao->comissao_valor);
    }
}
