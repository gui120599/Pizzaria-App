<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Filament\Pages\OperarVenda;
use App\Models\Caixa;
use App\Models\Categoria;
use App\Models\ItensPedido;
use App\Models\ItensVenda;
use App\Models\OpcoesPagamento;
use App\Models\PagamentosVenda;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\SessaoCaixa;
use App\Models\User;
use App\Models\Venda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OperarVendaPagamentoTest extends TestCase
{
    use RefreshDatabase;

    private Venda $venda;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->admin()->create(['name_first' => 'Admin']);
        $this->actingAs($user);

        $caixa = Caixa::create(['caixa_nome' => 'Caixa 1']);
        $sessaoCaixa = SessaoCaixa::create([
            'sessaocaixa_caixa_id' => $caixa->id,
            'sessaocaixa_status' => 'ABERTA',
            'sessaocaixa_data_hora_abertura' => now(),
            'sessaocaixa_saldo_inicial' => 0,
            'sessaocaixa_user_id' => $user->id,
        ]);

        $categoria = Categoria::create(['categoria_nome' => 'Bebidas']);
        $produto = Produto::create([
            'produto_descricao' => 'Refrigerante Lata',
            'produto_categoria_id' => $categoria->id,
            'produto_tipo' => ProdutoTipoEnum::REVENDA->value,
            'produto_preco_venda' => 20.00,
        ]);

        $this->venda = Venda::create([
            'venda_status' => 'INICIADA',
            'venda_sessao_caixa_id' => $sessaoCaixa->id,
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
    }

    public function test_registrar_pagamento_exato_sem_taxa(): void
    {
        $opcao = OpcoesPagamento::create(['opcaopag_nome' => 'Dinheiro', 'opcaopag_tipo_taxa' => 'N/A', 'opcaopag_valor_percentual_taxa' => 0]);

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->set('opcaoPagamentoSelecionadaId', $opcao->id)
            ->set('valorPagamento', 20.00)
            ->call('registrarPagamento')
            ->assertSet('modalPagamentoAberta', false);

        $vendaFresh = $this->venda->fresh();
        $this->assertSame(20.00, (float) $vendaFresh->venda_valor_pago);
        $this->assertSame(0.00, (float) $vendaFresh->venda_valor_troco);
        $this->assertSame(1, PagamentosVenda::where('pg_venda_venda_id', $this->venda->id)->count());
    }

    public function test_registrar_pagamento_com_troco(): void
    {
        $opcao = OpcoesPagamento::create(['opcaopag_nome' => 'Dinheiro', 'opcaopag_tipo_taxa' => 'N/A', 'opcaopag_valor_percentual_taxa' => 0]);

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->set('opcaoPagamentoSelecionadaId', $opcao->id)
            ->set('valorPagamento', 20.00)
            ->set('valorPagoPeloCliente', 50.00)
            ->call('registrarPagamento');

        $pagamento = PagamentosVenda::where('pg_venda_venda_id', $this->venda->id)->firstOrFail();
        $this->assertSame(30.00, (float) $pagamento->pg_venda_valor_troco);
        $this->assertSame(30.00, (float) $this->venda->fresh()->venda_valor_troco);
    }

    public function test_registrar_pagamento_com_acrescimo_de_cartao_aumenta_total(): void
    {
        $opcao = OpcoesPagamento::create([
            'opcaopag_nome' => 'Cartão de Crédito',
            'opcaopag_tipo_taxa' => 'ACRESCENTAR',
            'opcaopag_valor_percentual_taxa' => 5,
        ]);

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->set('opcaoPagamentoSelecionadaId', $opcao->id)
            ->set('valorPagamento', 20.00)
            ->call('registrarPagamento');

        $vendaFresh = $this->venda->fresh();
        $this->assertSame(1.00, (float) $vendaFresh->venda_valor_acrescimo);
        $this->assertSame(21.00, (float) $vendaFresh->venda_valor_total);
        $this->assertSame(21.00, (float) $vendaFresh->venda_valor_pago);
    }

    public function test_registrar_pagamento_com_desconto_pix_reduz_total(): void
    {
        $opcao = OpcoesPagamento::create([
            'opcaopag_nome' => 'Pix',
            'opcaopag_tipo_taxa' => 'DESCONTAR',
            'opcaopag_valor_percentual_taxa' => 10,
        ]);

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->set('opcaoPagamentoSelecionadaId', $opcao->id)
            ->set('valorPagamento', 20.00)
            ->call('registrarPagamento');

        $vendaFresh = $this->venda->fresh();
        $this->assertSame(2.00, (float) $vendaFresh->venda_valor_desconto);
        $this->assertSame(18.00, (float) $vendaFresh->venda_valor_total);
    }

    public function test_remover_pagamento_recalcula_via_venda_service(): void
    {
        $opcao = OpcoesPagamento::create(['opcaopag_nome' => 'Dinheiro', 'opcaopag_tipo_taxa' => 'N/A', 'opcaopag_valor_percentual_taxa' => 0]);

        $component = Livewire::test(OperarVenda::class, ['venda' => $this->venda]);
        $component->set('opcaoPagamentoSelecionadaId', $opcao->id);
        $component->set('valorPagamento', 20.00);
        $component->call('registrarPagamento');

        $pagamento = PagamentosVenda::where('pg_venda_venda_id', $this->venda->id)->firstOrFail();
        $component->call('removerPagamento', $pagamento->id);

        $this->assertSame(0, PagamentosVenda::where('pg_venda_venda_id', $this->venda->id)->count());
        $this->assertSame(0.00, (float) $this->venda->fresh()->venda_valor_pago);
    }

    public function test_valor_restante_e_o_total_menos_o_ja_pago(): void
    {
        $opcao = OpcoesPagamento::create(['opcaopag_nome' => 'Dinheiro', 'opcaopag_tipo_taxa' => 'N/A', 'opcaopag_valor_percentual_taxa' => 0]);

        $component = Livewire::test(OperarVenda::class, ['venda' => $this->venda]);
        $this->assertSame(20.00, $component->instance()->valorRestante);

        $component->set('opcaoPagamentoSelecionadaId', $opcao->id)
            ->set('valorPagamento', 8.00)
            ->call('registrarPagamento');

        unset($component);
        $component = Livewire::test(OperarVenda::class, ['venda' => $this->venda->fresh()]);
        $this->assertSame(12.00, $component->instance()->valorRestante);
    }

    public function test_abrir_modal_pagamento_preenche_valor_recebido_com_o_restante(): void
    {
        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->call('abrirModalPagamento')
            ->assertSet('valorPagamento', 20.00)
            ->assertSet('modalPagamentoAberta', true);
    }

    public function test_editar_pagamento_preenche_o_modal_e_substitui_o_registro_antigo(): void
    {
        $dinheiro = OpcoesPagamento::create(['opcaopag_nome' => 'Dinheiro', 'opcaopag_tipo_taxa' => 'N/A', 'opcaopag_valor_percentual_taxa' => 0]);
        $pix = OpcoesPagamento::create(['opcaopag_nome' => 'Pix', 'opcaopag_tipo_taxa' => 'N/A', 'opcaopag_valor_percentual_taxa' => 0]);

        $component = Livewire::test(OperarVenda::class, ['venda' => $this->venda]);
        $component->set('opcaoPagamentoSelecionadaId', $dinheiro->id)
            ->set('valorPagamento', 20.00)
            ->call('registrarPagamento');

        $pagamentoOriginal = PagamentosVenda::where('pg_venda_venda_id', $this->venda->id)->firstOrFail();

        $component->call('editarPagamento', $pagamentoOriginal->id)
            ->assertSet('opcaoPagamentoSelecionadaId', $dinheiro->id)
            ->assertSet('valorPagamento', 20.00)
            ->assertSet('modalPagamentoAberta', true);

        $component->set('opcaoPagamentoSelecionadaId', $pix->id)
            ->call('registrarPagamento');

        $this->assertSame(1, PagamentosVenda::where('pg_venda_venda_id', $this->venda->id)->count());
        $pagamentoNovo = PagamentosVenda::where('pg_venda_venda_id', $this->venda->id)->firstOrFail();
        $this->assertNotSame($pagamentoOriginal->id, $pagamentoNovo->id);
        $this->assertSame($pix->id, $pagamentoNovo->pg_venda_opcaopagamento_id);
        $this->assertSame(20.00, (float) $this->venda->fresh()->venda_valor_pago);
    }

    public function test_registrar_pagamento_com_valor_recebido_maior_que_o_total_da_venda_falha(): void
    {
        $opcao = OpcoesPagamento::create(['opcaopag_nome' => 'Dinheiro', 'opcaopag_tipo_taxa' => 'N/A', 'opcaopag_valor_percentual_taxa' => 0]);

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->call('abrirModalPagamento')
            ->set('opcaoPagamentoSelecionadaId', $opcao->id)
            ->set('valorPagamento', 20.01)
            ->call('registrarPagamento')
            ->assertHasErrors(['valorPagamento'])
            ->assertSet('modalPagamentoAberta', true);

        $this->assertSame(0, PagamentosVenda::where('pg_venda_venda_id', $this->venda->id)->count());
        $this->assertSame(0.00, (float) $this->venda->fresh()->venda_valor_pago);
    }

    public function test_registrar_pagamento_com_valor_recebido_maior_que_o_pago_pelo_cliente_falha(): void
    {
        $opcao = OpcoesPagamento::create(['opcaopag_nome' => 'Dinheiro', 'opcaopag_tipo_taxa' => 'N/A', 'opcaopag_valor_percentual_taxa' => 0]);

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->call('abrirModalPagamento')
            ->set('opcaoPagamentoSelecionadaId', $opcao->id)
            ->set('valorPagamento', 15.00)
            ->set('valorPagoPeloCliente', 10.00)
            ->call('registrarPagamento')
            ->assertHasErrors(['valorPagamento'])
            ->assertSet('modalPagamentoAberta', true);

        $this->assertSame(0, PagamentosVenda::where('pg_venda_venda_id', $this->venda->id)->count());
        $this->assertSame(0.00, (float) $this->venda->fresh()->venda_valor_pago);
    }

    public function test_dividir_conta_fatia_o_restante_em_n_pagamentos_sem_perder_centavo(): void
    {
        $dinheiro = OpcoesPagamento::create(['opcaopag_nome' => 'Dinheiro', 'opcaopag_tipo_taxa' => 'N/A', 'opcaopag_valor_percentual_taxa' => 0]);
        // 20,00 não divide exato por 3 — testa que a soma das fatias bate certinho.
        $this->venda->update(['venda_valor_total' => 20.00]);

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->call('dividirConta', 3, $dinheiro->id);

        $pagamentos = PagamentosVenda::where('pg_venda_venda_id', $this->venda->id)->get();
        $this->assertCount(3, $pagamentos);
        $this->assertSame(20.00, round((float) $pagamentos->sum('pg_venda_valor_pagamento'), 2));
        $this->assertSame(20.00, (float) $this->venda->fresh()->venda_valor_pago);
    }

    /** Simula um pedido já lançado nesta venda (item_pedido_venda_id preenchido). */
    private function pedidoLancadoNestaVenda(?string $descricaoPagamento = null, ?string $observacaoPagamento = null): Pedido
    {
        $pedido = Pedido::create([
            'pedido_status' => 'ABERTO',
            'pedido_descricao_pagamento' => $descricaoPagamento,
            'pedido_observacao_pagamento' => $observacaoPagamento,
        ]);

        ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $this->venda->itensVenda->first()->item_venda_produto_id,
            'item_pedido_venda_id' => $this->venda->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => 20.00,
            'item_pedido_valor' => 20.00,
            'item_pedido_desconto' => 0,
            'item_pedido_valor_adicionais' => 0,
            'item_pedido_status' => 'INSERIDO',
        ]);

        return $pedido;
    }

    public function test_abrir_modal_pagamento_pre_seleciona_opcao_quando_pedido_indica_forma_valida(): void
    {
        $dinheiro = OpcoesPagamento::create(['opcaopag_nome' => 'Dinheiro', 'opcaopag_tipo_taxa' => 'N/A', 'opcaopag_valor_percentual_taxa' => 0]);
        $this->pedidoLancadoNestaVenda('Dinheiro');

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->call('abrirModalPagamento')
            ->assertSet('opcaoPagamentoSelecionadaId', $dinheiro->id);
    }

    public function test_abrir_modal_pagamento_nao_pre_seleciona_quando_texto_nao_bate_com_nenhuma_opcao(): void
    {
        OpcoesPagamento::create(['opcaopag_nome' => 'Dinheiro', 'opcaopag_tipo_taxa' => 'N/A', 'opcaopag_valor_percentual_taxa' => 0]);
        $this->pedidoLancadoNestaVenda('Pix instantâneo');

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->call('abrirModalPagamento')
            ->assertSet('opcaoPagamentoSelecionadaId', null);
    }

    public function test_abrir_modal_pagamento_nao_pre_seleciona_quando_pedidos_indicam_formas_diferentes(): void
    {
        OpcoesPagamento::create(['opcaopag_nome' => 'Dinheiro', 'opcaopag_tipo_taxa' => 'N/A', 'opcaopag_valor_percentual_taxa' => 0]);
        OpcoesPagamento::create(['opcaopag_nome' => 'Cartão', 'opcaopag_tipo_taxa' => 'N/A', 'opcaopag_valor_percentual_taxa' => 0]);
        $this->pedidoLancadoNestaVenda('Dinheiro');
        $this->pedidoLancadoNestaVenda('Cartão');

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->call('abrirModalPagamento')
            ->assertSet('opcaoPagamentoSelecionadaId', null);
    }

    public function test_abrir_modal_pagamento_nao_sobrescreve_selecao_manual_ja_feita(): void
    {
        $dinheiro = OpcoesPagamento::create(['opcaopag_nome' => 'Dinheiro', 'opcaopag_tipo_taxa' => 'N/A', 'opcaopag_valor_percentual_taxa' => 0]);
        $cartao = OpcoesPagamento::create(['opcaopag_nome' => 'Cartão', 'opcaopag_tipo_taxa' => 'N/A', 'opcaopag_valor_percentual_taxa' => 0]);
        $this->pedidoLancadoNestaVenda('Dinheiro');

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->set('opcaoPagamentoSelecionadaId', $cartao->id)
            ->call('abrirModalPagamento')
            ->assertSet('opcaoPagamentoSelecionadaId', $cartao->id);
    }
}
