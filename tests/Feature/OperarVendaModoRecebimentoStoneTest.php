<?php

namespace Tests\Feature;

use App\Enums\OperadoraMaquininha;
use App\Enums\ProdutoTipoEnum;
use App\Filament\Pages\OperarVenda;
use App\Models\Caixa;
use App\Models\CartoesPagamento;
use App\Models\Categoria;
use App\Models\ItensVenda;
use App\Models\Maquininha;
use App\Models\OpcoesPagamento;
use App\Models\PagamentosVenda;
use App\Models\Produto;
use App\Models\SessaoCaixa;
use App\Models\StonePedido;
use App\Models\User;
use App\Models\Venda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * D1: uma única forma "Crédito" que PODE ser cobrada na Stone. No modal de
 * pagamento do PDV o operador escolhe "Lançar manual" (qualquer maquininha,
 * bandeira e autorização — nunca chama a Stone) ou "Cobrar na Stone".
 */
class OperarVendaModoRecebimentoStoneTest extends TestCase
{
    use RefreshDatabase;

    private Venda $venda;

    private OpcoesPagamento $credito;

    private Maquininha $sunmi;

    private Maquininha $cielo;

    private Maquininha $tectoy;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.stone.secret_key' => 'sk_test_abc',
            'services.stone.service_referer_name' => 'ref-123',
            'services.stone.base_url' => 'https://api.pagar.me/core/v5',
            'services.stone.pedido_direto' => true,
        ]);
        Http::preventStrayRequests();

        $user = User::factory()->admin()->create(['name_first' => 'Admin']);
        $this->actingAs($user);

        $sessao = SessaoCaixa::create([
            'sessaocaixa_caixa_id' => Caixa::create(['caixa_nome' => 'Caixa 1'])->id,
            'sessaocaixa_status' => 'ABERTA',
            'sessaocaixa_data_hora_abertura' => now(),
            'sessaocaixa_saldo_inicial' => 0,
            'sessaocaixa_user_id' => $user->id,
        ]);

        $produto = Produto::create([
            'produto_descricao' => 'Suco',
            'produto_categoria_id' => Categoria::create(['categoria_nome' => 'Bebidas'])->id,
            'produto_tipo' => ProdutoTipoEnum::REVENDA->value,
            'produto_preco_venda' => 20.00,
        ]);

        $this->venda = Venda::create([
            'venda_status' => 'INICIADA',
            'venda_sessao_caixa_id' => $sessao->id,
            'venda_valor_itens' => 20.00,
            'venda_valor_total' => 20.00,
        ]);

        ItensVenda::create([
            'item_numero' => 1,
            'item_venda_venda_id' => $this->venda->id,
            'item_venda_produto_id' => $produto->id,
            'item_venda_quantidade' => 1,
            'item_venda_quantidade_tributavel' => 1,
            'item_venda_valor_unitario' => 20.00,
            'item_venda_desconto' => 0,
            'item_venda_valor' => 20.00,
            'item_venda_valor_base_calculo' => 20.00,
            'item_venda_status' => 'INSERIDO',
        ]);

        $this->credito = OpcoesPagamento::create([
            'opcaopag_nome' => 'Crédito',
            'opcaopag_desc_nfe' => 'creditCard',
            'opcaopag_tipo_taxa' => 'N/A',
            'opcaopag_valor_percentual_taxa' => 0,
            'opcaopag_requer_bandeira' => true,
            'opcaopag_requer_autorizacao' => true,
            'opcaopag_stone_integrada' => true,
        ]);

        $this->sunmi = Maquininha::create(['nome' => 'Sunmi P2-B', 'operadora' => OperadoraMaquininha::Stone, 'maquininha_connect_ativo' => true, 'numero_serie' => 'SUNMI1']);
        $this->tectoy = Maquininha::create(['nome' => 'Tectoy T8', 'operadora' => OperadoraMaquininha::Stone, 'numero_serie' => 'T8-1']);
        $this->cielo = Maquininha::create(['nome' => 'Cielo Flash', 'operadora' => OperadoraMaquininha::Cielo]);
    }

    /** @return array<string, array{string}> */
    public static function maquininhasManuais(): array
    {
        return ['Cielo Flash' => ['cielo'], 'Tectoy T8' => ['tectoy']];
    }

    /**
     * #6 — manual numa maquininha que não é Connect, com forma que permite Stone.
     */
    #[DataProvider('maquininhasManuais')]
    public function test_lancar_manual_grava_pagamento_com_maquininha_bandeira_e_autorizacao_sem_chamar_a_stone(string $qual): void
    {
        $visa = CartoesPagamento::create(['cartao_bandeira' => 'Visa']);

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->set('opcaoPagamentoSelecionadaId', $this->credito->id)
            ->assertSet('modoRecebimento', 'manual')
            ->set('maquininhaPagamentoId', $this->{$qual}->id)
            ->set('cartaoId', $visa->id)
            ->set('numeroAutorizacaoCartao', 'AUT123')
            ->set('valorPagamento', 20.00)
            ->call('registrarPagamento')
            ->assertSet('modalStoneAberta', false);

        $pagamento = PagamentosVenda::where('pg_venda_venda_id', $this->venda->id)->sole();
        $this->assertSame($this->{$qual}->id, $pagamento->pg_venda_maquininha_id);
        $this->assertSame($visa->id, $pagamento->pg_venda_cartao_id);
        $this->assertSame('AUT123', $pagamento->pg_venda_numero_autorizacao_cartao);
        $this->assertNotSame('integrated', $pagamento->pg_venda_tipo_integracao);
        $this->assertSame(0, StonePedido::count());
        Http::assertNothingSent();
    }

    /** #6a — mesma forma, modo "Cobrar na Stone". */
    public function test_cobrar_na_stone_cria_pedido_direto_na_maquininha_connect_sem_lancar_pagamento(): void
    {
        Http::fake([
            'api.pagar.me/core/v5/orders' => Http::response(['id' => 'or_xyz', 'code' => 'ABC1', 'status' => 'pending'], 200),
            'api.pagar.me/*' => Http::response([], 200),
        ]);

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->set('opcaoPagamentoSelecionadaId', $this->credito->id)
            ->set('modoRecebimento', 'stone')
            ->set('stoneMaquininhaId', $this->sunmi->id)
            ->set('valorPagamento', 20.00)
            ->call('registrarPagamento')
            ->assertSet('modalStoneAberta', true)
            ->assertSet('stoneStatusModal', 'aguardando');

        $pedido = StonePedido::sole();
        $this->assertSame('direto', $pedido->stp_modo->value);
        $this->assertSame($this->sunmi->id, $pedido->stp_maquininha_id);
        $this->assertSame($this->credito->id, $pedido->stp_opcaopagamento_id);
        $this->assertSame(0, PagamentosVenda::count());

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->data()['poi_payment_settings']['devices_serial_number'] === ['SUNMI1']);
    }

    /** #6b — forma que não permite Stone, modo forçado. */
    public function test_modo_stone_forcado_com_forma_que_nao_permite_e_recusado_no_servidor(): void
    {
        $dinheiro = OpcoesPagamento::create([
            'opcaopag_nome' => 'Dinheiro', 'opcaopag_desc_nfe' => 'cash', 'opcaopag_tipo_taxa' => 'N/A', 'opcaopag_valor_percentual_taxa' => 0,
        ]);

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->set('opcaoPagamentoSelecionadaId', $dinheiro->id)
            ->set('modoRecebimento', 'stone')
            ->set('stoneMaquininhaId', $this->sunmi->id)
            ->set('valorPagamento', 20.00)
            ->call('registrarPagamento')
            ->assertHasErrors('valorPagamento')
            ->assertSet('modalStoneAberta', false);

        $this->assertSame(0, StonePedido::count());
        $this->assertSame(0, PagamentosVenda::count());
        Http::assertNothingSent();
    }

    /** #6b — maquininha sem connect_ativo. */
    public function test_modo_stone_com_maquininha_sem_connect_ativo_e_recusado_sem_chamar_a_stone(): void
    {
        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->set('opcaoPagamentoSelecionadaId', $this->credito->id)
            ->set('modoRecebimento', 'stone')
            ->set('stoneMaquininhaId', $this->tectoy->id)
            ->set('valorPagamento', 20.00)
            ->call('registrarPagamento')
            ->assertSet('stoneStatusModal', 'erro');

        // A validação recusa antes de gravar o StonePedido: nada fica pendente nem vai para a Stone.
        $this->assertSame(0, PagamentosVenda::count());
        $this->assertSame(0, StonePedido::count());
        Http::assertNothingSent();
    }

    public function test_edicao_de_pagamento_e_sempre_manual_mesmo_com_modo_stone(): void
    {
        $pagamento = PagamentosVenda::create([
            'pg_venda_venda_id' => $this->venda->id,
            'pg_venda_opcaopagamento_id' => $this->credito->id,
            'pg_venda_maquininha_id' => $this->cielo->id,
            'pg_venda_valor_pagamento' => 20, 'pg_venda_valor_recebido' => 20, 'pg_venda_valor_pago_pelo_cliente' => 20,
            'pg_venda_valor_troco' => 0, 'pg_venda_valor_acrescimo' => 0, 'pg_venda_valor_desconto' => 0,
        ]);
        $this->venda->update(['venda_valor_pago' => 20]);

        $componente = Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->call('editarPagamento', $pagamento->id)
            ->assertSet('modoRecebimento', 'manual')
            ->set('modoRecebimento', 'stone')
            ->call('registrarPagamento')
            ->assertHasErrors('valorPagamento');

        $this->assertSame(0, StonePedido::count());
        $this->assertTrue(PagamentosVenda::whereKey($pagamento->id)->exists());
        Http::assertNothingSent();
    }

    public function test_seletor_so_aparece_quando_a_forma_permite_e_ha_maquininha_connect(): void
    {
        $dinheiro = OpcoesPagamento::create([
            'opcaopag_nome' => 'Dinheiro', 'opcaopag_desc_nfe' => 'cash', 'opcaopag_tipo_taxa' => 'N/A', 'opcaopag_valor_percentual_taxa' => 0,
        ]);

        $componente = Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->call('abrirModalPagamento')
            ->set('opcaoPagamentoSelecionadaId', $this->credito->id)
            ->assertSee('Cobrar na Stone')
            ->assertSee('Lançar manual')
            ->set('opcaoPagamentoSelecionadaId', $dinheiro->id)
            ->assertDontSee('Cobrar na Stone');

        $this->sunmi->update(['maquininha_connect_ativo' => false]);

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->call('abrirModalPagamento')
            ->set('opcaoPagamentoSelecionadaId', $this->credito->id)
            ->assertDontSee('Cobrar na Stone');
    }

    public function test_ultima_escolha_do_operador_e_lembrada_na_sessao_so_onde_a_escolha_existe(): void
    {
        $dinheiro = OpcoesPagamento::create([
            'opcaopag_nome' => 'Dinheiro', 'opcaopag_desc_nfe' => 'cash', 'opcaopag_tipo_taxa' => 'N/A', 'opcaopag_valor_percentual_taxa' => 0,
        ]);

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->set('opcaoPagamentoSelecionadaId', $this->credito->id)
            ->set('modoRecebimento', 'stone')
            ->set('opcaoPagamentoSelecionadaId', $dinheiro->id)
            ->assertSet('modoRecebimento', 'manual')
            ->set('opcaoPagamentoSelecionadaId', $this->credito->id)
            ->assertSet('modoRecebimento', 'stone');
    }

    /** #7 — manual funciona sem secret_key e com a Stone fora do ar. */
    public function test_lancar_manual_funciona_sem_secret_key_e_com_a_stone_fora_do_ar(): void
    {
        config(['services.stone.secret_key' => null]);
        Http::fake(['*' => Http::response(['message' => 'indisponível'], 500)]);

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->set('opcaoPagamentoSelecionadaId', $this->credito->id)
            ->set('maquininhaPagamentoId', $this->cielo->id)
            ->set('valorPagamento', 20.00)
            ->call('registrarPagamento')
            ->assertHasNoErrors();

        $this->assertSame(1, PagamentosVenda::count());
        $this->assertSame(0, StonePedido::count());
        Http::assertNothingSent();
    }

    /** G5: duas cobranças de R$ 15 sobre um restante de R$ 20 não passam. */
    public function test_valor_da_cobranca_nao_passa_do_restante_menos_as_pendentes(): void
    {
        Http::fake([
            'api.pagar.me/core/v5/orders' => Http::response(['id' => 'or_5', 'code' => 'rf-2'], 200),
            'api.pagar.me/*' => Http::response([], 200),
        ]);
        StonePedido::create([
            'stp_venda_id' => $this->venda->id, 'stp_maquininha_id' => $this->sunmi->id, 'stp_origem' => 'venda',
            'stp_valor_solicitado' => 15, 'stp_status' => 'aguardando', 'stp_modo' => 'listado', 'stp_order_id' => 'or_pend',
        ]);

        $tela = Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->set('opcaoPagamentoSelecionadaId', $this->credito->id)
            ->set('modoRecebimento', 'stone')
            ->set('stoneMaquininhaId', $this->sunmi->id)
            ->set('valorPagamento', 10.00)
            ->call('registrarPagamento')
            ->assertHasErrors('valorPagamento');

        $this->assertSame(1, StonePedido::count());

        $tela->set('valorPagamento', 5.00)->call('registrarPagamento')->assertHasNoErrors();
        $this->assertSame(2, StonePedido::count());
    }

    /** D4: Direto trava em uma cobrança pendente por venda. */
    public function test_segunda_cobranca_direto_na_mesma_venda_e_recusada(): void
    {
        Http::fake([
            'api.pagar.me/core/v5/orders' => Http::response(['id' => 'or_d1', 'code' => 'rf-1'], 200),
            'api.pagar.me/*' => Http::response([], 200),
        ]);

        $tela = Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->set('opcaoPagamentoSelecionadaId', $this->credito->id)
            ->set('modoRecebimento', 'stone')
            ->set('stoneMaquininhaId', $this->sunmi->id)
            ->set('valorPagamento', 10.00)
            ->call('registrarPagamento')
            ->assertSet('stoneStatusModal', 'aguardando');

        $tela->call('fecharModalStone')
            ->set('opcaoPagamentoSelecionadaId', $this->credito->id)
            ->set('modoRecebimento', 'stone')
            ->set('stoneMaquininhaId', $this->sunmi->id)
            ->set('valorPagamento', 10.00)
            ->call('registrarPagamento')
            ->assertSet('stoneStatusModal', 'erro')
            ->assertSet('stoneErroModal', fn ($m) => str_contains($m, 'Já existe uma cobrança aguardando'));

        $this->assertSame(1, StonePedido::count());
        Http::assertSentCount(1);
    }
}
