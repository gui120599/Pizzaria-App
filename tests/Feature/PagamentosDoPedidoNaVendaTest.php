<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Enums\StatusLancamento;
use App\Enums\TipoLancamento;
use App\Filament\Pages\AtenderPedido;
use App\Filament\Pages\OperarVenda;
use App\Livewire\EntregaPagamentoPicker;
use App\Models\Caixa;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\ItensPedido;
use App\Models\Lancamento;
use App\Models\OpcoesPagamento;
use App\Models\PagamentosPedido;
use App\Models\PagamentosVenda;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\SessaoCaixa;
use App\Models\User;
use App\Models\Venda;
use App\Services\VendaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Formas de pagamento combinadas no pedido viram pagamentos da venda no Nova
 * Venda, e o atendente marca no pedido a forma que já conferiu (PIX CNPJ).
 */
class PagamentosDoPedidoNaVendaTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Produto $produto;

    private Venda $venda;

    private OpcoesPagamento $pixCnpj;

    private OpcoesPagamento $dinheiro;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->admin()->create(['name_first' => 'Ana', 'name' => 'Ana']);
        $this->actingAs($this->user);

        $sessaoCaixa = SessaoCaixa::create([
            'sessaocaixa_caixa_id' => Caixa::create(['caixa_nome' => 'Caixa 1'])->id,
            'sessaocaixa_status' => 'ABERTA',
            'sessaocaixa_data_hora_abertura' => now(),
            'sessaocaixa_saldo_inicial' => 0,
            'sessaocaixa_user_id' => $this->user->id,
        ]);

        $this->produto = Produto::create([
            'produto_descricao' => 'Calabresa',
            'produto_categoria_id' => Categoria::create(['categoria_nome' => 'Pizzas'])->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 40.00,
        ]);

        $this->venda = Venda::create(['venda_status' => 'INICIADA', 'venda_sessao_caixa_id' => $sessaoCaixa->id]);

        $this->pixCnpj = OpcoesPagamento::create([
            'opcaopag_nome' => 'PIX CNPJ', 'opcaopag_desc_nfe' => 'InstantPayment',
            'opcaopag_pix_cnpj' => true, 'opcaopag_confirmavel_no_pedido' => true,
        ]);
        $this->dinheiro = OpcoesPagamento::create(['opcaopag_nome' => 'Dinheiro', 'opcaopag_desc_nfe' => 'cash']);
    }

    /**
     * Pedido avulso com uma linha de R$ 40 por unidade e as formas combinadas.
     *
     * @param  array<int, array{0: OpcoesPagamento, 1: float, 2?: ?float, 3?: bool}>  $formas  [forma, valor, troco para, pago]
     */
    private function pedido(array $formas, int $quantidade = 2): Pedido
    {
        $pedido = Pedido::create(['pedido_status' => 'ENTREGUE', 'pedido_valor_total' => 40 * $quantidade]);

        for ($i = 0; $i < $quantidade; $i++) {
            ItensPedido::create([
                'item_pedido_pedido_id' => $pedido->id,
                'item_pedido_produto_id' => $this->produto->id,
                'item_pedido_quantidade' => 1,
                'item_pedido_valor_unitario' => 40,
                'item_pedido_desconto' => 0,
                'item_pedido_valor_adicionais' => 0,
                'item_pedido_valor' => 40,
                'item_pedido_status' => 'INSERIDO',
            ]);
        }

        foreach ($formas as $ordem => $forma) {
            PagamentosPedido::create([
                'pg_pedido_pedido_id' => $pedido->id,
                'pg_pedido_opcaopagamento_id' => $forma[0]->id,
                'pg_pedido_opcaopagamento_nome' => $forma[0]->opcaopag_nome,
                'pg_pedido_valor' => $forma[1],
                'pg_pedido_valor_troco_para' => $forma[2] ?? null,
                'pg_pedido_pago' => $forma[3] ?? false,
                'pg_pedido_ordem' => $ordem,
            ]);
        }

        return $pedido;
    }

    private function pdv(): Testable
    {
        return Livewire::test(OperarVenda::class, ['venda' => $this->venda]);
    }

    public function test_lancar_o_pedido_inteiro_cria_os_pagamentos_combinados(): void
    {
        $pedido = $this->pedido([[$this->pixCnpj, 50, null, true], [$this->dinheiro, 30, 50]]);

        $this->pdv()
            ->call('lancarPedidoAvulso', $pedido->id)
            ->assertSee('pago no pedido')
            ->assertSee('pedido #'.$pedido->id);

        $pagamentos = PagamentosVenda::where('pg_venda_venda_id', $this->venda->id)->orderBy('id')->get();
        $this->assertSame(
            [[$this->pixCnpj->id, '50.00', '0.00'], [$this->dinheiro->id, '30.00', '20.00']],
            $pagamentos->map(fn (PagamentosVenda $p): array => [$p->pg_venda_opcaopagamento_id, $p->pg_venda_valor_pagamento, $p->pg_venda_valor_troco])->all(),
        );

        $venda = $this->venda->fresh();
        $this->assertEqualsWithDelta(80.0, (float) $venda->venda_valor_pago, 0.001);
        $this->assertEqualsWithDelta(20.0, (float) $venda->venda_valor_troco, 0.001);
    }

    public function test_relancar_nao_duplica_e_retirar_o_pedido_desfaz_os_pagamentos(): void
    {
        $pedido = $this->pedido([[$this->pixCnpj, 80, null, true]]);
        $pdv = $this->pdv()->call('lancarPedidoAvulso', $pedido->id);

        $pdv->call('lancarPedidoAvulso', $pedido->id);
        $this->assertSame(1, PagamentosVenda::where('pg_venda_venda_id', $this->venda->id)->count());

        $pdv->call('removerPedidoAvulso', $pedido->id);
        $this->assertSame(0, PagamentosVenda::where('pg_venda_venda_id', $this->venda->id)->count());
        $this->assertEqualsWithDelta(0.0, (float) $this->venda->fresh()->venda_valor_pago, 0.001);

        // Voltar com o pedido recria.
        $pdv->call('lancarPedidoAvulso', $pedido->id);
        $this->assertSame(1, PagamentosVenda::where('pg_venda_venda_id', $this->venda->id)->count());
    }

    public function test_lancamento_parcial_do_pedido_nao_cria_pagamentos_ate_ficar_inteiro(): void
    {
        $pedido = $this->pedido([[$this->pixCnpj, 80, null, true]]);
        [$primeiro, $segundo] = $pedido->item_pedido_pedido_id()->orderBy('id')->pluck('id')->all();

        $pdv = $this->pdv()->call('alternarItemPedido', $primeiro);
        $this->assertSame(0, PagamentosVenda::where('pg_venda_venda_id', $this->venda->id)->count());

        $pdv->call('alternarItemPedido', $segundo);
        $this->assertSame(1, PagamentosVenda::where('pg_venda_venda_id', $this->venda->id)->count());

        // Tirar um item quebra o pedido: o pagamento sai junto.
        $pdv->call('alternarItemPedido', $segundo);
        $this->assertSame(0, PagamentosVenda::where('pg_venda_venda_id', $this->venda->id)->count());
    }

    public function test_forma_stone_integrada_fica_para_a_maquininha_e_valor_e_limitado_ao_restante(): void
    {
        $stone = OpcoesPagamento::create(['opcaopag_nome' => 'Cartão Stone', 'opcaopag_desc_nfe' => 'creditCard', 'opcaopag_stone_integrada' => true]);
        $pedido = $this->pedido([[$stone, 30], [$this->pixCnpj, 100]]);

        $this->pdv()->call('lancarPedidoAvulso', $pedido->id)->assertNotified('Cobrar na maquininha');

        $pagamentos = PagamentosVenda::where('pg_venda_venda_id', $this->venda->id)->get();
        $this->assertSame([$this->pixCnpj->id], $pagamentos->pluck('pg_venda_opcaopagamento_id')->all());
        $this->assertSame('80.00', $pagamentos->first()->pg_venda_valor_pagamento);
    }

    public function test_forma_com_acrescimo_aplica_a_taxa_da_forma(): void
    {
        $credito = OpcoesPagamento::create([
            'opcaopag_nome' => 'Crédito', 'opcaopag_desc_nfe' => 'creditCard',
            'opcaopag_tipo_taxa' => 'ACRESCENTAR', 'opcaopag_valor_percentual_taxa' => 5,
        ]);
        $pedido = $this->pedido([[$credito, 80]]);

        $this->pdv()->call('lancarPedidoAvulso', $pedido->id);

        $venda = $this->venda->fresh();
        $this->assertSame('4.00', PagamentosVenda::where('pg_venda_venda_id', $venda->id)->sole()->pg_venda_valor_acrescimo);
        $this->assertEqualsWithDelta(84.0, (float) $venda->venda_valor_total, 0.001);
    }

    public function test_atendente_marca_pagamento_ja_efetuado_so_em_forma_confirmavel(): void
    {
        $pedido = $this->pedido([], quantidade: 1);
        $pedido->update(['pedido_status' => 'ABERTO']);

        $salvar = fn (array $linhas) => Livewire::test(AtenderPedido::class, ['pedido' => $pedido])
            ->set('entregaPagamentoData', ['opcaoEntregaId' => null, 'pagamentos' => $linhas])
            ->call('save')
            ->assertHasNoErrors();

        $salvar([
            ['opcaoPagamentoId' => $this->pixCnpj->id, 'valor' => '30,00', 'trocoPara' => null, 'pago' => true],
            ['opcaoPagamentoId' => $this->dinheiro->id, 'valor' => '10,00', 'trocoPara' => null, 'pago' => true],
        ]);

        [$pix, $dinheiro] = $pedido->pagamentosCombinados()->orderBy('pg_pedido_ordem')->get()->all();
        $this->assertTrue($pix->pg_pedido_pago);
        $this->assertSame($this->user->id, $pix->pg_pedido_pago_usuario_id);
        $this->assertNotNull($pix->pg_pedido_pago_em);
        $this->assertFalse($dinheiro->pg_pedido_pago, 'Dinheiro não pode ser confirmado no pedido.');

        // Outro usuário reabre e salva: a confirmação e o id da linha ficam.
        $this->travel(1)->hours();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Bia']));
        $salvar([
            ['opcaoPagamentoId' => $this->pixCnpj->id, 'valor' => '30,00', 'trocoPara' => null, 'pago' => true],
            ['opcaoPagamentoId' => $this->dinheiro->id, 'valor' => '10,00', 'trocoPara' => null, 'pago' => false],
        ]);

        $depois = $pix->fresh();
        $this->assertNotNull($depois, 'A linha é atualizada, não recriada.');
        $this->assertSame($this->user->id, $depois->pg_pedido_pago_usuario_id);
        $this->assertTrue($depois->pg_pedido_pago_em->equalTo($pix->pg_pedido_pago_em));
    }

    public function test_picker_so_oferece_ja_pago_em_forma_confirmavel_e_desmarca_ao_trocar(): void
    {
        $picker = Livewire::test(EntregaPagamentoPicker::class, ['total' => 40])
            ->set('pagamentos.0.opcaoPagamentoId', $this->dinheiro->id)
            ->assertDontSee('Pagamento já efetuado')
            ->set('pagamentos.0.opcaoPagamentoId', $this->pixCnpj->id)
            ->assertSee('Pagamento já efetuado')
            ->set('pagamentos.0.pago', true)
            ->set('pagamentos.0.opcaoPagamentoId', $this->dinheiro->id);

        $this->assertFalse($picker->get('pagamentos')[0]['pago']);
    }

    public function test_comanda_mostra_a_forma_paga(): void
    {
        $pedido = $this->pedido([[$this->pixCnpj, 50, null, true], [$this->dinheiro, 30]]);

        $this->get(route('pedido.imprimir', ['id' => $pedido->id]))
            ->assertOk()
            ->assertSee('PIX CNPJ (PAGO)')
            ->assertSee('PAGO — não cobrar', false);
    }

    private function comClienteFiado(): void
    {
        $cliente = Cliente::create(['cliente_nome' => 'João', 'cliente_tipo' => 'PF', 'cliente_limite_credito' => 500]);
        $this->venda->update(['venda_cliente_id' => $cliente->id]);
    }

    private function pagamentosDaVenda(): int
    {
        return PagamentosVenda::where('pg_venda_venda_id', $this->venda->id)->count();
    }

    public function test_marcar_fiado_tira_os_pagamentos_do_pedido_e_desmarcar_traz_de_volta(): void
    {
        $this->comClienteFiado();
        $pedido = $this->pedido([[$this->pixCnpj, 50], [$this->dinheiro, 30, 50]]);

        $pdv = $this->pdv()->call('lancarPedidoAvulso', $pedido->id);
        $this->assertSame(2, $this->pagamentosDaVenda());

        $pdv->set('vendaFiado', true);
        $this->assertSame(0, $this->pagamentosDaVenda());
        $this->assertSame(2, PagamentosPedido::where('pg_pedido_pedido_id', $pedido->id)->count());
        $venda = $this->venda->fresh();
        $this->assertEqualsWithDelta(0.0, (float) $venda->venda_valor_pago, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $venda->venda_valor_troco, 0.001);

        $pdv->set('vendaFiado', false);
        $this->assertSame(2, $this->pagamentosDaVenda());
        $this->assertEqualsWithDelta(80.0, (float) $this->venda->fresh()->venda_valor_pago, 0.001);

        $pdv->set('vendaFiado', true)->set('vendaFiado', false);
        $this->assertSame(2, $this->pagamentosDaVenda());
    }

    public function test_fiado_parcial_mantem_so_o_confirmado_e_o_titulo_cobra_o_saldo(): void
    {
        $this->comClienteFiado();
        $pedido = $this->pedido([[$this->pixCnpj, 50, null, true], [$this->dinheiro, 30]]);

        $this->pdv()
            ->call('lancarPedidoAvulso', $pedido->id)
            ->set('vendaFiado', true)
            ->call('finalizarVenda')
            ->assertHasNoErrors();

        $pagamentos = PagamentosVenda::where('pg_venda_venda_id', $this->venda->id)->get();
        $this->assertSame([$this->pixCnpj->id], $pagamentos->pluck('pg_venda_opcaopagamento_id')->all());

        $venda = $this->venda->fresh();
        $this->assertSame('FINALIZADA', $venda->venda_status);
        $this->assertEqualsWithDelta(50.0, (float) $venda->venda_valor_pago, 0.001);
        $this->assertEqualsWithDelta(30.0, (float) Lancamento::where('venda_id', $venda->id)->value('valor'), 0.001);
    }

    public function test_pedido_selecionado_com_fiado_ja_marcado_so_lanca_o_confirmado(): void
    {
        $this->comClienteFiado();
        $pedido = $this->pedido([[$this->pixCnpj, 50, null, true], [$this->dinheiro, 30]]);

        $this->pdv()->set('vendaFiado', true)->call('lancarPedidoAvulso', $pedido->id);

        $this->assertSame([$this->pixCnpj->id], PagamentosVenda::where('pg_venda_venda_id', $this->venda->id)->pluck('pg_venda_opcaopagamento_id')->all());
    }

    public function test_pagamento_manual_do_caixa_sobrevive_ao_liga_desliga_do_fiado(): void
    {
        $this->comClienteFiado();
        $pedido = $this->pedido([[$this->dinheiro, 30]]);

        $pdv = $this->pdv()->call('lancarPedidoAvulso', $pedido->id);
        $manual = PagamentosVenda::create([
            'pg_venda_venda_id' => $this->venda->id,
            'pg_venda_opcaopagamento_id' => $this->dinheiro->id,
            'pg_venda_valor_pagamento' => 10,
            'pg_venda_valor_recebido' => 10,
            'pg_venda_valor_pago_pelo_cliente' => 10,
            'pg_venda_valor_troco' => 0,
            'pg_venda_valor_acrescimo' => 0,
            'pg_venda_valor_desconto' => 0,
        ]);
        app(VendaService::class)->atualizarValoresdaVenda($this->venda->id);

        $pdv->set('vendaFiado', true);

        $this->assertSame([$manual->id], PagamentosVenda::where('pg_venda_venda_id', $this->venda->id)->pluck('id')->all());
        $this->assertEqualsWithDelta(10.0, (float) $this->venda->fresh()->venda_valor_pago, 0.001);
    }

    public function test_finalizar_fiado_com_pagamento_do_pedido_dessincronizado_nao_deixa_pagamento_fantasma(): void
    {
        $this->comClienteFiado();
        $pedido = $this->pedido([[$this->dinheiro, 80]]);

        $pdv = $this->pdv()->set('vendaFiado', true);
        // Estado dessincronizado: o pagamento do pedido entra na venda com o fiado já marcado.
        $pdv->set('vendaFiado', false)->call('lancarPedidoAvulso', $pedido->id);
        $this->assertSame(1, $this->pagamentosDaVenda());

        $pdv->set('vendaFiado', true)->call('finalizarVenda')->assertHasNoErrors();

        $this->assertSame(0, $this->pagamentosDaVenda());
        $this->assertEqualsWithDelta(80.0, (float) Lancamento::where('venda_id', $this->venda->id)->value('valor'), 0.001);
    }

    public function test_comando_de_diagnostico_lista_fiado_com_pagamento_de_pedido_sem_alterar_nada(): void
    {
        $this->comClienteFiado();
        $pedido = $this->pedido([[$this->dinheiro, 30]]);
        $pdv = $this->pdv()->call('lancarPedidoAvulso', $pedido->id);

        // Fluxo antigo: título fiado criado com o pagamento do pedido ainda na venda.
        Lancamento::create([
            'tipo' => TipoLancamento::Receber, 'venda_id' => $this->venda->id,
            'cliente_id' => $this->venda->venda_cliente_id, 'descricao' => 'fiado', 'valor' => 50,
            'vencimento' => now()->addWeek(), 'status' => StatusLancamento::Pendente,
        ]);

        $this->artisan('vendas:diagnosticar-fiado-pagamentos')
            ->expectsOutputToContain('30.00')
            ->assertSuccessful();

        $this->assertSame(1, $this->pagamentosDaVenda());
    }
}
