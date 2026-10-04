<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Exceptions\ItemNaoDivisivelException;
use App\Filament\Pages\OperarVenda;
use App\Models\AdicionaisItemPedido;
use App\Models\Adicional;
use App\Models\Caixa;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\ItensPedido;
use App\Models\ItensVenda;
use App\Models\Mesa;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\SessaoCaixa;
use App\Models\SessaoMesa;
use App\Models\User;
use App\Models\Venda;
use App\Services\DivisaoItemPedidoService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Nova Venda: lançar só parte de uma mesa/pedido — por item, por pessoa,
 * por rodada ou por algumas unidades de um item.
 */
class OperarVendaItensParciaisTest extends TestCase
{
    use RefreshDatabase;

    private Produto $produto;

    private Venda $venda;

    private SessaoMesa $sessao;

    private Cliente $ana;

    private Cliente $bruno;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->admin()->create(['name_first' => 'Admin']);
        $this->actingAs($user);

        $sessaoCaixa = SessaoCaixa::create([
            'sessaocaixa_caixa_id' => Caixa::create(['caixa_nome' => 'Caixa 1'])->id,
            'sessaocaixa_status' => 'ABERTA',
            'sessaocaixa_data_hora_abertura' => now(),
            'sessaocaixa_saldo_inicial' => 0,
            'sessaocaixa_user_id' => $user->id,
        ]);

        $this->produto = Produto::create([
            'produto_descricao' => 'Cerveja',
            'produto_categoria_id' => Categoria::create(['categoria_nome' => 'Bebidas'])->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 10.00,
            'produto_valor_percentual_icms' => 10,
            'produto_valor_percentual_pis' => 0,
            'produto_valor_percentual_cofins' => 0,
        ]);

        $this->venda = Venda::create(['venda_status' => 'INICIADA', 'venda_sessao_caixa_id' => $sessaoCaixa->id]);

        $mesa = Mesa::create(['mesa_nome' => 'Mesa 5', 'mesa_status' => 'OCUPADA']);
        $this->sessao = SessaoMesa::create([
            'sessao_mesa_mesa_id' => $mesa->id,
            'sessao_mesa_usuario_id' => $user->id,
            'sessao_mesa_status' => 'ABERTA',
            'sessao_mesa_taxa_servico_percentual' => 10,
        ]);

        $this->ana = Cliente::create(['cliente_nome' => 'Ana', 'cliente_tipo' => 'Física']);
        $this->bruno = Cliente::create(['cliente_nome' => 'Bruno', 'cliente_tipo' => 'Física']);
    }

    private function pedido(): Pedido
    {
        return Pedido::create(['pedido_sessao_mesa_id' => $this->sessao->id, 'pedido_status' => 'ENTREGUE']);
    }

    private function item(Pedido $pedido, float $quantidade = 1, ?Cliente $cliente = null, float $desconto = 0): ItensPedido
    {
        return ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $this->produto->id,
            'item_pedido_cliente_id' => $cliente?->id,
            'item_pedido_quantidade' => $quantidade,
            'item_pedido_valor_unitario' => 10.00,
            'item_pedido_desconto' => $desconto,
            'item_pedido_valor_adicionais' => 0,
            'item_pedido_valor' => 10.00 * $quantidade - $desconto,
            'item_pedido_status' => 'INSERIDO',
        ]);
    }

    private function pdv(): Testable
    {
        return Livewire::test(OperarVenda::class, ['venda' => $this->venda]);
    }

    public function test_marcar_um_item_lanca_so_ele_com_taxa_proporcional_e_desmarcar_retira(): void
    {
        $pedido = $this->pedido();
        $daAna = $this->item($pedido, 2, $this->ana);
        $doBruno = $this->item($pedido, 1, $this->bruno);

        $pdv = $this->pdv()->call('alternarItemPedido', $daAna->id);

        $this->assertSame($this->venda->id, $daAna->fresh()->item_pedido_venda_id);
        $this->assertNull($doBruno->fresh()->item_pedido_venda_id);
        $this->assertSame($daAna->fresh()->item_pedido_item_venda_id, ItensVenda::where('item_venda_venda_id', $this->venda->id)->value('id'));
        $this->assertEquals(2.00, $this->venda->fresh()->venda_valor_taxa_servico);
        $this->assertEquals(22.00, $this->venda->fresh()->venda_valor_total);

        $pdv->call('alternarItemPedido', $daAna->id);

        $this->assertNull($daAna->fresh()->item_pedido_venda_id);
        $this->assertNull($daAna->fresh()->item_pedido_item_venda_id);
        $this->assertSame(0, ItensVenda::where('item_venda_venda_id', $this->venda->id)->count());
        $this->assertEquals(0, $this->venda->fresh()->venda_valor_total);
    }

    public function test_chip_da_pessoa_lanca_so_os_itens_dela_e_segundo_clique_retira(): void
    {
        $primeira = $this->pedido();
        $segunda = $this->pedido();
        $this->item($primeira, 1, $this->ana);
        $this->item($segunda, 1, $this->ana);
        $doBruno = $this->item($primeira, 1, $this->bruno);
        $semPessoa = $this->item($segunda, 1);

        $pdv = $this->pdv()->call('alternarClienteDaMesa', $this->sessao->id, $this->ana->id);

        $this->assertSame(2, ItensPedido::where('item_pedido_venda_id', $this->venda->id)->count());
        $this->assertNull($doBruno->fresh()->item_pedido_venda_id);
        $this->assertSame($this->ana->id, $this->venda->fresh()->venda_cliente_id);

        $pdv->call('alternarClienteDaMesa', $this->sessao->id, 'sem_cliente');
        $this->assertSame($this->venda->id, $semPessoa->fresh()->item_pedido_venda_id);

        $pdv->call('alternarClienteDaMesa', $this->sessao->id, $this->ana->id);
        $this->assertSame([$semPessoa->id], ItensPedido::where('item_pedido_venda_id', $this->venda->id)->pluck('id')->all());
    }

    public function test_checkbox_da_rodada_lanca_e_retira_so_aquele_pedido(): void
    {
        $primeira = $this->pedido();
        $segunda = $this->pedido();
        $this->item($primeira, 1);
        $this->item($primeira, 2);
        $outraRodada = $this->item($segunda, 1);

        $pdv = $this->pdv()->call('alternarPedido', $primeira->id);

        $this->assertSame(2, ItensPedido::where('item_pedido_venda_id', $this->venda->id)->count());
        $this->assertNull($outraRodada->fresh()->item_pedido_venda_id);

        $pdv->call('alternarPedido', $primeira->id);
        $this->assertSame(0, ItensPedido::where('item_pedido_venda_id', $this->venda->id)->count());
    }

    public function test_item_ja_em_outra_venda_nao_entra_nesta(): void
    {
        $item = $this->item($this->pedido());
        $outraVenda = Venda::create(['venda_status' => 'INICIADA']);
        $item->update(['item_pedido_venda_id' => $outraVenda->id]);

        $this->pdv()
            ->call('alternarItemPedido', $item->id)
            ->call('lancarItensDaMesa', $this->sessao->id);

        $this->assertSame($outraVenda->id, $item->fresh()->item_pedido_venda_id);
        $this->assertSame(0, ItensVenda::where('item_venda_venda_id', $this->venda->id)->count());
    }

    public function test_mesa_com_parte_em_outra_venda_aparece_lancada_quando_o_resto_esta_nesta(): void
    {
        $pedido = $this->pedido();
        $pago = $this->item($pedido, 1, $this->ana);
        $this->item($pedido, 1, $this->bruno);
        $pago->update(['item_pedido_venda_id' => Venda::create(['venda_status' => 'FINALIZADA'])->id]);

        $pdv = $this->pdv()->call('lancarItensDaMesa', $this->sessao->id);

        $itens = $pdv->instance()->mesas->first()->pedidos->flatMap->item_pedido_pedido_id;
        $this->assertTrue($pdv->instance()->itensEstaoLancadosNestaVenda($itens));
        $this->assertSame('paga', $pdv->instance()->situacaoDoItem($itens->firstWhere('id', $pago->id)));
    }

    public function test_lixeira_do_carrinho_libera_o_item_do_pedido(): void
    {
        $item = $this->item($this->pedido(), 2);

        $pdv = $this->pdv()->call('lancarItensDaMesa', $this->sessao->id);
        $linha = ItensVenda::where('item_venda_venda_id', $this->venda->id)->sole();

        $pdv->call('removerItemCarrinho', $linha->id);

        $this->assertNull($item->fresh()->item_pedido_venda_id);
        $this->assertEquals(0, $this->venda->fresh()->venda_valor_taxa_servico);

        $pdv->call('alternarItemPedido', $item->id);
        $this->assertSame($this->venda->id, $item->fresh()->item_pedido_venda_id);
    }

    public function test_lixeira_libera_item_lancado_antes_do_vinculo_pela_busca_por_produto(): void
    {
        $item = $this->item($this->pedido());
        $this->pdv()->call('lancarItensDaMesa', $this->sessao->id);
        $item->update(['item_pedido_item_venda_id' => null]);

        $this->pdv()->call('removerItemCarrinho', ItensVenda::where('item_venda_venda_id', $this->venda->id)->value('id'));

        $this->assertNull($item->fresh()->item_pedido_venda_id);
    }

    public function test_retirar_item_abate_a_linha_certa_quando_ha_duas_do_mesmo_produto(): void
    {
        $pedido = $this->pedido();
        $simples = $this->item($pedido);
        $comAdicional = $this->item($pedido);
        $adicional = Adicional::create(['adicional_nome' => 'Limão', 'adicional_valor' => 2.00]);
        AdicionaisItemPedido::create([
            'aip_item_pedido_id' => $comAdicional->id,
            'aip_adicional_id' => $adicional->id,
            'aip_quantidade' => 1,
            'aip_valor_unitario' => 2.00,
            'aip_valor_total' => 2.00,
        ]);
        $comAdicional->update(['item_pedido_valor_adicionais' => 2.00, 'item_pedido_valor' => 12.00]);

        $pdv = $this->pdv()->call('lancarItensDaMesa', $this->sessao->id);
        $this->assertSame(2, ItensVenda::where('item_venda_venda_id', $this->venda->id)->count());

        $pdv->call('alternarItemPedido', $comAdicional->id);

        $restante = ItensVenda::where('item_venda_venda_id', $this->venda->id)->sole();
        $this->assertSame($simples->fresh()->item_pedido_item_venda_id, $restante->id);
        $this->assertEquals(10.00, $restante->item_venda_valor);
        $this->assertEquals(0, $restante->item_venda_valor_adicionais);
    }

    public function test_dividir_lanca_parte_das_unidades_e_as_linhas_somam_o_original(): void
    {
        $item = $this->item($this->pedido(), 3, $this->ana, desconto: 1.00);

        $this->pdv()
            ->callAction(TestAction::make('dividirItem')->arguments(['item' => $item->id]), ['quantidade' => 1])
            ->assertHasNoActionErrors();

        $restante = $item->fresh();
        $novo = ItensPedido::where('item_pedido_pedido_id', $item->item_pedido_pedido_id)->whereKeyNot($item->id)->sole();

        $this->assertEquals(2, $restante->item_pedido_quantidade);
        $this->assertNull($restante->item_pedido_venda_id);
        $this->assertEquals(1, $novo->item_pedido_quantidade);
        $this->assertSame($this->venda->id, $novo->item_pedido_venda_id);
        $this->assertSame($this->ana->id, $novo->item_pedido_cliente_id);
        $this->assertEquals(29.00, round($restante->item_pedido_valor + $novo->item_pedido_valor, 2));
        $this->assertEquals(1.00, round($restante->item_pedido_desconto + $novo->item_pedido_desconto, 2));
        $this->assertEquals($novo->item_pedido_valor, $this->venda->fresh()->venda_valor_total - $this->venda->fresh()->venda_valor_taxa_servico);
    }

    public function test_dividir_reparte_os_adicionais(): void
    {
        $item = $this->item($this->pedido(), 3);
        $adicional = Adicional::create(['adicional_nome' => 'Limão', 'adicional_valor' => 1.00]);
        AdicionaisItemPedido::create([
            'aip_item_pedido_id' => $item->id,
            'aip_adicional_id' => $adicional->id,
            'aip_quantidade' => 3,
            'aip_valor_unitario' => 1.00,
            'aip_valor_total' => 3.00,
        ]);
        $item->update(['item_pedido_valor_adicionais' => 3.00, 'item_pedido_valor' => 33.00]);

        $novo = app(DivisaoItemPedidoService::class)->dividir($item, 2);

        $this->assertEquals(22.00, $novo->item_pedido_valor);
        $this->assertEquals(2.00, $novo->item_pedido_valor_adicionais);
        $this->assertEquals(11.00, $item->fresh()->item_pedido_valor);
        $this->assertEquals(2, $novo->adicionaisItemPedido()->value('aip_quantidade'));
        $this->assertEquals(1.00, $item->adicionaisItemPedido()->value('aip_valor_total'));
    }

    public function test_dividir_recusa_quantidade_invalida_item_promocional_e_item_ja_lancado(): void
    {
        $servico = app(DivisaoItemPedidoService::class);
        $item = $this->item($this->pedido(), 3);

        foreach ([0, 3, 1.5] as $quantidade) {
            try {
                $servico->dividir($item, $quantidade);
                $this->fail("Aceitou dividir {$quantidade} de 3.");
            } catch (ItemNaoDivisivelException) {
                $this->assertEquals(3, $item->fresh()->item_pedido_quantidade);
            }
        }

        $promocional = $this->item($this->pedido(), 2);
        $promocional->forceFill(['item_pedido_origem_id' => $item->id])->save();
        $this->expectException(ItemNaoDivisivelException::class);
        $servico->dividir($promocional, 1);
    }

    public function test_pedido_avulso_permite_lancar_um_item_e_continua_na_lista_com_o_resto(): void
    {
        $pedido = Pedido::create(['pedido_status' => 'ABERTO']);
        $primeiro = $this->item($pedido);
        $segundo = $this->item($pedido);

        $pdv = $this->pdv()->call('alternarItemPedido', $primeiro->id);

        $this->assertSame($this->venda->id, $primeiro->fresh()->item_pedido_venda_id);
        $this->assertNull($segundo->fresh()->item_pedido_venda_id);

        // Numa venda nova, o pedido continua disponível com o item restante.
        $outraVenda = Venda::create(['venda_status' => 'INICIADA']);
        $ids = Livewire::test(OperarVenda::class, ['venda' => $outraVenda])->instance()->pedidosAvulsos->pluck('id');
        $this->assertContains($pedido->id, $ids);
    }

    public function test_abas_mostram_chips_rodadas_selos_e_dividir(): void
    {
        $pedido = $this->pedido();
        $this->item($pedido, 3, $this->ana);
        $pago = $this->item($pedido, 1, $this->bruno);
        $pago->update(['item_pedido_venda_id' => Venda::create(['venda_status' => 'FINALIZADA'])->id]);
        $avulso = Pedido::create(['pedido_status' => 'ABERTO']);
        $this->item($avulso, 2);

        $this->pdv()
            ->set('abaAtiva', 'mesas')
            ->assertSeeText(['Mesa inteira', 'Ana · R$ 30,00', "Pedido #{$pedido->id}", 'pago', 'Dividir', 'A cobrar: R$ 30,00'])
            ->assertDontSeeText('Bruno · R$')
            ->set('abaAtiva', 'pedidos')
            ->assertSeeText(["Pedido #{$avulso->id}", 'Dividir']);
    }
}
