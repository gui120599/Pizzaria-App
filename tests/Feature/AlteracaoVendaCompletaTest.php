<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Enums\StatusFechamentoCaixa;
use App\Enums\StatusLancamento;
use App\Exceptions\AlteracaoVendaException;
use App\Filament\Pages\OperarVenda;
use App\Filament\Resources\Vendas\Pages\ListVendas;
use App\Models\Caixa;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\FechamentoCaixa;
use App\Models\ItensPedido;
use App\Models\ItensVenda;
use App\Models\Lancamento;
use App\Models\MovimentacoesSessaoCaixa;
use App\Models\OpcoesPagamento;
use App\Models\PagamentosVenda;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\SessaoCaixa;
use App\Models\User;
use App\Models\Venda;
use App\Models\VendaAlteracao;
use App\Services\AlteracaoVendaService;
use App\Services\FinalizacaoVendaService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Alteração completa de venda finalizada: reabre (desfaz caixa, estoque, fiado
 * e pedidos), edita no PDV, finaliza de novo ou descarta restaurando o original.
 */
class AlteracaoVendaCompletaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private SessaoCaixa $sessao;

    private Produto $refri;

    private OpcoesPagamento $dinheiro;

    private Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create(['name_first' => 'Ana', 'name' => 'Ana']);
        $this->actingAs($this->admin);

        $this->sessao = SessaoCaixa::create([
            'sessaocaixa_caixa_id' => Caixa::create(['caixa_nome' => 'Caixa 1'])->id,
            'sessaocaixa_status' => 'ABERTA',
            'sessaocaixa_data_hora_abertura' => now()->subHours(2),
            'sessaocaixa_saldo_inicial' => 0,
            'sessaocaixa_user_id' => $this->admin->id,
        ]);

        $categoria = Categoria::create(['categoria_nome' => 'Geral']);
        $this->refri = Produto::create([
            'produto_descricao' => 'Refrigerante', 'produto_categoria_id' => $categoria->id,
            'produto_tipo' => ProdutoTipoEnum::REVENDA->value, 'produto_preco_venda' => 10.00,
            'produto_controla_estoque' => true, 'produto_saldo_estoque' => 9, 'produto_custo_medio' => 3,
        ]);
        $this->dinheiro = OpcoesPagamento::create(['opcaopag_nome' => 'Dinheiro', 'opcaopag_desc_nfe' => 'Cash']);
        $this->cliente = Cliente::create(['cliente_nome' => 'Maria', 'cliente_tipo' => 'PF', 'cliente_limite_credito' => 500]);
    }

    /**
     * Venda direta finalizada de verdade (observer de estoque, caixa e fiado incluídos).
     */
    private function vendaFinalizada(int $quantidade = 2, float $pago = 20, bool $fiado = false): Venda
    {
        $total = $quantidade * 10;
        $venda = Venda::create([
            'venda_status' => 'INICIADA', 'venda_sessao_caixa_id' => $this->sessao->id, 'venda_datahora_iniciada' => now()->subHour(),
            'venda_cliente_id' => $fiado ? $this->cliente->id : null,
            'venda_valor_itens' => $total, 'venda_valor_total' => $total, 'venda_valor_pago' => $pago,
        ]);
        ItensVenda::create(['item_numero' => 1, 'item_venda_venda_id' => $venda->id, 'item_venda_produto_id' => $this->refri->id, 'item_venda_quantidade' => $quantidade, 'item_venda_quantidade_tributavel' => $quantidade, 'item_venda_valor_unitario' => 10, 'item_venda_valor' => $total, 'item_venda_status' => 'INSERIDO']);
        PagamentosVenda::create(['pg_venda_venda_id' => $venda->id, 'pg_venda_opcaopagamento_id' => $this->dinheiro->id, 'pg_venda_valor_pagamento' => $pago, 'pg_venda_valor_pago_pelo_cliente' => $pago, 'pg_venda_valor_troco' => 0]);

        app(FinalizacaoVendaService::class)->finalizar($venda, null, [], [], $fiado, $fiado ? Carbon::parse('2026-12-31') : null);

        return $venda->fresh();
    }

    private function servico(): AlteracaoVendaService
    {
        return app(AlteracaoVendaService::class);
    }

    public function test_reabrir_desfaz_estoque_caixa_e_fiado_mantendo_itens_e_pagamentos(): void
    {
        $venda = $this->vendaFinalizada(2, 5, fiado: true);

        $this->assertEqualsWithDelta(7.0, (float) $this->refri->fresh()->produto_saldo_estoque, 0.001);
        $this->assertEqualsWithDelta(5.0, (float) $this->sessao->fresh()->sessaocaixa_saldo_final, 0.001);
        $this->assertSame(1, Lancamento::where('venda_id', $venda->id)->count());

        $alteracao = $this->servico()->reabrir($venda, 'item lançado errado');

        $venda->refresh();
        $this->assertSame('INICIADA', $venda->venda_status);
        $this->assertNull($venda->venda_datahora_finalizada);
        $this->assertEqualsWithDelta(9.0, (float) $this->refri->fresh()->produto_saldo_estoque, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $this->sessao->fresh()->sessaocaixa_saldo_final, 0.001);
        $this->assertSame(0, MovimentacoesSessaoCaixa::where('mov_venda_id', $venda->id)->count());
        $this->assertSame(0, Lancamento::where('venda_id', $venda->id)->count());
        $this->assertSame(1, $venda->itensVenda()->count());
        $this->assertSame(1, $venda->pagamentos()->count());
        $this->assertSame(VendaAlteracao::ABERTA, $alteracao->status);
        $this->assertSame('item lançado errado', $alteracao->motivo);
        $this->assertTrue((bool) $venda->alteracaoAberta);
    }

    public function test_editar_e_finalizar_de_novo_conclui_a_alteracao(): void
    {
        $venda = $this->vendaFinalizada(2, 20);
        $this->servico()->reabrir($venda, 'cliente e quantidade errados');

        ItensVenda::where('item_venda_venda_id', $venda->id)->update(['item_venda_quantidade' => 3, 'item_venda_quantidade_tributavel' => 3, 'item_venda_valor' => 30]);
        PagamentosVenda::where('pg_venda_venda_id', $venda->id)->update(['pg_venda_valor_pagamento' => 30, 'pg_venda_valor_pago_pelo_cliente' => 30]);
        $venda->update(['venda_valor_itens' => 30, 'venda_valor_total' => 30, 'venda_valor_pago' => 30, 'venda_cliente_id' => $this->cliente->id]);

        app(FinalizacaoVendaService::class)->finalizar($venda->fresh());

        $this->assertEqualsWithDelta(6.0, (float) $this->refri->fresh()->produto_saldo_estoque, 0.001);
        $this->assertEqualsWithDelta(30.0, (float) $this->sessao->fresh()->sessaocaixa_saldo_final, 0.001);
        $this->assertSame($this->sessao->id, $venda->fresh()->venda_sessao_caixa_id);

        $alteracao = VendaAlteracao::where('venda_id', $venda->id)->sole();
        $this->assertSame(VendaAlteracao::CONCLUIDA, $alteracao->status);
        $this->assertEqualsWithDelta(10.0, $alteracao->depois['diferenca'], 0.001);
        $this->assertEqualsWithDelta(20.0, $alteracao->depois['total_antes'], 0.001);
        $this->assertNotNull($alteracao->encerrada_em);
    }

    public function test_descartar_restaura_o_original_inclusive_fiado_e_estoque(): void
    {
        $venda = $this->vendaFinalizada(2, 5, fiado: true);
        $finalizadaEm = $venda->venda_datahora_finalizada;
        $itemOriginal = $venda->itensVenda()->sole();
        $pagamentoOriginal = $venda->pagamentos()->sole();

        $this->servico()->reabrir($venda, 'teste');

        // Edição: quantidade, pagamento extra, item novo, cliente removido.
        ItensVenda::where('item_venda_venda_id', $venda->id)->update(['item_venda_quantidade' => 5, 'item_venda_valor' => 50]);
        ItensVenda::create(['item_numero' => 2, 'item_venda_venda_id' => $venda->id, 'item_venda_produto_id' => $this->refri->id, 'item_venda_quantidade' => 1, 'item_venda_quantidade_tributavel' => 1, 'item_venda_valor_unitario' => 10, 'item_venda_valor' => 10, 'item_venda_status' => 'INSERIDO']);
        PagamentosVenda::create(['pg_venda_venda_id' => $venda->id, 'pg_venda_opcaopagamento_id' => $this->dinheiro->id, 'pg_venda_valor_pagamento' => 7, 'pg_venda_valor_pago_pelo_cliente' => 7, 'pg_venda_valor_troco' => 0]);
        $venda->update(['venda_valor_total' => 60, 'venda_valor_pago' => 12, 'venda_cliente_id' => null]);

        $this->servico()->descartar($venda->fresh());

        $venda->refresh();
        $this->assertSame('FINALIZADA', $venda->venda_status);
        $this->assertSame($finalizadaEm->toDateTimeString(), $venda->venda_datahora_finalizada->toDateTimeString());
        $this->assertSame($this->cliente->id, $venda->venda_cliente_id);
        $this->assertEqualsWithDelta(20.0, (float) $venda->venda_valor_total, 0.001);
        $this->assertEqualsWithDelta(5.0, (float) $venda->venda_valor_pago, 0.001);

        $this->assertSame([$itemOriginal->id], $venda->itensVenda()->pluck('id')->all());
        $this->assertEqualsWithDelta(2.0, (float) $venda->itensVenda()->sole()->item_venda_quantidade, 0.001);
        $this->assertSame([$pagamentoOriginal->id], $venda->pagamentos()->pluck('id')->all());

        $this->assertEqualsWithDelta(7.0, (float) $this->refri->fresh()->produto_saldo_estoque, 0.001);
        $this->assertEqualsWithDelta(5.0, (float) $this->sessao->fresh()->sessaocaixa_saldo_final, 0.001);
        $titulo = Lancamento::where('venda_id', $venda->id)->sole();
        $this->assertEqualsWithDelta(15.0, (float) $titulo->valor, 0.001);
        $this->assertSame('2026-12-31', $titulo->vencimento->toDateString());
        $this->assertSame(VendaAlteracao::DESCARTADA, VendaAlteracao::where('venda_id', $venda->id)->sole()->status);
    }

    public function test_reabrir_e_descartar_varias_vezes_nao_estorna_estoque_em_duplicidade(): void
    {
        $venda = $this->vendaFinalizada(2, 20);

        $this->servico()->reabrir($venda, 'primeira');
        $this->servico()->descartar($venda->fresh());
        $this->servico()->reabrir($venda->fresh(), 'segunda');

        $this->assertEqualsWithDelta(9.0, (float) $this->refri->fresh()->produto_saldo_estoque, 0.001);

        $this->servico()->descartar($venda->fresh());
        $this->assertEqualsWithDelta(7.0, (float) $this->refri->fresh()->produto_saldo_estoque, 0.001);
        $this->assertSame(2, VendaAlteracao::where('venda_id', $venda->id)->count());
    }

    public function test_pedido_volta_ao_recebimento_mantendo_o_vinculo_e_descartar_refinaliza(): void
    {
        $pedido = Pedido::create(['pedido_status' => 'ENTREGUE', 'pedido_valor_total' => 10]);
        $venda = Venda::create(['venda_status' => 'INICIADA', 'venda_sessao_caixa_id' => $this->sessao->id, 'venda_datahora_iniciada' => now(), 'venda_valor_itens' => 10, 'venda_valor_total' => 10, 'venda_valor_pago' => 10]);
        $itemVenda = ItensVenda::create(['item_numero' => 1, 'item_venda_venda_id' => $venda->id, 'item_venda_produto_id' => $this->refri->id, 'item_venda_quantidade' => 1, 'item_venda_quantidade_tributavel' => 1, 'item_venda_valor_unitario' => 10, 'item_venda_valor' => 10, 'item_venda_status' => 'INSERIDO']);
        $itemPedido = ItensPedido::create(['item_pedido_pedido_id' => $pedido->id, 'item_pedido_produto_id' => $this->refri->id, 'item_pedido_quantidade' => 1, 'item_pedido_valor_unitario' => 10, 'item_pedido_desconto' => 0, 'item_pedido_valor_adicionais' => 0, 'item_pedido_valor' => 10, 'item_pedido_status' => 'INSERIDO', 'item_pedido_venda_id' => $venda->id, 'item_pedido_item_venda_id' => $itemVenda->id]);
        PagamentosVenda::create(['pg_venda_venda_id' => $venda->id, 'pg_venda_opcaopagamento_id' => $this->dinheiro->id, 'pg_venda_valor_pagamento' => 10, 'pg_venda_valor_pago_pelo_cliente' => 10, 'pg_venda_valor_troco' => 0]);
        app(FinalizacaoVendaService::class)->finalizar($venda, null, [], [$pedido->id]);

        $this->assertSame('FINALIZADO', $pedido->fresh()->pedido_status);

        $this->servico()->reabrir($venda->fresh(), 'trocar o item do pedido');

        $this->assertSame('ENTREGUE', $pedido->fresh()->pedido_status);
        $this->assertNull($pedido->fresh()->pedido_venda_id);
        $this->assertSame($venda->id, $itemPedido->fresh()->item_pedido_venda_id);
        $this->assertSame($itemVenda->id, $itemPedido->fresh()->item_pedido_item_venda_id);

        $this->servico()->descartar($venda->fresh());

        $this->assertSame('FINALIZADO', $pedido->fresh()->pedido_status);
        $this->assertSame($venda->id, $pedido->fresh()->pedido_venda_id);
        $this->assertSame($venda->id, $itemPedido->fresh()->item_pedido_venda_id);
    }

    public function test_bloqueios(): void
    {
        $servico = $this->servico();

        $autorizada = $this->vendaFinalizada();
        $autorizada->update(['venda_status_nfe' => 'Issued']);
        $this->assertStringContainsString('autorizada', $servico->motivoBloqueio($autorizada));

        $stone = $this->vendaFinalizada();
        $stone->pagamentos()->update(['pg_venda_tipo_integracao' => 'integrated']);
        $this->assertStringContainsString('Stone', $servico->motivoBloqueio($stone));

        $recebido = $this->vendaFinalizada(2, 5, fiado: true);
        Lancamento::where('venda_id', $recebido->id)->update(['status' => StatusLancamento::Pago]);
        $this->assertStringContainsString('recebimento', $servico->motivoBloqueio($recebido));

        $fechada = $this->vendaFinalizada();
        FechamentoCaixa::create(['sessao_caixa_id' => $this->sessao->id, 'user_id' => $this->admin->id, 'status' => StatusFechamentoCaixa::Confirmado]);
        $this->assertStringContainsString('fechamento', $servico->motivoBloqueio($fechada));
        FechamentoCaixa::query()->delete();

        $livre = $this->vendaFinalizada();
        $this->assertNull($servico->motivoBloqueio($livre));

        $semSessao = User::factory()->gerente()->create(['name_first' => 'Gil', 'name' => 'Gil']);
        $this->assertStringContainsString('sessão de caixa', $servico->motivoBloqueio($livre, $semSessao->id));

        $servico->reabrir($livre, 'x');
        $this->assertStringContainsString('já está em alteração', $servico->motivoBloqueio($livre->fresh()));

        $this->expectException(AlteracaoVendaException::class);
        $servico->reabrir($autorizada, 'não pode');
    }

    public function test_cancelar_a_venda_em_alteracao_encerra_a_alteracao_e_o_pdv_nao_a_cancela_como_vazia(): void
    {
        $venda = $this->vendaFinalizada();
        $venda->update(['venda_valor_total' => 0]);
        $this->servico()->reabrir($venda->fresh(), 'x');

        $this->post(route('venda.cancelar_vazia', ['venda' => $venda->id]))->assertNoContent();
        $this->assertSame('INICIADA', $venda->fresh()->venda_status);

        $this->artisan('vendas:cancelar-iniciadas-vazias', ['--horas' => 0])->assertSuccessful();
        $this->assertSame('INICIADA', $venda->fresh()->venda_status);

        app(FinalizacaoVendaService::class)->cancelar($venda->fresh(), 'outro');
        $this->assertSame(VendaAlteracao::CANCELADA, VendaAlteracao::where('venda_id', $venda->id)->sole()->status);
    }

    public function test_acao_da_tabela_reabre_e_redireciona_para_o_pdv_e_a_aba_lista(): void
    {
        $venda = $this->vendaFinalizada();

        Livewire::test(ListVendas::class)
            ->assertTableActionVisible('alterarVenda', $venda)
            ->callTableAction('alterarVenda', $venda, ['motivo' => 'trocar itens da venda'])
            ->assertRedirect(OperarVenda::getUrl(['venda' => $venda]));

        $this->assertSame('INICIADA', $venda->fresh()->venda_status);

        Livewire::test(ListVendas::class)
            ->set('activeTab', 'em_alteracao')
            ->assertCanSeeTableRecords([$venda->fresh()]);

        Livewire::test(OperarVenda::class, ['venda' => $venda->fresh()])
            ->assertSee('em alteração')
            ->assertSee('trocar itens da venda')
            ->call('descartarAlteracao');

        $this->assertSame('FINALIZADA', $venda->fresh()->venda_status);
    }

    public function test_sem_a_permissao_nova_a_acao_nao_aparece(): void
    {
        $caixa = User::factory()->caixa()->create(['name_first' => 'Cai', 'name' => 'Cai']);
        $this->actingAs($caixa);

        $this->assertFalse($caixa->can('alterar_finalizada:venda'));
        $this->assertFalse($caixa->can('cancelar_finalizada:venda'));
    }
}
