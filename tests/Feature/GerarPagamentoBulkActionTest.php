<?php

namespace Tests\Feature;

use App\Enums\FormaPagamentoComissaoEnum;
use App\Enums\OrigemCupomEnum;
use App\Enums\StatusComissaoEnum;
use App\Enums\TipoDescontoEnum;
use App\Filament\Resources\Comissoes\Pages\ManageComissoes;
use App\Models\Cliente;
use App\Models\Comissao;
use App\Models\Cupom;
use App\Models\Influenciador;
use App\Models\Pedido;
use App\Models\User;
use App\Services\PagamentoComissaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use Tests\TestCase;

class GerarPagamentoBulkActionTest extends TestCase
{
    use RefreshDatabase;

    private PagamentoComissaoService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new PagamentoComissaoService;
    }

    private function influenciador(): Influenciador
    {
        return Influenciador::create([
            'influenciador_nome' => 'Fulano',
            'email' => uniqid().'@teste.com',
            'password' => 'senha-segura',
        ]);
    }

    private function comissaoAprovada(Influenciador $influenciador, float $valor = 10.0): Comissao
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
            'pedido_valor_total' => 100.0,
            'pedido_status' => 'FINALIZADO',
        ]);

        $comissao = Comissao::where('comissao_pedido_id', $pedido->id)->firstOrFail();
        $comissao->forceFill([
            'comissao_status' => StatusComissaoEnum::APROVADA,
            'comissao_aprovada_em' => now(),
            'comissao_valor' => $valor,
        ])->save();

        return $comissao;
    }

    public function test_gera_pagamento_pix_e_marca_comissoes_como_pagas(): void
    {
        $influenciador = $this->influenciador();
        $c1 = $this->comissaoAprovada($influenciador, 10.0);
        $c2 = $this->comissaoAprovada($influenciador, 15.0);

        $pagamento = $this->service->gerar(
            new Collection([$c1, $c2]),
            FormaPagamentoComissaoEnum::PIX,
        );

        $this->assertSame(25.0, (float) $pagamento->pgcom_valor_total);
        $this->assertSame(StatusComissaoEnum::PAGA, $c1->fresh()->comissao_status);
        $this->assertSame(StatusComissaoEnum::PAGA, $c2->fresh()->comissao_status);
        $this->assertSame($pagamento->id, $c1->fresh()->comissao_pgcom_id);
    }

    public function test_forma_credito_pizza_cria_cupom_de_credito_vinculado(): void
    {
        $influenciador = $this->influenciador();
        $c1 = $this->comissaoAprovada($influenciador, 20.0);

        $pagamento = $this->service->gerar(
            new Collection([$c1]),
            FormaPagamentoComissaoEnum::CREDITO_PIZZA,
        );

        $this->assertNotNull($pagamento->pgcom_cupom_credito_id);

        $cupom = Cupom::findOrFail($pagamento->pgcom_cupom_credito_id);
        $this->assertSame(OrigemCupomEnum::CREDITO_COMISSAO, $cupom->cupom_origem);
        $this->assertSame(TipoDescontoEnum::VALOR_FIXO, $cupom->cupom_tipo_desconto);
        $this->assertSame(20.0, (float) $cupom->cupom_valor_desconto);
        $this->assertSame(1, $cupom->cupom_limite_uso_total);
        $this->assertSame($influenciador->id, $cupom->cupom_influenciador_id);
    }

    public function test_rejeita_comissoes_de_influenciadores_diferentes(): void
    {
        $c1 = $this->comissaoAprovada($this->influenciador());
        $c2 = $this->comissaoAprovada($this->influenciador());

        $this->expectException(\InvalidArgumentException::class);

        $this->service->gerar(new Collection([$c1, $c2]), FormaPagamentoComissaoEnum::PIX);
    }

    public function test_rejeita_comissao_nao_aprovada(): void
    {
        $influenciador = $this->influenciador();
        $comissao = $this->comissaoAprovada($influenciador);
        $comissao->forceFill(['comissao_status' => StatusComissaoEnum::PENDENTE])->save();

        $this->expectException(\InvalidArgumentException::class);

        $this->service->gerar(new Collection([$comissao]), FormaPagamentoComissaoEnum::PIX);
    }

    public function test_admin_gera_pagamento_pela_bulk_action(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));

        $influenciador = $this->influenciador();
        $comissao = $this->comissaoAprovada($influenciador, 10.0);

        Livewire::test(ManageComissoes::class)
            ->callTableBulkAction('gerarPagamento', [$comissao], ['forma' => FormaPagamentoComissaoEnum::PIX->value])
            ->assertHasNoTableBulkActionErrors();

        $this->assertSame(StatusComissaoEnum::PAGA, $comissao->fresh()->comissao_status);
    }
}
