<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Enums\StatusComissaoEnum;
use App\Enums\StatusInfluenciadorEnum;
use App\Enums\TipoDescontoEnum;
use App\Filament\Pages\AtenderPedido;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Comissao;
use App\Models\Cupom;
use App\Models\Influenciador;
use App\Models\ItensPedido;
use App\Models\OpcoesPagamento;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\User;
use App\Models\Venda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** F1.6 (D3): cupom informado ao colaborador no atendimento de pedido. */
class AtenderPedidoCupomTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
    }

    private function produto(float $preco = 100.0): Produto
    {
        return Produto::create([
            'produto_descricao' => 'Calabresa',
            'produto_categoria_id' => Categoria::firstOrCreate(['categoria_nome' => 'Pizzas'])->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => $preco,
        ]);
    }

    private function cupom(string $codigo, float $valor, ?Influenciador $dono = null): Cupom
    {
        return Cupom::create([
            'cupom_influenciador_id' => $dono?->id,
            'cupom_codigo' => $codigo,
            'cupom_tipo_desconto' => TipoDescontoEnum::PERCENTUAL->value,
            'cupom_valor_desconto' => $valor,
        ]);
    }

    private function influenciador(): Influenciador
    {
        $influenciador = Influenciador::create([
            'influenciador_nome' => 'Fulano',
            'email' => uniqid().'@teste.com',
            'password' => 'senha-segura',
            'influenciador_valor_comissao' => 10,
        ]);
        $influenciador->forceFill(['influenciador_status' => StatusInfluenciadorEnum::ATIVO])->save();

        return $influenciador;
    }

    /** @return array{0: Pedido, 1: ItensPedido} */
    private function pedidoAberto(): array
    {
        $produto = $this->produto(100);
        $pedido = Pedido::create([
            'pedido_cliente_id' => Cliente::create(['cliente_nome' => 'Cliente', 'cliente_tipo' => 'PF'])->id,
            'pedido_status' => 'ABERTO',
            'pedido_valor_itens' => 100,
            'pedido_valor_total' => 100,
        ]);
        $item = ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $produto->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => 100,
            'item_pedido_valor' => 100,
            'item_pedido_desconto' => 0,
            'item_pedido_status' => 'INSERIDO',
        ]);

        return [$pedido, $item];
    }

    public function test_aplicar_no_pedido_em_edicao_grava_o_desconto_e_gera_a_comissao_pendente(): void
    {
        $dono = $this->influenciador();
        $this->cupom('FULANO10', 10, $dono);
        [$pedido] = $this->pedidoAberto();

        Livewire::test(AtenderPedido::class, ['pedido' => $pedido])
            ->set('cupomCodigo', 'fulano10')
            ->call('aplicarCupom')
            ->assertSet('cupomCodigo', 'FULANO10');

        $pedido->refresh();
        $this->assertSame('90.00', $pedido->pedido_valor_total);
        $this->assertSame('10.00', $pedido->pedido_valor_desconto_cupom);

        $comissao = Comissao::where('comissao_pedido_id', $pedido->id)->sole();
        $this->assertSame($dono->id, $comissao->comissao_influenciador_id);
        $this->assertSame(StatusComissaoEnum::PENDENTE, $comissao->comissao_status);
    }

    public function test_trocar_de_cupom_recalcula_o_desconto_e_a_comissao(): void
    {
        $primeiro = $this->influenciador();
        $segundo = $this->influenciador();
        $this->cupom('PRIMEIRO', 10, $primeiro);
        $this->cupom('SEGUNDO', 20, $segundo);
        [$pedido] = $this->pedidoAberto();

        $tela = Livewire::test(AtenderPedido::class, ['pedido' => $pedido])
            ->set('cupomCodigo', 'PRIMEIRO')
            ->call('aplicarCupom')
            ->call('removerCupom');

        $this->assertSame('100.00', $pedido->fresh()->pedido_valor_total);
        $this->assertNull($pedido->fresh()->pedido_cupom_id);
        $this->assertSame(StatusComissaoEnum::CANCELADA, Comissao::sole()->comissao_status);

        $tela->set('cupomCodigo', 'SEGUNDO')->call('aplicarCupom');

        $pedido->refresh();
        $this->assertSame('80.00', $pedido->pedido_valor_total);
        $comissao = Comissao::where('comissao_pedido_id', $pedido->id)->sole();
        $this->assertSame($segundo->id, $comissao->comissao_influenciador_id);
        $this->assertSame(StatusComissaoEnum::PENDENTE, $comissao->comissao_status);
    }

    public function test_cupom_invalido_no_atendimento_nao_altera_o_pedido(): void
    {
        [$pedido] = $this->pedidoAberto();

        Livewire::test(AtenderPedido::class, ['pedido' => $pedido])
            ->set('cupomCodigo', 'NAOEXISTE')
            ->call('aplicarCupom')
            ->assertSet('cupomId', null);

        $this->assertSame('100.00', $pedido->fresh()->pedido_valor_total);
        $this->assertNull($pedido->fresh()->pedido_cupom_id);
    }

    public function test_pedido_com_venda_nao_aceita_troca_de_cupom(): void
    {
        $this->cupom('DESC10', 10);
        $this->cupom('DESC20', 20);
        [$pedido, $item] = $this->pedidoAberto();

        $tela = Livewire::test(AtenderPedido::class, ['pedido' => $pedido])
            ->set('cupomCodigo', 'DESC10')
            ->call('aplicarCupom');

        $item->update(['item_pedido_venda_id' => Venda::create(['venda_status' => 'INICIADA'])->id]);

        $tela->set('cupomCodigo', 'DESC20')->call('removerCupom')->call('aplicarCupom');

        $pedido->refresh();
        $this->assertSame('90.00', $pedido->pedido_valor_total);
        $this->assertSame('DESC10', $pedido->cupom->cupom_codigo);
    }

    public function test_pedido_novo_aplica_o_cupom_ao_salvar_e_o_total_bate_com_o_pagamento(): void
    {
        $dono = $this->influenciador();
        $this->cupom('FULANO10', 10, $dono);
        $produto = $this->produto(50);
        $dinheiro = OpcoesPagamento::create(['opcaopag_nome' => 'Dinheiro', 'opcaopag_desc_nfe' => 'cash']);

        Livewire::test(AtenderPedido::class)
            ->set('itensCarrinho', [[
                'produto_id' => $produto->id, 'quantidade' => 2, 'valor_unitario' => 50.0,
                'desconto' => 0, 'valor' => 100.0, 'adicionais_valor' => 0, 'observacao' => '', 'adicionais' => [],
            ]])
            ->set('cupomCodigo', 'FULANO10')
            ->call('aplicarCupom')
            ->assertSet('cupomId', Cupom::sole()->id)
            ->set('clienteData', ['semCliente' => true])
            ->set('entregaPagamentoData', [
                'opcaoEntregaId' => null,
                // 100 - 10% de cupom: o pagamento combinado precisa fechar com o total já descontado.
                'pagamentos' => [['opcaoPagamentoId' => $dinheiro->id, 'valor' => '90,00', 'trocoPara' => null]],
            ])
            ->call('save')
            ->assertHasNoErrors();

        $pedido = Pedido::sole();
        $this->assertSame('90.00', $pedido->pedido_valor_total);
        $this->assertSame('100.00', $pedido->pedido_valor_itens);
        $this->assertSame('10.00', $pedido->pedido_valor_desconto_cupom);
        $this->assertSame('10.00', ItensPedido::sole()->item_pedido_desconto_cupom);
        $this->assertSame(StatusComissaoEnum::PENDENTE, Comissao::sole()->comissao_status);
    }

    public function test_cupom_de_influenciador_inativo_nao_e_aceito_no_atendimento(): void
    {
        $dono = $this->influenciador();
        $this->cupom('FULANO10', 10, $dono);
        $dono->forceFill(['influenciador_status' => StatusInfluenciadorEnum::INATIVO])->save();
        [$pedido] = $this->pedidoAberto();

        Livewire::test(AtenderPedido::class, ['pedido' => $pedido])
            ->set('cupomCodigo', 'FULANO10')
            ->call('aplicarCupom')
            ->assertSet('cupomId', null);

        $this->assertSame('100.00', $pedido->fresh()->pedido_valor_total);
    }
}
