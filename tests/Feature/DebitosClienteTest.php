<?php

namespace Tests\Feature;

use App\Enums\FormaPagamento;
use App\Enums\ProdutoTipoEnum;
use App\Enums\StatusLancamento;
use App\Enums\TipoLancamento;
use App\Filament\Pages\OperarVenda;
use App\Filament\Pages\RelatorioDebitosClientes;
use App\Filament\Resources\Clientes\Pages\EditCliente;
use App\Filament\Resources\Clientes\Widgets\DebitosClienteWidget;
use App\Models\Caixa;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\ItensPedido;
use App\Models\Lancamento;
use App\Models\Mesa;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\SessaoCaixa;
use App\Models\SessaoMesa;
use App\Models\User;
use App\Models\Venda;
use App\Services\DebitosClienteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DebitosClienteTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private SessaoCaixa $sessaoCaixa;

    private Produto $produto;

    private Cliente $ana;

    private Cliente $bruno;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->admin()->create(['name_first' => 'Admin']);
        $this->actingAs($this->user);

        $this->sessaoCaixa = SessaoCaixa::create([
            'sessaocaixa_caixa_id' => Caixa::create(['caixa_nome' => 'Caixa 1'])->id,
            'sessaocaixa_status' => 'ABERTA',
            'sessaocaixa_data_hora_abertura' => now(),
            'sessaocaixa_saldo_inicial' => 0,
            'sessaocaixa_user_id' => $this->user->id,
        ]);

        $this->produto = Produto::create([
            'produto_descricao' => 'Cerveja',
            'produto_categoria_id' => Categoria::create(['categoria_nome' => 'Bebidas'])->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 10.00,
        ]);

        $this->ana = Cliente::create(['cliente_nome' => 'Ana', 'cliente_tipo' => 'PF', 'cliente_limite_credito' => 500]);
        $this->bruno = Cliente::create(['cliente_nome' => 'Bruno', 'cliente_tipo' => 'PF', 'cliente_limite_credito' => 500]);
    }

    /**
     * Venda fiado com um item de R$ 10 por unidade em cada pedido informado
     * (quantidade = valor/10) mais taxa de serviço, e o título do saldo.
     *
     * @param  array<int, float>  $valoresDosPedidos
     * @return array{venda: Venda, lancamento: Lancamento, pedidos: array<int, Pedido>}
     */
    private function vendaFiado(Cliente $cliente, array $valoresDosPedidos, float $taxa = 0, float $pagoNoAto = 0, ?string $vencimento = null): array
    {
        $total = array_sum($valoresDosPedidos) + $taxa;
        $venda = Venda::create([
            'venda_status' => 'FINALIZADA',
            'venda_sessao_caixa_id' => $this->sessaoCaixa->id,
            'venda_cliente_id' => $cliente->id,
            'venda_valor_taxa_servico' => $taxa,
            'venda_valor_total' => $total,
            'venda_valor_pago' => $pagoNoAto,
            'venda_valor_troco' => 0,
        ]);

        $pedidos = [];
        foreach ($valoresDosPedidos as $valor) {
            $pedido = Pedido::create(['pedido_status' => 'FINALIZADO', 'pedido_cliente_id' => $cliente->id, 'pedido_venda_id' => $venda->id]);
            ItensPedido::create([
                'item_pedido_pedido_id' => $pedido->id,
                'item_pedido_produto_id' => $this->produto->id,
                'item_pedido_quantidade' => $valor / 10,
                'item_pedido_valor_unitario' => 10.00,
                'item_pedido_desconto' => 0,
                'item_pedido_valor_adicionais' => 0,
                'item_pedido_valor' => $valor,
                'item_pedido_status' => 'INSERIDO',
                'item_pedido_venda_id' => $venda->id,
            ]);
            $pedidos[] = $pedido;
        }

        $lancamento = Lancamento::create([
            'tipo' => TipoLancamento::Receber,
            'venda_id' => $venda->id,
            'cliente_id' => $cliente->id,
            'descricao' => "Venda #{$venda->id} - saldo fiado",
            'valor' => $total - $pagoNoAto,
            'vencimento' => $vencimento ?? now()->addDays(7)->toDateString(),
            'status' => StatusLancamento::Pendente,
        ]);

        return ['venda' => $venda, 'lancamento' => $lancamento, 'pedidos' => $pedidos];
    }

    public function test_titulo_e_aberto_por_pedido_com_o_saldo_rateado_pelo_valor_de_cada_um(): void
    {
        // Venda de R$ 110 (pedidos de 60 e 40 + taxa de 10), R$ 33 pagos no ato: título de R$ 77.
        ['lancamento' => $lancamento, 'pedidos' => [$primeiro, $segundo]] = $this->vendaFiado($this->ana, [60, 40], taxa: 10, pagoNoAto: 33);

        $titulo = (new DebitosClienteService)->porCliente()->sole()['titulos']->sole();

        $this->assertSame($lancamento->id, $titulo['lancamento']->id);
        $this->assertSame(77.0, $titulo['restante']);
        $this->assertSame(
            [[$primeiro->id, 60.0, 42.0], [$segundo->id, 40.0, 28.0]],
            $titulo['pedidos']->map(fn (array $linha): array => [$linha['pedido']->id, $linha['valor'], $linha['em_aberto']])->all(),
        );
        $this->assertSame(['descricao' => 'Taxa de serviço', 'valor' => 10.0, 'em_aberto' => 7.0], $titulo['ajuste']);
    }

    public function test_recebimento_parcial_reduz_o_em_aberto_e_as_linhas_somam_o_saldo_exato(): void
    {
        ['lancamento' => $lancamento] = $this->vendaFiado($this->ana, [10, 10, 10]);
        $lancamento->registrarPagamento(20, forma: FormaPagamento::Dinheiro);

        $titulo = (new DebitosClienteService)->porCliente()->sole()['titulos']->sole();

        $this->assertSame(10.0, $titulo['restante']);
        $this->assertSame(20.0, $titulo['recebido']);
        $this->assertNull($titulo['ajuste']);
        $this->assertEqualsWithDelta(10.0, $titulo['pedidos']->sum('em_aberto'), 0.001);
        $this->assertSame([3.33, 3.33, 3.34], $titulo['pedidos']->pluck('em_aberto')->sort()->values()->all());
    }

    public function test_agrupa_por_cliente_do_maior_devedor_e_ignora_titulos_quitados(): void
    {
        $this->vendaFiado($this->ana, [30]);
        $this->vendaFiado($this->bruno, [50], vencimento: now()->subDay()->toDateString());
        $this->vendaFiado($this->bruno, [20]);
        $this->vendaFiado($this->ana, [99])['lancamento']->registrarPagamento(99, forma: FormaPagamento::Dinheiro);

        $service = new DebitosClienteService;
        $porCliente = $service->porCliente();

        $this->assertSame(
            [['Bruno', 70.0, 50.0, 2], ['Ana', 30.0, 0.0, 1]],
            $porCliente->map(fn (array $d): array => [$d['cliente']->cliente_nome, $d['total'], $d['vencido'], $d['titulos']->count()])->all(),
        );
        $this->assertSame(['clientes' => 2, 'titulos' => 3, 'total' => 100.0, 'vencido' => 50.0], $service->totais());
        $this->assertSame($this->bruno->saldoDevedor(), $porCliente->first()['total']);
    }

    public function test_titulo_lancado_no_financeiro_entra_no_relatorio_mas_nao_no_pdv(): void
    {
        $this->vendaFiado($this->ana, [30]);
        Lancamento::create([
            'tipo' => TipoLancamento::Receber,
            'cliente_id' => $this->ana->id,
            'descricao' => 'Empréstimo de caixa',
            'valor' => 15,
            'vencimento' => now()->addDays(7),
            'status' => StatusLancamento::Pendente,
        ]);

        $completo = (new DebitosClienteService)->porCliente()->sole();
        $this->assertSame(45.0, $completo['total']);
        $this->assertSame(
            ['descricao' => 'Empréstimo de caixa', 'valor' => 15.0, 'em_aberto' => 15.0],
            $completo['titulos']->firstWhere('venda', null)['ajuste'],
        );

        $this->assertSame(30.0, (new DebitosClienteService(['somente_vendas' => true]))->porCliente()->sole()['total']);
    }

    public function test_busca_pelo_numero_do_pedido_e_filtro_de_vencidos(): void
    {
        ['pedidos' => [$pedidoDaAna]] = $this->vendaFiado($this->ana, [30]);
        $this->vendaFiado($this->bruno, [50], vencimento: now()->subDay()->toDateString());

        $porPedido = (new DebitosClienteService(['busca' => (string) $pedidoDaAna->id]))->porCliente();
        $this->assertSame([$this->ana->id], $porPedido->pluck('cliente.id')->all());

        $vencidos = (new DebitosClienteService(['somente_vencidos' => true]))->porCliente();
        $this->assertSame([$this->bruno->id], $vencidos->pluck('cliente.id')->all());
    }

    public function test_pedido_de_mesa_mostra_a_mesa_e_so_os_itens_cobrados_nesta_venda(): void
    {
        $mesa = Mesa::create(['mesa_nome' => 'Mesa 7', 'mesa_status' => 'OCUPADA']);
        $sessao = SessaoMesa::create(['sessao_mesa_mesa_id' => $mesa->id, 'sessao_mesa_usuario_id' => $this->user->id, 'sessao_mesa_status' => 'ABERTA']);
        ['venda' => $venda, 'pedidos' => [$pedido]] = $this->vendaFiado($this->ana, [20]);
        $pedido->update(['pedido_sessao_mesa_id' => $sessao->id]);

        // Item do mesmo pedido cobrado em outra venda (conta dividida) não entra neste título.
        ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id, 'item_pedido_produto_id' => $this->produto->id,
            'item_pedido_quantidade' => 1, 'item_pedido_valor_unitario' => 10, 'item_pedido_desconto' => 0,
            'item_pedido_valor_adicionais' => 0, 'item_pedido_valor' => 10, 'item_pedido_status' => 'INSERIDO',
            'item_pedido_venda_id' => Venda::create(['venda_status' => 'FINALIZADA', 'venda_sessao_caixa_id' => $this->sessaoCaixa->id])->id,
        ]);

        $linha = (new DebitosClienteService)->porCliente()->sole()['titulos']->sole()['pedidos']->sole();

        $this->assertSame(20.0, $linha['valor']);
        $this->assertCount(1, $linha['itens']);

        Livewire::test(RelatorioDebitosClientes::class)
            ->assertOk()
            ->assertSee('Ana')
            ->assertSee('Venda #'.$venda->id)
            ->assertSee('Pedido #'.$pedido->id)
            ->assertSee('Mesa 7');
    }

    public function test_relatorio_filtra_pela_busca_e_imprime(): void
    {
        $this->vendaFiado($this->ana, [30]);
        $this->vendaFiado($this->bruno, [50]);

        Livewire::test(RelatorioDebitosClientes::class)
            ->set('busca', 'Bruno')
            ->assertSee('Bruno')
            ->assertDontSee('Ana')
            ->assertDontSee('S/M'); // pedido sem mesa não herda o withDefault de sessaoMesa

        $this->get(route('relatorios.debitos_clientes.imprimir', ['filters' => ['cliente_id' => $this->ana->id]]))
            ->assertOk()
            ->assertSee('Extrato de débitos')
            ->assertSee('Ana')
            ->assertDontSee('Bruno');
    }

    public function test_impressao_e_relatorio_exigem_acesso_a_clientes_ou_ao_pdv(): void
    {
        $this->actingAs(User::factory()->create(['name_first' => 'Sem acesso']));

        $this->get(route('relatorios.debitos_clientes.imprimir'))->assertForbidden();
        $this->assertFalse(RelatorioDebitosClientes::canAccess());
    }

    public function test_agrupa_os_titulos_pelo_mes_da_compra_com_subtotal(): void
    {
        $this->travelTo(now()->setDate(2026, 8, 20));
        $this->vendaFiado($this->ana, [30]);
        $this->travelTo(now()->setDate(2026, 9, 5));
        $this->vendaFiado($this->ana, [50]);
        $this->vendaFiado($this->ana, [20]);
        $this->travelBack();

        $meses = DebitosClienteService::agruparPorMes((new DebitosClienteService)->porCliente()->sole()['titulos']);

        $this->assertSame(
            [['Agosto de 2026', 30.0, 1], ['Setembro de 2026', 70.0, 2]],
            $meses->map(fn (array $mes): array => [$mes['mes'], $mes['total'], $mes['titulos']->count()])->values()->all(),
        );

        Livewire::test(RelatorioDebitosClientes::class)
            ->assertDontSee('Agosto de 2026')
            ->set('agruparPorMes', true)
            ->assertSee('Agosto de 2026')
            ->assertSee('Setembro de 2026');

        $this->get(route('relatorios.debitos_clientes.imprimir', ['filters' => ['agrupar_mes' => 1]]))
            ->assertOk()
            ->assertSee('agrupado por mês')
            ->assertSee('Setembro de 2026');
    }

    public function test_edicao_do_cliente_mostra_os_debitos_dele(): void
    {
        ['pedidos' => [$pedido]] = $this->vendaFiado($this->ana, [30]);
        $this->vendaFiado($this->bruno, [50]);

        Livewire::test(DebitosClienteWidget::class, ['record' => $this->ana])
            ->assertSee('Débitos em aberto')
            ->assertSee('Pedido #'.$pedido->id)
            ->assertSee('R$ 30,00')
            ->assertDontSee('Bruno');

        Livewire::test(DebitosClienteWidget::class, ['record' => Cliente::create(['cliente_nome' => 'Sem dívida', 'cliente_tipo' => 'PF'])])
            ->assertDontSee('Débitos em aberto');

        Livewire::test(EditCliente::class, ['record' => $this->ana->id])->assertOk();
    }

    public function test_pdv_avisa_o_debito_do_cliente_da_venda_e_leva_a_aba_pendentes(): void
    {
        $this->vendaFiado($this->ana, [30]);
        $venda = Venda::create(['venda_status' => 'INICIADA', 'venda_sessao_caixa_id' => $this->sessaoCaixa->id, 'venda_cliente_id' => $this->ana->id]);

        Livewire::test(OperarVenda::class, ['venda' => $venda])
            ->assertSee('Deve R$ 30,00')
            ->call('verDebitosDoCliente')
            ->assertSet('abaAtiva', 'pendentes')
            ->assertSet('buscaPendentes', 'Ana')
            ->assertSee('Receber');
    }
}
