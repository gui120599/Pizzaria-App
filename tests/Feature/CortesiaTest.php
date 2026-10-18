<?php

namespace Tests\Feature;

use App\Enums\MovimentacaoOrigemEnum;
use App\Enums\ProdutoTipoEnum;
use App\Exceptions\AutorizacaoNegadaException;
use App\Exceptions\NfeIoException;
use App\Filament\Pages\AtenderPedido;
use App\Filament\Pages\OperarVenda;
use App\Filament\Pages\RelatorioCortesias;
use App\Models\AutorizacaoGerente;
use App\Models\Caixa;
use App\Models\Categoria;
use App\Models\Empresa;
use App\Models\ItensPedido;
use App\Models\ItensVenda;
use App\Models\MovimentacaoProduto;
use App\Models\OpcoesPagamento;
use App\Models\PagamentosVenda;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\SessaoCaixa;
use App\Models\User;
use App\Models\Venda;
use App\Services\CortesiaService;
use App\Services\FinalizacaoVendaService;
use App\Services\LancamentoItensVendaService;
use App\Services\Nfe\EmissaoNfeAutomaticaService;
use App\Services\NfeIoService;
use App\Services\VendaService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Cortesia: sai do estoque (origem CORTESIA), não é cobrada e fica fora da
 * NFC-e — diferente do desconto de 100%, que a SEFAZ rejeita.
 */
class CortesiaTest extends TestCase
{
    use RefreshDatabase;

    private User $gerente;

    private Produto $pizza;

    private Produto $refri;

    private Venda $venda;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gerente = User::factory()->gerente()->create(['name_first' => 'Gil', 'name' => 'Gil']);
        $admin = User::factory()->admin()->create(['name_first' => 'Admin', 'name' => 'Admin']);
        $this->actingAs($admin);

        $sessao = SessaoCaixa::create([
            'sessaocaixa_caixa_id' => Caixa::create(['caixa_nome' => 'Caixa 1'])->id,
            'sessaocaixa_status' => 'ABERTA',
            'sessaocaixa_data_hora_abertura' => now(),
            'sessaocaixa_saldo_inicial' => 0,
            'sessaocaixa_user_id' => $admin->id,
        ]);
        $this->venda = Venda::create(['venda_status' => 'INICIADA', 'venda_sessao_caixa_id' => $sessao->id]);

        $categoria = Categoria::create(['categoria_nome' => 'Geral']);
        $this->pizza = Produto::create([
            'produto_descricao' => 'Calabresa', 'produto_categoria_id' => $categoria->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value, 'produto_preco_venda' => 40.00,
        ]);
        $this->refri = Produto::create([
            'produto_descricao' => 'Refrigerante', 'produto_categoria_id' => $categoria->id,
            'produto_tipo' => ProdutoTipoEnum::REVENDA->value, 'produto_preco_venda' => 10.00,
            'produto_controla_estoque' => true, 'produto_saldo_estoque' => 9, 'produto_custo_medio' => 3,
        ]);
    }

    private function pedido(Produto $produto, int $quantidade = 1, string $status = 'ABERTO'): array
    {
        $pedido = Pedido::create(['pedido_status' => $status, 'pedido_valor_total' => $produto->produto_preco_venda * $quantidade]);
        $item = ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $produto->id,
            'item_pedido_quantidade' => $quantidade,
            'item_pedido_valor_unitario' => $produto->produto_preco_venda,
            'item_pedido_desconto' => 0,
            'item_pedido_valor_adicionais' => 0,
            'item_pedido_valor' => $produto->produto_preco_venda * $quantidade,
            'item_pedido_status' => 'INSERIDO',
        ]);

        return [$pedido, $item];
    }

    private function service(): CortesiaService
    {
        return app(CortesiaService::class);
    }

    public function test_gerente_concede_cortesia_no_item_sem_virar_desconto(): void
    {
        [$pedido, $item] = $this->pedido($this->pizza, 2);

        $this->service()->concederItem($item, $this->gerente, null, null, 'Cliente do dia');

        $item->refresh();
        $this->assertTrue($item->item_pedido_cortesia);
        $this->assertSame('0.00', number_format((float) $item->item_pedido_valor, 2, '.', ''));
        $this->assertEqualsWithDelta(40.0, (float) $item->item_pedido_valor_unitario, 0.001);
        $this->assertEqualsWithDelta(80.0, (float) $item->item_pedido_desconto, 0.001);
        $this->assertSame('Cliente do dia', $item->item_pedido_cortesia_motivo);
        $this->assertSame($this->gerente->id, $item->item_pedido_cortesia_user_id);

        $pedido->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $pedido->pedido_valor_total, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $pedido->pedido_valor_desconto, 0.001);
        $this->assertSame(1, AutorizacaoGerente::count());
    }

    public function test_garcom_sem_permissao_precisa_do_pin_de_gerente(): void
    {
        [, $item] = $this->pedido($this->pizza);
        $garcom = User::factory()->garcom()->create(['name_first' => 'Zé', 'name' => 'Zé']);
        $gerentePin = User::factory()->gerente()->comPin('1234')->create(['name_first' => 'Ana', 'name' => 'Ana']);

        try {
            $this->service()->concederItem($item, $garcom, null, null, 'Aniversário');
            $this->fail('Deveria exigir autorização.');
        } catch (AutorizacaoNegadaException) {
        }
        $this->assertFalse($item->fresh()->item_pedido_cortesia);

        $this->service()->concederItem($item, $garcom, $gerentePin->id, '1234', 'Aniversário');

        $this->assertTrue($item->fresh()->item_pedido_cortesia);
        $this->assertSame($gerentePin->id, $item->fresh()->item_pedido_cortesia_user_id);
        $this->assertSame($garcom->id, AutorizacaoGerente::first()->autorizacao_solicitante_id);
    }

    public function test_motivo_e_obrigatorio_e_item_na_venda_nao_muda(): void
    {
        [, $item] = $this->pedido($this->pizza);

        try {
            $this->service()->concederItem($item, $this->gerente, null, null, ' ');
            $this->fail('Motivo é obrigatório.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('motivo', $e->getMessage());
        }

        $item->update(['item_pedido_venda_id' => $this->venda->id]);
        $this->expectException(RuntimeException::class);
        $this->service()->concederItem($item, $this->gerente, null, null, 'Teste');
    }

    public function test_desfazer_cortesia_restaura_valor_e_total(): void
    {
        [$pedido, $item] = $this->pedido($this->pizza);
        $this->service()->concederItem($item, $this->gerente, null, null, 'Teste');

        $this->service()->desfazerItem($item->fresh(), $this->gerente, null, null);

        $item->refresh();
        $this->assertFalse($item->item_pedido_cortesia);
        $this->assertNull($item->item_pedido_cortesia_motivo);
        $this->assertEqualsWithDelta(40.0, (float) $item->item_pedido_valor, 0.001);
        $this->assertEqualsWithDelta(40.0, (float) $pedido->fresh()->pedido_valor_total, 0.001);
    }

    public function test_pedido_inteiro_vira_cortesia(): void
    {
        [$pedido, $item] = $this->pedido($this->pizza);
        $outro = ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id, 'item_pedido_produto_id' => $this->refri->id,
            'item_pedido_quantidade' => 1, 'item_pedido_valor_unitario' => 10, 'item_pedido_desconto' => 0,
            'item_pedido_valor_adicionais' => 0, 'item_pedido_valor' => 10, 'item_pedido_status' => 'INSERIDO',
        ]);

        $this->service()->concederPedido($pedido, $this->gerente, null, null, 'Reposição');

        $this->assertTrue($item->fresh()->item_pedido_cortesia);
        $this->assertTrue($outro->fresh()->item_pedido_cortesia);
        $this->assertSame(1, AutorizacaoGerente::count());
    }

    public function test_estoque_baixa_com_origem_cortesia_pelo_custo_medio(): void
    {
        [$pedido, $item] = $this->pedido($this->refri, 2);
        $this->service()->concederItem($item, $this->gerente, null, null, 'Cortesia da casa');

        $pedido->update(['pedido_status' => 'PREPARANDO']);

        $saida = MovimentacaoProduto::where('mov_produto_id', $this->refri->id)->first();
        $this->assertSame(MovimentacaoOrigemEnum::CORTESIA, $saida->mov_origem);
        $this->assertEqualsWithDelta(2.0, (float) $saida->mov_quantidade, 0.001);
        $this->assertEqualsWithDelta(3.0, (float) $saida->mov_custo_unitario, 0.001);
        $this->assertEqualsWithDelta(7.0, (float) $this->refri->fresh()->produto_saldo_estoque, 0.001);
    }

    public function test_lancar_na_venda_nao_mescla_cortesia_com_item_cobrado(): void
    {
        [, $cortesia] = $this->pedido($this->pizza);
        [, $cobrado] = $this->pedido($this->pizza);
        $this->service()->concederItem($cortesia, $this->gerente, null, null, 'Teste');

        app(LancamentoItensVendaService::class)->lancarItens($this->venda, collect([$cortesia->fresh(), $cobrado->fresh()]));
        app(VendaService::class)->atualizarValoresdaVenda($this->venda->id);

        $linhas = ItensVenda::where('item_venda_venda_id', $this->venda->id)->orderBy('id')->get();
        $this->assertCount(2, $linhas);
        $this->assertTrue($linhas[0]->item_venda_cortesia);
        $this->assertEqualsWithDelta(40.0, (float) $linhas[0]->item_venda_valor_tabela, 0.001);
        $this->assertFalse($linhas[1]->item_venda_cortesia);

        $venda = $this->venda->fresh();
        $this->assertEqualsWithDelta(40.0, (float) $venda->venda_valor_total, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $venda->venda_valor_desconto, 0.001);
    }

    private function vendaComCortesiaEItemCobrado(): Venda
    {
        $empresa = Empresa::create(['empresa_razao_social' => 'Pizzaria LTDA', 'empresa_cnpj' => '11222333000181', 'empresa_api_nfeio_company_id' => 'c', 'empresa_api_nfeio_apikey' => 'k']);
        [, $cortesia] = $this->pedido($this->pizza);
        [, $cobrado] = $this->pedido($this->refri);
        $this->service()->concederItem($cortesia, $this->gerente, null, null, 'Teste');
        app(LancamentoItensVendaService::class)->lancarItens($this->venda, collect([$cortesia->fresh(), $cobrado->fresh()]));
        app(VendaService::class)->atualizarValoresdaVenda($this->venda->id);

        $pix = OpcoesPagamento::create(['opcaopag_nome' => 'Pix', 'opcaopag_desc_nfe' => 'InstantPayment', 'opcaopag_envio_automatico_nfe' => true]);
        PagamentosVenda::create([
            'pg_venda_venda_id' => $this->venda->id, 'pg_venda_opcaopagamento_id' => $pix->id,
            'pg_venda_valor_pagamento' => 10, 'pg_venda_valor_pago_pelo_cliente' => 10,
        ]);
        app(VendaService::class)->atualizarValoresdaVenda($this->venda->id);

        return $this->venda->fresh();
    }

    public function test_payload_da_nfce_leva_so_o_item_cobrado_e_total_bate_com_o_pagamento(): void
    {
        $venda = $this->vendaComCortesiaEItemCobrado();

        $payload = app(NfeIoService::class)->payloadPreview($venda);

        $this->assertCount(1, $payload['items']);
        $this->assertSame((string) $this->refri->id, $payload['items'][0]['code']);
        $this->assertEqualsWithDelta(10.0, collect($payload['items'])->sum(fn ($i) => $i['totalAmount'] - $i['discountAmount'] + $i['othersAmount']), 0.001);
        $this->assertEqualsWithDelta(10.0, (float) $venda->venda_valor_total, 0.001);
        $this->assertTrue(app(EmissaoNfeAutomaticaService::class)->deveEmitir($venda));
    }

    public function test_venda_so_de_cortesia_finaliza_sem_pagamento_e_nao_emite_nfce(): void
    {
        Empresa::create(['empresa_razao_social' => 'Pizzaria LTDA', 'empresa_cnpj' => '11222333000181', 'empresa_api_nfeio_company_id' => 'c', 'empresa_api_nfeio_apikey' => 'k']);
        [, $item] = $this->pedido($this->pizza);
        $this->service()->concederItem($item, $this->gerente, null, null, 'Teste');
        app(LancamentoItensVendaService::class)->lancarItens($this->venda, collect([$item->fresh()]));
        app(VendaService::class)->atualizarValoresdaVenda($this->venda->id);
        $venda = $this->venda->fresh();

        $this->assertEqualsWithDelta(0.0, (float) $venda->venda_valor_total, 0.001);
        $this->assertFalse($venda->temItemFiscal());
        $this->assertFalse(app(EmissaoNfeAutomaticaService::class)->deveEmitir($venda));

        app(FinalizacaoVendaService::class)->finalizar($venda);
        $this->assertSame('FINALIZADA', $venda->fresh()->venda_status);

        $this->expectException(NfeIoException::class);
        app(NfeIoService::class)->emitir($venda->fresh());
    }

    public function test_linha_avulsa_do_caixa_vira_cortesia_e_desfaz(): void
    {
        $linha = ItensVenda::create([
            'item_numero' => 1, 'item_venda_venda_id' => $this->venda->id, 'item_venda_produto_id' => $this->refri->id,
            'item_venda_quantidade' => 2, 'item_venda_valor_unitario' => 10, 'item_venda_valor_adicionais' => 0,
            'item_venda_desconto' => 0, 'item_venda_valor' => 20, 'item_venda_status' => 'INSERIDO',
            'item_venda_quantidade_tributavel' => 2, 'item_venda_valor_unitario_tributavel' => 10,
            'item_venda_valor_base_calculo' => 20,
        ]);
        app(VendaService::class)->atualizarValoresdaVenda($this->venda->id);

        $this->service()->alternarLinhaDaVenda($linha, $this->gerente, null, null, 'Cortesia do dono');

        $linha->refresh();
        $this->assertTrue($linha->item_venda_cortesia);
        $this->assertEqualsWithDelta(0.0, (float) $linha->item_venda_valor, 0.001);
        $this->assertEqualsWithDelta(20.0, (float) $linha->item_venda_valor_tabela, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $this->venda->fresh()->venda_valor_total, 0.001);

        $this->service()->alternarLinhaDaVenda($linha->fresh(), $this->gerente, null, null, 'Desfeita');

        $this->assertFalse($linha->fresh()->item_venda_cortesia);
        $this->assertEqualsWithDelta(20.0, (float) $this->venda->fresh()->venda_valor_total, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $linha->fresh()->item_venda_desconto, 0.001);
    }

    public function test_caixa_da_cortesia_na_linha_do_pedido_e_o_item_volta_para_a_venda(): void
    {
        [$pedido, $item] = $this->pedido($this->pizza, 2, 'ENTREGUE');

        $tela = Livewire::test(OperarVenda::class, ['venda' => $this->venda])->call('lancarPedidoAvulso', $pedido->id);
        $linha = ItensVenda::where('item_venda_venda_id', $this->venda->id)->firstOrFail();
        $this->assertEqualsWithDelta(80.0, (float) $this->venda->fresh()->venda_valor_total, 0.001);

        $tela->callAction(TestAction::make('cortesiaLinha')->arguments(['item' => $linha->id]), ['motivo' => 'Cortesia do dono'])
            ->assertHasNoActionErrors()
            ->assertSee('CORTESIA');

        $item->refresh();
        $this->assertTrue($item->item_pedido_cortesia);
        $this->assertSame($this->venda->id, $item->item_pedido_venda_id);
        $linha = ItensVenda::where('item_venda_venda_id', $this->venda->id)->firstOrFail();
        $this->assertTrue($linha->item_venda_cortesia);
        $this->assertEqualsWithDelta(80.0, (float) $linha->item_venda_valor_tabela, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $this->venda->fresh()->venda_valor_total, 0.001);

        $tela->callAction(TestAction::make('cortesiaLinha')->arguments(['item' => $linha->id]), ['motivo' => 'Foi engano']);

        $this->assertFalse($item->fresh()->item_pedido_cortesia);
        $this->assertEqualsWithDelta(80.0, (float) $this->venda->fresh()->venda_valor_total, 0.001);
    }

    public function test_autorizacao_negada_no_caixa_nao_perde_o_item_da_venda(): void
    {
        [$pedido, $item] = $this->pedido($this->pizza, 1, 'ENTREGUE');
        $caixa = User::factory()->caixa()->create(['name_first' => 'Cris', 'name' => 'Cris']);
        $caixa->givePermissionTo(Permission::findOrCreate('operar:venda'));
        SessaoCaixa::query()->update(['sessaocaixa_user_id' => $caixa->id]);
        $this->actingAs($caixa);

        $tela = Livewire::test(OperarVenda::class, ['venda' => $this->venda])->call('lancarPedidoAvulso', $pedido->id);
        $linha = ItensVenda::where('item_venda_venda_id', $this->venda->id)->firstOrFail();

        $tela->callAction(TestAction::make('cortesiaLinha')->arguments(['item' => $linha->id]), ['motivo' => 'Sem PIN', 'autorizador_id' => $this->gerente->id, 'pin' => '0000']);

        $this->assertFalse($item->fresh()->item_pedido_cortesia);
        $this->assertSame($this->venda->id, $item->fresh()->item_pedido_venda_id);
        $this->assertEqualsWithDelta(40.0, (float) $this->venda->fresh()->venda_valor_total, 0.001);
    }

    public function test_atender_pedido_da_cortesia_no_item_e_no_pedido_inteiro(): void
    {
        [$pedido, $item] = $this->pedido($this->pizza, 1);
        $outro = ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id, 'item_pedido_produto_id' => $this->refri->id,
            'item_pedido_quantidade' => 1, 'item_pedido_valor_unitario' => 10, 'item_pedido_desconto' => 0,
            'item_pedido_valor_adicionais' => 0, 'item_pedido_valor' => 10, 'item_pedido_status' => 'INSERIDO',
        ]);
        $pedido->update(['pedido_valor_total' => 50]);

        $tela = Livewire::test(AtenderPedido::class, ['pedido' => $pedido])
            ->callAction(TestAction::make('cortesiaItem')->arguments(['item' => $item->id]), ['motivo' => 'Brinde'])
            ->assertSee('CORTESIA');

        $this->assertTrue($item->fresh()->item_pedido_cortesia);
        $this->assertEqualsWithDelta(10.0, (float) $pedido->fresh()->pedido_valor_total, 0.001);

        $tela->callAction('cortesiaPedido', ['motivo' => 'Reposição']);

        $this->assertTrue($outro->fresh()->item_pedido_cortesia);
        $this->assertEqualsWithDelta(0.0, (float) $pedido->fresh()->pedido_valor_total, 0.001);
    }

    public function test_relatorio_lista_cortesia_com_motivo_autorizador_e_custo(): void
    {
        $this->actingAs($this->gerente);
        [, $item] = $this->pedido($this->refri, 2);
        $this->service()->concederItem($item, $this->gerente, null, null, 'Brinde do evento');

        Livewire::test(RelatorioCortesias::class)
            ->assertSee('Brinde do evento')
            ->assertSee('Refrigerante')
            ->assertSee('Gil')
            ->assertSee('R$ 20,00')
            ->assertSee('R$ 6,00')
            ->set('busca', 'inexistente')
            ->assertSee('Nenhuma cortesia no período.');
    }
}
