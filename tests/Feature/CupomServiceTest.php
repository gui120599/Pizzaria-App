<?php

namespace Tests\Feature;

use App\Enums\TipoDescontoEnum;
use App\Exceptions\CupomInvalidoException;
use App\Models\Cliente;
use App\Models\Cupom;
use App\Models\Pedido;
use App\Services\CupomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CupomServiceTest extends TestCase
{
    use RefreshDatabase;

    private CupomService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new CupomService;
    }

    private function cupom(array $attrs = []): Cupom
    {
        return Cupom::create(array_merge([
            'cupom_codigo' => 'PROMO10',
            'cupom_tipo_desconto' => TipoDescontoEnum::PERCENTUAL->value,
            'cupom_valor_desconto' => 10,
            'cupom_limite_uso_por_cliente' => 1,
            'cupom_ativo' => true,
        ], $attrs));
    }

    private function cliente(): Cliente
    {
        return Cliente::create([
            'cliente_nome' => 'Cliente Teste',
            'cliente_tipo' => 'PF',
        ]);
    }

    public function test_cupom_inexistente_lanca_excecao(): void
    {
        $this->expectException(CupomInvalidoException::class);

        $this->service->validar('NAO-EXISTE', 100.0, null);
    }

    public function test_cupom_inativo_lanca_excecao(): void
    {
        $this->cupom(['cupom_ativo' => false]);

        $this->expectException(CupomInvalidoException::class);

        $this->service->validar('PROMO10', 100.0, null);
    }

    public function test_cupom_fora_da_janela_antes_do_inicio_lanca_excecao(): void
    {
        $this->cupom(['cupom_data_inicio' => now()->addDay()->toDateString()]);

        $this->expectException(CupomInvalidoException::class);

        $this->service->validar('PROMO10', 100.0, null);
    }

    public function test_cupom_expirado_lanca_excecao(): void
    {
        $this->cupom(['cupom_data_expiracao' => now()->subDay()->toDateString()]);

        $this->expectException(CupomInvalidoException::class);

        $this->service->validar('PROMO10', 100.0, null);
    }

    public function test_valor_minimo_nao_atingido_lanca_excecao(): void
    {
        $this->cupom(['cupom_valor_minimo_pedido' => 50]);

        $this->expectException(CupomInvalidoException::class);

        $this->service->validar('PROMO10', 30.0, null);
    }

    public function test_limite_total_de_uso_estourado_lanca_excecao(): void
    {
        $cupom = $this->cupom(['cupom_limite_uso_total' => 1]);
        $cliente = $this->cliente();

        Pedido::create([
            'pedido_cliente_id' => $cliente->id,
            'pedido_cupom_id' => $cupom->id,
            'pedido_status' => 'FINALIZADO',
        ]);

        $this->expectException(CupomInvalidoException::class);

        $this->service->validar('PROMO10', 100.0, null);
    }

    public function test_pedido_cancelado_nao_conta_para_limite_total(): void
    {
        $cupom = $this->cupom(['cupom_limite_uso_total' => 1]);
        $cliente = $this->cliente();

        Pedido::create([
            'pedido_cliente_id' => $cliente->id,
            'pedido_cupom_id' => $cupom->id,
            'pedido_status' => 'CANCELADO',
        ]);

        $resultado = $this->service->validar('PROMO10', 100.0, null);

        $this->assertSame(10.0, $resultado['valor_desconto']);
    }

    public function test_limite_por_cliente_estourado_lanca_excecao(): void
    {
        $cupom = $this->cupom(['cupom_limite_uso_por_cliente' => 1]);
        $cliente = $this->cliente();

        Pedido::create([
            'pedido_cliente_id' => $cliente->id,
            'pedido_cupom_id' => $cupom->id,
            'pedido_status' => 'FINALIZADO',
        ]);

        $this->expectException(CupomInvalidoException::class);

        $this->service->validar('PROMO10', 100.0, $cliente);
    }

    public function test_cupom_valido_percentual_calcula_desconto_corretamente(): void
    {
        $this->cupom(['cupom_tipo_desconto' => TipoDescontoEnum::PERCENTUAL->value, 'cupom_valor_desconto' => 20]);

        $resultado = $this->service->validar('PROMO10', 100.0, null);

        $this->assertSame(20.0, $resultado['valor_desconto']);
    }

    public function test_cupom_valido_valor_fixo_calcula_desconto_corretamente(): void
    {
        $this->cupom(['cupom_tipo_desconto' => TipoDescontoEnum::VALOR_FIXO->value, 'cupom_valor_desconto' => 15]);

        $resultado = $this->service->validar('PROMO10', 100.0, null);

        $this->assertSame(15.0, $resultado['valor_desconto']);
    }

    public function test_cupom_valor_fixo_maior_que_pedido_nao_gera_total_negativo(): void
    {
        $this->cupom(['cupom_tipo_desconto' => TipoDescontoEnum::VALOR_FIXO->value, 'cupom_valor_desconto' => 999]);

        $resultado = $this->service->validar('PROMO10', 50.0, null);

        $this->assertSame(50.0, $resultado['valor_desconto']);
    }
}
